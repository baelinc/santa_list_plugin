#!/usr/bin/env python3
"""
Santa's Naughty & Nice List — FPP Plugin  (v2 — Pixel Overlay Model edition)
=============================================================================
Architecture
------------
  WordPress REST API
       │  (HTTP poll every N seconds)
       ▼
  WordPressPoller thread  ──► name lists (nice / naughty)
       │
       ▼
  DisplayEngine  (main loop)
       │
       ├─► OverlayModel("SantaHeader")  ──► FPP shared memory ──► FPP Channel Output ──► ColorLight ──► Panel
       └─► OverlayModel("SantaNames")   ──► FPP shared memory ──► FPP Channel Output ──► ColorLight ──► Panels

The plugin NEVER talks to the ColorLight card directly.
FPP owns the hardware; we just paint pixels into FPP's shared memory buffers.

Pixel Overlay Model shared memory layout (FPP source: src/channeloutput/PixelOverlayModel.cpp):
  byte  0      : lock byte  (0 = we can write, 1 = FPP is reading — spin-wait)
  byte  1      : data-ready flag (write 1 after updating pixels to signal FPP)
  bytes 2+     : raw pixel data  R,G,B, R,G,B, ...  row-major, top-to-bottom

Configuration is stored in:
  /home/fpp/media/config/santa_list_plugin.json
"""

import colorsys
import json
import logging
import mmap
import os
import struct
import sys
import threading
import time
from pathlib import Path
from typing import Optional

import requests

# ── optional rpi_ws281x ───────────────────────────────────────────────────────
try:
    from rpi_ws281x import Color, PixelStrip
    HAS_WS281X = True
except ImportError:
    HAS_WS281X = False
    logging.warning("rpi_ws281x not found — pixel strip running in simulation mode")

# ── optional posix_ipc (used for shared memory on older FPP builds) ───────────
try:
    import posix_ipc
    HAS_POSIX_IPC = True
except ImportError:
    HAS_POSIX_IPC = False

# ─────────────────────────────────────────────────────────────────────────────
# Configuration
# ─────────────────────────────────────────────────────────────────────────────
CONFIG_PATH = Path("/home/fpp/media/config/santa_list_plugin.json")

DEFAULT_CONFIG = {
    # WordPress connection
    "api_url":               "http://your-site.com/wp-json/sld/v1/names",
    "api_token":             "",
    "poll_interval_seconds": 60,

    # FPP Pixel Overlay Model names (set in FPP → Content Setup → Pixel Overlay Models)
    "header_model_name": "SantaHeader",   # single panel: must be 32 wide × 64 tall
    "names_model_name":  "SantaNames",    # name canvas:  must be 96 wide × 128 tall

    # Expected model dimensions — used for validation on startup
    "header_model_width":  32,
    "header_model_height": 64,
    "names_model_width":   96,
    "names_model_height":  128,

    # Display timing
    "list_display_seconds": 30,   # seconds per list before switching

    # Pixel outline (PiHat)
    "pixel_pin":        18,
    "pixel_count":      100,
    "pixel_brightness": 200,
    "marquee_speed_ms": 50,

    # Colors (hex)
    "nice_color":    "#ffffff",
    "naughty_color": "#ff0000",

    # Text
    "font_scale":      2,
    "scroll_speed_ms": 40,
}


def load_config() -> dict:
    if CONFIG_PATH.exists():
        try:
            with open(CONFIG_PATH) as f:
                cfg = json.load(f)
            for k, v in DEFAULT_CONFIG.items():
                cfg.setdefault(k, v)
            return cfg
        except Exception as e:
            logging.error("Config load error: %s", e)
    return dict(DEFAULT_CONFIG)


def save_config(cfg: dict):
    CONFIG_PATH.parent.mkdir(parents=True, exist_ok=True)
    with open(CONFIG_PATH, "w") as f:
        json.dump(cfg, f, indent=2)


# ─────────────────────────────────────────────────────────────────────────────
# FPP REST helpers
# ─────────────────────────────────────────────────────────────────────────────
FPP_API = "http://localhost"


def fpp_get_models() -> list[dict]:
    """
    Query FPP's local API for all defined Pixel Overlay Models.
    Returns a list of dicts, each containing at minimum:
      name, width, height, startChannel
    """
    try:
        r = requests.get(f"{FPP_API}/api/models", timeout=3)
        r.raise_for_status()
        return r.json()
    except Exception as e:
        logging.error("Could not fetch FPP model list: %s", e)
        return []


def fpp_enable_model(model_name: str) -> bool:
    """Enable (activate) a Pixel Overlay Model so FPP will push it to hardware."""
    try:
        r = requests.get(
            f"{FPP_API}/api/models/{model_name}/enable",
            timeout=3,
        )
        return r.status_code == 200
    except Exception as e:
        logging.error("Could not enable model '%s': %s", model_name, e)
        return False


def fpp_set_model_state(model_name: str, state: str = "Normal") -> bool:
    """
    Set the display state of a model.
    state: "Normal" | "Disabled" | "Transparent" | "TransparentRGB"
    """
    try:
        r = requests.get(
            f"{FPP_API}/api/models/{model_name}/state/{state}",
            timeout=3,
        )
        return r.status_code == 200
    except Exception as e:
        logging.error("Could not set model state '%s': %s", model_name, e)
        return False


# ─────────────────────────────────────────────────────────────────────────────
# Pixel Overlay Model — shared memory wrapper
# ─────────────────────────────────────────────────────────────────────────────
class OverlayModel:
    """
    Wraps a single FPP Pixel Overlay Model shared memory segment.

    FPP names its shared memory blocks:  /FPPOverlay_{model_name}
    The layout is:
        offset 0   : uint8  lock byte  — 1 means FPP is reading, don't write
        offset 1   : uint8  data flag  — set to 1 after writing to wake FPP
        offset 2.. : R,G,B bytes       — width*height*3 bytes, row-major

    We use Python's built-in mmap via /dev/shm (which is where POSIX shared
    memory lives on Linux) so we don't need any third-party library.
    """

    SHM_PREFIX = "/dev/shm/FPPOverlay_"

    def __init__(self, model_name: str, width: int, height: int):
        self.name   = model_name
        self.width  = width
        self.height = height
        self._mm: Optional[mmap.mmap] = None
        self._fd: Optional[int]       = None
        self._size  = 2 + width * height * 3   # header bytes + pixel bytes

    def open(self) -> bool:
        """
        Open (or create) the shared memory segment.
        Returns True on success.
        """
        shm_path = self.SHM_PREFIX + self.name
        try:
            # FPP should have already created the file when the model was
            # defined and enabled.  If it hasn't yet, we create it ourselves
            # so we can start writing — FPP will pick it up when it starts.
            existed = os.path.exists(shm_path)
            flags   = os.O_RDWR | (0 if existed else os.O_CREAT)
            self._fd = os.open(shm_path, flags, 0o666)

            if not existed:
                # Pre-allocate the file to the right size
                os.write(self._fd, b"\x00" * self._size)
                os.lseek(self._fd, 0, os.SEEK_SET)

            self._mm = mmap.mmap(self._fd, self._size)
            logging.info(
                "Opened overlay model '%s' (%dx%d) at %s",
                self.name, self.width, self.height, shm_path,
            )
            return True
        except Exception as e:
            logging.error(
                "Failed to open shared memory for model '%s': %s", self.name, e
            )
            return False

    def close(self):
        if self._mm:
            self._mm.close()
            self._mm = None
        if self._fd is not None:
            os.close(self._fd)
            self._fd = None

    def write_frame(self, pixels: list[tuple[int, int, int]]):
        """
        Write a full frame of pixels into shared memory.

        pixels : flat list of (r, g, b) tuples, length must equal width*height,
                 ordered left-to-right, top-to-bottom.
        """
        if not self._mm:
            return

        # Spin-wait if FPP is currently reading (lock byte == 1)
        # Timeout after 5ms to avoid hanging if FPP is not running
        deadline = time.monotonic() + 0.005
        while time.monotonic() < deadline:
            self._mm.seek(0)
            if self._mm.read_byte() == 0:
                break
            time.sleep(0.0001)

        # Build raw bytes: skip first 2 header bytes
        raw = bytearray(self._size)
        raw[0] = 0   # lock = 0 (we're writing)
        raw[1] = 0   # data flag — will set to 1 at end
        idx = 2
        for r, g, b in pixels:
            raw[idx]     = r & 0xFF
            raw[idx + 1] = g & 0xFF
            raw[idx + 2] = b & 0xFF
            idx += 3

        raw[1] = 1   # signal FPP that new data is ready

        self._mm.seek(0)
        self._mm.write(bytes(raw))

    def fill(self, r: int, g: int, b: int):
        """Fill entire model with a single colour."""
        self.write_frame([(r, g, b)] * (self.width * self.height))

    def validate_against_fpp(self, fpp_models: list[dict]) -> bool:
        """
        Check that a model with our name exists in FPP and has matching dimensions.
        Logs a warning if there's a mismatch — doesn't block operation.
        """
        match = next((m for m in fpp_models if m.get("name") == self.name), None)
        if not match:
            logging.warning(
                "Model '%s' not found in FPP! Create it in FPP → "
                "Content Setup → Pixel Overlay Models (%dx%d).",
                self.name, self.width, self.height,
            )
            return False

        fpp_w = int(match.get("width",  0))
        fpp_h = int(match.get("height", 0))
        if fpp_w != self.width or fpp_h != self.height:
            logging.warning(
                "Model '%s' dimension mismatch! "
                "Plugin expects %dx%d but FPP reports %dx%d. "
                "Update the plugin settings or the FPP model.",
                self.name, self.width, self.height, fpp_w, fpp_h,
            )
            return False

        logging.info("Model '%s' validated OK (%dx%d).", self.name, self.width, self.height)
        return True


# ─────────────────────────────────────────────────────────────────────────────
# Pixel font  (3×5 bitmap, scale-able)
# ─────────────────────────────────────────────────────────────────────────────
FONT_3x5: dict[str, list[list[int]]] = {
    "A": [[0,1,0],[1,0,1],[1,1,1],[1,0,1],[1,0,1]],
    "B": [[1,1,0],[1,0,1],[1,1,0],[1,0,1],[1,1,0]],
    "C": [[0,1,1],[1,0,0],[1,0,0],[1,0,0],[0,1,1]],
    "D": [[1,1,0],[1,0,1],[1,0,1],[1,0,1],[1,1,0]],
    "E": [[1,1,1],[1,0,0],[1,1,0],[1,0,0],[1,1,1]],
    "F": [[1,1,1],[1,0,0],[1,1,0],[1,0,0],[1,0,0]],
    "G": [[0,1,1],[1,0,0],[1,0,1],[1,0,1],[0,1,1]],
    "H": [[1,0,1],[1,0,1],[1,1,1],[1,0,1],[1,0,1]],
    "I": [[1,1,1],[0,1,0],[0,1,0],[0,1,0],[1,1,1]],
    "J": [[0,0,1],[0,0,1],[0,0,1],[1,0,1],[0,1,0]],
    "K": [[1,0,1],[1,0,1],[1,1,0],[1,0,1],[1,0,1]],
    "L": [[1,0,0],[1,0,0],[1,0,0],[1,0,0],[1,1,1]],
    "M": [[1,0,1],[1,1,1],[1,0,1],[1,0,1],[1,0,1]],
    "N": [[1,0,1],[1,1,1],[1,1,1],[1,0,1],[1,0,1]],
    "O": [[0,1,0],[1,0,1],[1,0,1],[1,0,1],[0,1,0]],
    "P": [[1,1,0],[1,0,1],[1,1,0],[1,0,0],[1,0,0]],
    "Q": [[0,1,0],[1,0,1],[1,0,1],[1,1,1],[0,1,1]],
    "R": [[1,1,0],[1,0,1],[1,1,0],[1,0,1],[1,0,1]],
    "S": [[0,1,1],[1,0,0],[0,1,0],[0,0,1],[1,1,0]],
    "T": [[1,1,1],[0,1,0],[0,1,0],[0,1,0],[0,1,0]],
    "U": [[1,0,1],[1,0,1],[1,0,1],[1,0,1],[0,1,0]],
    "V": [[1,0,1],[1,0,1],[1,0,1],[0,1,0],[0,1,0]],
    "W": [[1,0,1],[1,0,1],[1,0,1],[1,1,1],[1,0,1]],
    "X": [[1,0,1],[1,0,1],[0,1,0],[1,0,1],[1,0,1]],
    "Y": [[1,0,1],[1,0,1],[0,1,0],[0,1,0],[0,1,0]],
    "Z": [[1,1,1],[0,0,1],[0,1,0],[1,0,0],[1,1,1]],
    ".": [[0,0,0],[0,0,0],[0,0,0],[0,0,0],[0,1,0]],
    ",": [[0,0,0],[0,0,0],[0,0,0],[0,1,0],[1,0,0]],
    "-": [[0,0,0],[0,0,0],[1,1,1],[0,0,0],[0,0,0]],
    " ": [[0,0,0],[0,0,0],[0,0,0],[0,0,0],[0,0,0]],
}


def hex_to_rgb(hex_str: str) -> tuple[int, int, int]:
    h = hex_str.lstrip("#")
    return int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)


def make_canvas(w: int, h: int) -> list[list[tuple[int,int,int]]]:
    return [[(0, 0, 0)] * w for _ in range(h)]


def canvas_to_flat(canvas: list[list[tuple[int,int,int]]]) -> list[tuple[int,int,int]]:
    return [px for row in canvas for px in row]


def draw_char(canvas, ch: str, x: int, y: int, color: tuple, scale: int):
    glyph = FONT_3x5.get(ch.upper(), FONT_3x5[" "])
    for ry, row in enumerate(glyph):
        for rx, bit in enumerate(row):
            if bit:
                for sy in range(scale):
                    for sx in range(scale):
                        cx, cy = x + rx * scale + sx, y + ry * scale + sy
                        if 0 <= cx < len(canvas[0]) and 0 <= cy < len(canvas):
                            canvas[cy][cx] = color


def draw_text(canvas, text: str, x: int, y: int, color: tuple, scale: int) -> int:
    char_w = 3 * scale + scale   # 3 px glyph + 1 px gap, all scaled
    cx = x
    for ch in text.upper():
        draw_char(canvas, ch, cx, y, color, scale)
        cx += char_w
    return cx


def text_pixel_width(text: str, scale: int) -> int:
    return len(text) * (3 * scale + scale)


# ─────────────────────────────────────────────────────────────────────────────
# Renderers
# ─────────────────────────────────────────────────────────────────────────────

def render_header(list_type: str, cfg: dict) -> list[tuple[int,int,int]]:
    """
    Render the header panel (32 wide × 64 tall after 90° rotation).
    Shows 'NICE' in green tones or 'NAUGHTY' in red tones, centred.
    """
    w, h = cfg["header_model_width"], cfg["header_model_height"]
    canvas = make_canvas(w, h)

    is_nice = list_type == "nice"
    word    = "NICE" if is_nice else "NAUGHTY"
    color   = hex_to_rgb(cfg["nice_color"] if is_nice else cfg["naughty_color"])
    bg      = (0, 18, 0) if is_nice else (18, 0, 0)

    # Fill background
    for y in range(h):
        for x in range(w):
            canvas[y][x] = bg

    # Try scale=2 first; fall back to scale=1 if word doesn't fit
    for scale in (2, 1):
        tw = text_pixel_width(word, scale)
        th = 5 * scale
        if tw <= w:
            tx = (w - tw) // 2
            ty = (h - th) // 2
            draw_text(canvas, word, tx, ty, color, scale)
            break

    # Corner star decorations
    gold = (255, 200, 0)
    for sx, sy in [(1, 1), (w - 2, 1), (1, h - 2), (w - 2, h - 2)]:
        if 0 <= sx < w and 0 <= sy < h:
            canvas[sy][sx] = gold

    return canvas_to_flat(canvas)


def render_name_canvas(
    names: list[str],
    list_type: str,
    scroll_offset: int,
    cfg: dict,
) -> list[tuple[int,int,int]]:
    """
    Render the full name canvas (96 wide × 128 tall).
    Returns a flat pixel list ready to write to the SantaNames overlay model.
    """
    w  = cfg["names_model_width"]
    h  = cfg["names_model_height"]
    canvas = make_canvas(w, h)

    is_nice = list_type == "nice"
    color   = hex_to_rgb(cfg["nice_color"] if is_nice else cfg["naughty_color"])
    dim     = tuple(max(0, int(c * 0.3)) for c in color)
    scale   = int(cfg.get("font_scale", 2))
    line_h  = 5 * scale + scale + 2

    bg = (0, 10, 0) if is_nice else (10, 0, 0)
    for y in range(h):
        for x in range(w):
            canvas[y][x] = bg

    for i, name in enumerate(names):
        py = i * line_h - scroll_offset
        if py + line_h < 0 or py >= h:
            continue
        c = color if i % 2 == 0 else dim
        draw_text(canvas, name, 3, py, c, scale)

    return canvas_to_flat(canvas)


def max_scroll(names: list[str], cfg: dict) -> int:
    scale  = int(cfg.get("font_scale", 2))
    line_h = 5 * scale + scale + 2
    h      = cfg["names_model_height"]
    total  = len(names) * line_h
    return max(0, total - h)


# ─────────────────────────────────────────────────────────────────────────────
# Pixel outline controller  (PiHat)
# ─────────────────────────────────────────────────────────────────────────────

class PixelController:

    def __init__(self, cfg: dict):
        self.cfg    = cfg
        self.strip  = None
        self._mode  = "rainbow"
        self._lock  = threading.Lock()
        self._stop  = threading.Event()
        self._thread: Optional[threading.Thread] = None

        if HAS_WS281X:
            try:
                self.strip = PixelStrip(
                    cfg["pixel_count"], cfg["pixel_pin"],
                    800000, 10, False, cfg["pixel_brightness"], 0,
                )
                self.strip.begin()
                logging.info("PiHat pixel strip initialised (%d pixels)", cfg["pixel_count"])
            except Exception as e:
                logging.error("PixelStrip init error: %s", e)

    def set_mode(self, mode: str):
        with self._lock:
            self._mode = mode

    def start(self):
        self._stop.clear()
        self._thread = threading.Thread(target=self._run, daemon=True)
        self._thread.start()

    def stop(self):
        self._stop.set()
        if self._thread:
            self._thread.join(timeout=3)
        if self.strip:
            for i in range(self.cfg["pixel_count"]):
                self.strip.setPixelColor(i, Color(0, 0, 0))
            self.strip.show()

    def _run(self):
        offset = 0
        while not self._stop.is_set():
            with self._lock:
                mode = self._mode
            if   mode == "rainbow":          self._rainbow(offset)
            elif mode == "marquee_nice":     self._marquee(offset, (255, 255, 255))
            elif mode == "marquee_naughty":  self._marquee(offset, (255, 0, 0))
            offset = (offset + 1) % 256
            time.sleep(self.cfg["marquee_speed_ms"] / 1000)

    def _set(self, i, r, g, b):
        if self.strip:
            self.strip.setPixelColor(i, Color(r, g, b))

    def _show(self):
        if self.strip:
            self.strip.show()

    def _rainbow(self, offset):
        n = self.cfg["pixel_count"]
        for i in range(n):
            hue = ((i * 256 // n) + offset) % 256
            r, g, b = [int(c * 255) for c in colorsys.hsv_to_rgb(hue / 256, 1.0, 1.0)]
            self._set(i, r, g, b)
        self._show()

    def _marquee(self, offset, color):
        r, g, b = color
        n = self.cfg["pixel_count"]
        for i in range(n):
            if (i + offset // 4) % 3 == 0:
                self._set(i, r, g, b)
            else:
                self._set(i, 0, 0, 0)
        self._show()


# ─────────────────────────────────────────────────────────────────────────────
# WordPress API poller
# ─────────────────────────────────────────────────────────────────────────────

class WordPressPoller:

    def __init__(self, cfg: dict):
        self.cfg    = cfg
        self._names = {"nice": [], "naughty": []}
        self._since: Optional[str] = None
        self._lock  = threading.Lock()
        self._stop  = threading.Event()
        self._thread: Optional[threading.Thread] = None

    def get_names(self) -> dict:
        with self._lock:
            return {"nice": list(self._names["nice"]), "naughty": list(self._names["naughty"])}

    def start(self):
        self._stop.clear()
        self._thread = threading.Thread(target=self._run, daemon=True)
        self._thread.start()

    def stop(self):
        self._stop.set()

    def _run(self):
        self._poll(full=True)
        while not self._stop.wait(timeout=self.cfg.get("poll_interval_seconds", 60)):
            self.cfg = load_config()
            self._poll(full=False)

    def _poll(self, full: bool = False):
        url   = self.cfg.get("api_url", "")
        token = self.cfg.get("api_token", "")
        if not url or not token:
            logging.warning("WordPress API URL or token not configured.")
            return

        params = {} if full else ({"since": self._since} if self._since else {})

        try:
            resp = requests.get(
                url, params=params,
                headers={"Authorization": f"Bearer {token}"},
                timeout=10,
            )
            resp.raise_for_status()
            data = resp.json()
            nice    = [n["display_name"] for n in data.get("names", []) if n["list_type"] == "nice"]
            naughty = [n["display_name"] for n in data.get("names", []) if n["list_type"] == "naughty"]
            with self._lock:
                if full:
                    self._names = {"nice": nice, "naughty": naughty}
                else:
                    self._names["nice"]    += nice
                    self._names["naughty"] += naughty
            self._since = data.get("server_time")
            logging.info("Polled WP: +%d nice  +%d naughty", len(nice), len(naughty))
        except requests.RequestException as e:
            logging.error("WordPress poll error: %s", e)


# ─────────────────────────────────────────────────────────────────────────────
# Main
# ─────────────────────────────────────────────────────────────────────────────

def main():
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s [%(levelname)s] %(message)s",
        handlers=[
            logging.StreamHandler(sys.stdout),
            logging.FileHandler("/home/fpp/media/logs/santa_list_plugin.log"),
        ],
    )
    logging.info("═" * 60)
    logging.info("Santa List Plugin starting — FPP Pixel Overlay edition")
    logging.info("═" * 60)

    cfg = load_config()
    save_config(cfg)   # write defaults on first run

    # ── Fetch FPP model list & validate ──────────────────────────────────────
    fpp_models = fpp_get_models()
    if not fpp_models:
        logging.warning("Could not retrieve FPP model list — is FPP running?")

    header_model = OverlayModel(
        cfg["header_model_name"],
        cfg["header_model_width"],
        cfg["header_model_height"],
    )
    names_model = OverlayModel(
        cfg["names_model_name"],
        cfg["names_model_width"],
        cfg["names_model_height"],
    )

    # Validate dimensions against FPP
    header_model.validate_against_fpp(fpp_models)
    names_model.validate_against_fpp(fpp_models)

    # Enable models in FPP so they'll be pushed to hardware
    fpp_enable_model(cfg["header_model_name"])
    fpp_enable_model(cfg["names_model_name"])
    fpp_set_model_state(cfg["header_model_name"], "Normal")
    fpp_set_model_state(cfg["names_model_name"], "Normal")

    # Open shared memory
    if not header_model.open() or not names_model.open():
        logging.error("Failed to open one or more overlay model shared memory segments.")
        logging.error("Make sure the models are defined in FPP → Content Setup → Pixel Overlay Models.")
        sys.exit(1)

    # Clear panels to black on startup
    header_model.fill(0, 0, 0)
    names_model.fill(0, 0, 0)

    # ── Start subsystems ──────────────────────────────────────────────────────
    pixels  = PixelController(cfg)
    poller  = WordPressPoller(cfg)
    pixels.start()
    poller.start()

    current_list  = "nice"
    scroll_offset = 0
    last_switch   = time.monotonic()
    frame_count   = 0

    logging.info("Display loop started. Header model: '%s'  Names model: '%s'",
                 cfg["header_model_name"], cfg["names_model_name"])

    try:
        while True:
            # Hot-reload config every 300 frames (~10s at 30fps)
            frame_count += 1
            if frame_count % 300 == 0:
                cfg = load_config()
                pixels.cfg = cfg

            names     = poller.get_names()
            cur_names = names.get(current_list, [])

            # ── Pixel outline mode ────────────────────────────────────────────
            has_names = bool(names["nice"] or names["naughty"])
            if not has_names:
                pixels.set_mode("rainbow")
            elif current_list == "nice":
                pixels.set_mode("marquee_nice")
            else:
                pixels.set_mode("marquee_naughty")

            # ── Switch list every N seconds ───────────────────────────────────
            if time.monotonic() - last_switch >= cfg["list_display_seconds"]:
                current_list  = "naughty" if current_list == "nice" else "nice"
                scroll_offset = 0
                last_switch   = time.monotonic()
                logging.info("Switched to %s list (%d names)", current_list, len(names.get(current_list, [])))

            # ── Render & push header model ────────────────────────────────────
            header_pixels = render_header(current_list, cfg)
            header_model.write_frame(header_pixels)

            # ── Render & push name canvas model ───────────────────────────────
            name_pixels = render_name_canvas(cur_names, current_list, scroll_offset, cfg)
            names_model.write_frame(name_pixels)

            # ── Advance scroll ────────────────────────────────────────────────
            ms = max_scroll(cur_names, cfg)
            if ms > 0:
                scale  = int(cfg.get("font_scale", 2))
                line_h = 5 * scale + scale + 2
                # Pause at bottom for ~2 seconds before looping
                loop_len = ms + int(2000 / cfg["scroll_speed_ms"])
                scroll_offset = int((scroll_offset + 1) % loop_len)
                if scroll_offset > ms:
                    scroll_offset = min(scroll_offset, ms)
            else:
                scroll_offset = 0

            time.sleep(cfg["scroll_speed_ms"] / 1000)

    except KeyboardInterrupt:
        logging.info("Shutting down...")
    finally:
        header_model.fill(0, 0, 0)
        names_model.fill(0, 0, 0)
        pixels.stop()
        poller.stop()
        header_model.close()
        names_model.close()
        logging.info("Plugin stopped cleanly.")


if __name__ == "__main__":
    main()
