<?php
/**
 * Santa's Naughty & Nice List — FPP Plugin Settings UI  (v2)
 * Served by FPP's built-in web server as the plugin's configuration page.
 *
 * Key feature: model name dropdowns are populated live from FPP's own API
 * (GET /api/models) so you never type a model name manually.
 */

$config_path = '/home/fpp/media/config/santa_list_plugin.json';

$defaults = [
    'api_url'               => '',
    'api_token'             => '',
    'poll_interval_seconds' => 60,
    'header_model_name'     => 'SantaHeader',
    'names_model_name'      => 'SantaNames',
    'header_model_width'    => 32,
    'header_model_height'   => 64,
    'names_model_width'     => 96,
    'names_model_height'    => 128,
    'list_display_seconds'  => 30,
    'pixel_pin'             => 18,
    'pixel_count'           => 100,
    'pixel_brightness'      => 200,
    'marquee_speed_ms'      => 50,
    'nice_color'            => '#ffffff',
    'naughty_color'         => '#ff0000',
    'font_scale'            => 2,
    'scroll_speed_ms'       => 40,
];

$saved = [];
if (file_exists($config_path)) {
    $saved = json_decode(file_get_contents($config_path), true) ?: [];
}
$cfg = array_merge($defaults, $saved);

// ── Save handler ──────────────────────────────────────────────────────────
$save_msg   = '';
$save_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sld_save'])) {
    $new = [];
    foreach ($defaults as $k => $def) {
        if (array_key_exists($k, $_POST)) {
            $v = $_POST[$k];
            $new[$k] = is_int($def) ? (int)$v : (string)$v;
        }
    }
    if (file_put_contents($config_path, json_encode($new, JSON_PRETTY_PRINT)) !== false) {
        $cfg      = array_merge($defaults, $new);
        $save_msg = 'Settings saved! The plugin will hot-reload automatically.';
    } else {
        $save_error = 'Could not write config file. Check permissions on ' . $config_path;
    }
}

// ── WordPress ping handler ─────────────────────────────────────────────────
$ping_result = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sld_ping'])) {
    $url   = trim($_POST['api_url']   ?? '');
    $token = trim($_POST['api_token'] ?? '');
    // Convert names URL to ping URL
    $ping_url = preg_replace('/\/names$/', '/ping', rtrim($url, '/'));
    if ($ping_url === $url) {
        $ping_url = rtrim($url, '/') . '/ping'; // fallback
    }
    $ch = curl_init($ping_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code === 200) {
        $data        = json_decode($resp, true);
        $ver         = $data['version'] ?? 'unknown';
        $ping_result = "ok:Connected! WordPress plugin v{$ver} is reachable.";
    } elseif ($code > 0) {
        $ping_result = "err:HTTP {$code} — check your URL and token.";
    } else {
        $ping_result = "err:Could not reach host — {$err}";
    }
}

// ── Fetch FPP models via local API ────────────────────────────────────────
$fpp_models      = [];
$fpp_model_error = '';
$ch = curl_init('http://localhost/api/models');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
$fpp_resp = curl_exec($ch);
$fpp_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($fpp_code === 200) {
    $fpp_models = json_decode($fpp_resp, true) ?: [];
} else {
    $fpp_model_error = 'Could not fetch FPP model list (is FPP running?).';
}

function v($key) {
    global $cfg;
    return htmlspecialchars((string)($cfg[$key] ?? ''), ENT_QUOTES);
}

function model_select(string $field_name, string $current_value, array $models, string $expected_dims = ''): void {
    echo '<select name="' . $field_name . '" class="sld-select" data-current="' . htmlspecialchars($current_value, ENT_QUOTES) . '">';
    echo '<option value="">— select a model —</option>';
    foreach ($models as $m) {
        $name = htmlspecialchars($m['name'] ?? '', ENT_QUOTES);
        $w    = (int)($m['width']  ?? 0);
        $h    = (int)($m['height'] ?? 0);
        $sel  = ($m['name'] === $current_value) ? ' selected' : '';
        echo "<option value=\"{$name}\"{$sel}>{$name} ({$w}×{$h})</option>";
    }
    // If saved value not in list, add it so it's preserved
    $in_list = array_filter($models, fn($m) => ($m['name'] ?? '') === $current_value);
    if ($current_value && empty($in_list)) {
        echo '<option value="' . htmlspecialchars($current_value, ENT_QUOTES) . '" selected>' . htmlspecialchars($current_value, ENT_QUOTES) . ' (not found in FPP)</option>';
    }
    echo '</select>';
    if ($expected_dims) {
        echo '<span class="sld-hint">Expected dimensions: ' . $expected_dims . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Santa's List Display — FPP Settings</title>
<style>
:root {
    --bg:       #0d1117;
    --surface:  #161b22;
    --surface2: #1c2128;
    --border:   #30363d;
    --accent:   #c41e1e;
    --gold:     #f8e04a;
    --green:    #4caf50;
    --red:      #f44336;
    --text:     #e6edf3;
    --muted:    #8b949e;
    --radius:   10px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'Segoe UI',system-ui,sans-serif;font-size:14px;line-height:1.5}
a{color:var(--gold)}

/* Layout */
.page-header{background:linear-gradient(90deg,#1a0000,#0d1a00);border-bottom:1px solid var(--border);padding:1.2rem 2rem;display:flex;align-items:center;gap:1rem}
.page-header h1{font-size:1.5rem;color:var(--gold);font-weight:700}
.page-header p{color:var(--muted);font-size:0.85rem;margin-top:2px}
.wrap{max-width:1080px;margin:0 auto;padding:1.5rem 2rem 4rem}

/* Notices */
.notice{padding:.75rem 1.1rem;border-radius:var(--radius);margin:0 0 1.2rem;font-weight:600;display:flex;align-items:center;gap:.6rem}
.notice-success{background:rgba(76,175,80,.12);border:1px solid var(--green);color:#7defa0}
.notice-error  {background:rgba(244,67,54,.12); border:1px solid var(--red);  color:#ff8a93}
.notice-warn   {background:rgba(248,224,74,.1); border:1px solid #a89000;     color:#f8e04a}

/* Tabs */
.tabs{display:flex;gap:2px;border-bottom:2px solid var(--border);margin-bottom:1.5rem}
.tab-btn{background:none;border:none;border-bottom:3px solid transparent;margin-bottom:-2px;
    padding:.55rem 1.1rem;color:var(--muted);cursor:pointer;font-size:.875rem;font-weight:600;
    transition:color .15s,border-color .15s;white-space:nowrap}
.tab-btn:hover{color:var(--text)}
.tab-btn.active{color:var(--text);border-bottom-color:var(--accent)}
.tab-panel{display:none}.tab-panel.active{display:block}

/* Sections */
section{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
    padding:1.2rem 1.5rem;margin-bottom:1rem}
section h2{font-size:.875rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
    color:var(--gold);border-bottom:1px solid var(--border);padding-bottom:.5rem;margin-bottom:1rem}

/* Form grid */
.fg{display:grid;grid-template-columns:220px 1fr;gap:.7rem 1.2rem;align-items:start}
.fg label{color:var(--muted);font-size:.85rem;padding-top:.45rem;line-height:1.3}
.fg .sld-hint{grid-column:2;font-size:.75rem;color:var(--muted);margin-top:-.3rem}
.fg .sld-warn{grid-column:2;font-size:.75rem;color:#f8e04a;margin-top:-.3rem}

input[type=text],input[type=number],input[type=password],input[type=url],.sld-select{
    background:var(--surface2);border:1px solid var(--border);border-radius:6px;
    padding:.45rem .75rem;color:var(--text);font-size:.875rem;
    width:100%;max-width:400px;transition:border-color .2s}
input:focus,.sld-select:focus{outline:none;border-color:var(--gold)}
input[type=color]{width:44px;height:32px;padding:2px;border-radius:6px;cursor:pointer;
    border:1px solid var(--border);background:var(--surface2)}

.model-row{display:flex;flex-direction:column;gap:.3rem;max-width:400px}
.model-status{font-size:.75rem;padding:2px 8px;border-radius:20px;display:inline-flex;align-items:center;gap:.3rem}
.model-ok  {background:rgba(76,175,80,.15);color:#7defa0;border:1px solid rgba(76,175,80,.3)}
.model-warn{background:rgba(248,224,74,.1); color:#f8e04a; border:1px solid rgba(248,224,74,.3)}
.model-err {background:rgba(244,67,54,.1);  color:#ff8a93; border:1px solid rgba(244,67,54,.3)}

/* Buttons */
.btn{padding:.5rem 1.2rem;border-radius:6px;border:none;font-size:.875rem;font-weight:700;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:.4rem}
.btn-primary  {background:var(--accent);color:#fff}
.btn-primary:hover{background:#e02222}
.btn-secondary{background:var(--surface2);color:var(--text);border:1px solid var(--border)}
.btn-secondary:hover{border-color:var(--gold);color:var(--gold)}
.btn-row{display:flex;gap:.8rem;margin-top:1.5rem;flex-wrap:wrap;align-items:center}

#ping-result{font-size:.875rem;font-weight:600}
.ping-ok {color:#7defa0} .ping-err{color:#ff8a93}

/* ── Preview ──────────────────────────────────────────────────────────── */
.preview-outer{display:flex;gap:2.5rem;flex-wrap:wrap;justify-content:center;margin-top:1rem}
.preview-group{display:flex;flex-direction:column;align-items:center;gap:.5rem}
.preview-group-title{font-size:.8rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--muted)}
.preview-prop{display:flex;gap:.5rem;align-items:center}

/* Each logical panel after 90° rotation: 32px wide × 64px tall
   We display at 3× = 96×192 — but that's big, so 2× = 64×128               */
.panel-wrap{display:flex;flex-direction:column;align-items:center;gap:3px}
.panel-label{font-size:.6rem;color:#555}
.panel{
    width:64px;height:128px;   /* 32×64 at 2× scale */
    background:#050505;
    border:1px solid #2a2a2a;
    border-radius:3px;
    overflow:hidden;position:relative
}
.panel canvas{width:100%;height:100%;image-rendering:pixelated;display:block}
.port-badge{position:absolute;top:2px;right:2px;font-size:.55rem;background:rgba(0,0,0,.7);
    color:#555;padding:0 3px;border-radius:2px}

.name-grid{display:flex;gap:3px}
.name-col {display:flex;flex-direction:column;gap:3px}

.preview-controls{display:flex;gap:.8rem;align-items:center;flex-wrap:wrap;margin-top:1rem;justify-content:center}
.preview-controls input[type=text]{max-width:400px}

/* ── FPP model setup guide ───────────────────────────────────────────── */
.step-list{list-style:none;counter-reset:steps;display:flex;flex-direction:column;gap:.8rem}
.step-list li{counter-increment:steps;display:flex;gap:.8rem;align-items:flex-start}
.step-list li::before{content:counter(steps);background:var(--accent);color:#fff;
    width:1.6rem;height:1.6rem;border-radius:50%;display:flex;align-items:center;
    justify-content:center;font-size:.8rem;font-weight:700;flex-shrink:0;margin-top:.1rem}
code{background:var(--surface2);border:1px solid var(--border);padding:1px 6px;border-radius:4px;font-size:.82rem}
.model-table{width:100%;border-collapse:collapse;margin-top:.6rem}
.model-table th,.model-table td{padding:.4rem .7rem;text-align:left;border-bottom:1px solid var(--border);font-size:.85rem}
.model-table th{color:var(--muted);font-weight:600}
</style>
</head>
<body>

<div class="page-header">
    <div style="font-size:2rem;line-height:1">🎅</div>
    <div>
        <h1>Santa's Naughty &amp; Nice List Display</h1>
        <p>FPP Plugin Settings — v2.0  |  Pixel Overlay Model Edition</p>
    </div>
</div>

<div class="wrap">

<?php if ($save_msg):   ?><div class="notice notice-success">✅ <?= $save_msg ?></div><?php endif; ?>
<?php if ($save_error): ?><div class="notice notice-error">❌ <?= $save_error ?></div><?php endif; ?>
<?php if ($fpp_model_error): ?>
<div class="notice notice-warn">⚠️ <?= $fpp_model_error ?> Model dropdowns will be limited.</div>
<?php endif; ?>

<form method="post" id="sld-form">

<div class="tabs">
    <button type="button" class="tab-btn active" data-tab="models">🧩 Models</button>
    <button type="button" class="tab-btn" data-tab="connection">🔗 WordPress</button>
    <button type="button" class="tab-btn" data-tab="display">🎨 Display</button>
    <button type="button" class="tab-btn" data-tab="pixels">💡 Pixels</button>
    <button type="button" class="tab-btn" data-tab="preview">👁 Preview</button>
    <button type="button" class="tab-btn" data-tab="guide">📋 Setup Guide</button>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     TAB: Models
     ═════════════════════════════════════════════════════════════════════ -->
<div class="tab-panel active" id="tab-models">

    <section>
        <h2>FPP Pixel Overlay Model Assignment</h2>
        <p style="color:var(--muted);font-size:.85rem;margin-bottom:1rem">
            Select which FPP Pixel Overlay Model each part of the display uses.
            Models are loaded live from FPP — create them first in
            <strong>FPP → Content Setup → Pixel Overlay Models</strong>,
            then select them here. See the <strong>Setup Guide</strong> tab for exact steps.
        </p>

        <?php
        // Build a lookup of model name → dimensions for JS validation
        $model_dims = [];
        foreach ($fpp_models as $m) {
            $model_dims[$m['name']] = ['w' => (int)($m['width'] ?? 0), 'h' => (int)($m['height'] ?? 0)];
        }
        ?>

        <div class="fg">

            <!-- Header model -->
            <label>Header Panel Model<br>
                <small style="color:var(--muted);font-weight:400">Displays NICE or NAUGHTY</small>
            </label>
            <div class="model-row">
                <?php model_select('header_model_name', $cfg['header_model_name'], $fpp_models, '32 wide × 64 tall'); ?>
                <?php
                $hm = array_filter($fpp_models, fn($m) => ($m['name'] ?? '') === $cfg['header_model_name']);
                $hm = reset($hm);
                if ($hm) {
                    $hw = (int)($hm['width'] ?? 0); $hh = (int)($hm['height'] ?? 0);
                    if ($hw === $cfg['header_model_width'] && $hh === $cfg['header_model_height']) {
                        echo '<span class="model-status model-ok">✅ Dimensions match (' . $hw . '×' . $hh . ')</span>';
                    } else {
                        echo '<span class="model-status model-warn">⚠️ FPP reports ' . $hw . '×' . $hh . ', plugin expects ' . $cfg['header_model_width'] . '×' . $cfg['header_model_height'] . '</span>';
                    }
                } elseif ($cfg['header_model_name']) {
                    echo '<span class="model-status model-err">❌ Model not found in FPP</span>';
                }
                ?>
            </div>

            <label>Expected header dimensions</label>
            <div style="display:flex;gap:.5rem;align-items:center">
                <input type="number" name="header_model_width"  value="<?= v('header_model_width')  ?>" min="1" max="512" style="max-width:80px"> ×
                <input type="number" name="header_model_height" value="<?= v('header_model_height') ?>" min="1" max="512" style="max-width:80px"> px
            </div>
            <span class="sld-hint">Must match the model dimensions in FPP exactly.</span>

            <!-- Names model -->
            <label style="margin-top:.5rem">Name Display Model<br>
                <small style="color:var(--muted);font-weight:400">Displays the scrolling name list</small>
            </label>
            <div class="model-row" style="margin-top:.5rem">
                <?php model_select('names_model_name', $cfg['names_model_name'], $fpp_models, '96 wide × 128 tall'); ?>
                <?php
                $nm = array_filter($fpp_models, fn($m) => ($m['name'] ?? '') === $cfg['names_model_name']);
                $nm = reset($nm);
                if ($nm) {
                    $nw = (int)($nm['width'] ?? 0); $nh = (int)($nm['height'] ?? 0);
                    if ($nw === $cfg['names_model_width'] && $nh === $cfg['names_model_height']) {
                        echo '<span class="model-status model-ok">✅ Dimensions match (' . $nw . '×' . $nh . ')</span>';
                    } else {
                        echo '<span class="model-status model-warn">⚠️ FPP reports ' . $nw . '×' . $nh . ', plugin expects ' . $cfg['names_model_width'] . '×' . $cfg['names_model_height'] . '</span>';
                    }
                } elseif ($cfg['names_model_name']) {
                    echo '<span class="model-status model-err">❌ Model not found in FPP</span>';
                }
                ?>
            </div>

            <label>Expected names dimensions</label>
            <div style="display:flex;gap:.5rem;align-items:center">
                <input type="number" name="names_model_width"  value="<?= v('names_model_width')  ?>" min="1" max="512" style="max-width:80px"> ×
                <input type="number" name="names_model_height" value="<?= v('names_model_height') ?>" min="1" max="512" style="max-width:80px"> px
            </div>
            <span class="sld-hint">Must match the model dimensions in FPP exactly.</span>

        </div>

        <div class="btn-row">
            <button type="button" class="btn btn-secondary" id="refresh-models">🔄 Refresh Model List</button>
            <span style="color:var(--muted);font-size:.8rem">Refreshes the dropdown lists from FPP without saving.</span>
        </div>
    </section>

    <section>
        <h2>Timing</h2>
        <div class="fg">
            <label>Seconds per list</label>
            <input type="number" name="list_display_seconds" value="<?= v('list_display_seconds') ?>" min="5" max="600" style="max-width:100px">
            <span class="sld-hint">How long NICE (or NAUGHTY) is shown before the display switches. Default: 30s.</span>
        </div>
    </section>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     TAB: WordPress Connection
     ═════════════════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="tab-connection">
    <section>
        <h2>WordPress API</h2>
        <div class="fg">
            <label>API URL</label>
            <input type="url" name="api_url" value="<?= v('api_url') ?>" placeholder="https://your-site.com/wp-json/sld/v1/names">
            <span class="sld-hint">Full URL to the /names endpoint on your WordPress site.</span>

            <label>API Token</label>
            <input type="password" name="api_token" value="<?= v('api_token') ?>" autocomplete="off">
            <span class="sld-hint">Bearer token from WordPress admin → Santa's List → API &amp; Security.</span>

            <label>Poll Interval (seconds)</label>
            <input type="number" name="poll_interval_seconds" value="<?= v('poll_interval_seconds') ?>" min="10" max="3600" style="max-width:110px">
            <span class="sld-hint">How often FPP checks for new names. Default: 60s.</span>
        </div>

        <div class="btn-row">
            <button type="submit" name="sld_ping" class="btn btn-secondary">🔌 Test Connection</button>
            <?php if ($ping_result):
                [$type, $msg] = explode(':', $ping_result, 2);
                $cls = $type === 'ok' ? 'ping-ok' : 'ping-err';
                $ico = $type === 'ok' ? '✅' : '❌';
                echo "<span id='ping-result' class='$cls'>$ico $msg</span>";
            else: ?>
            <span id="ping-result"></span>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     TAB: Display
     ═════════════════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="tab-display">
    <section>
        <h2>Colors</h2>
        <div class="fg">
            <label>Nice List Color</label>
            <input type="color" name="nice_color" value="<?= v('nice_color') ?>" id="inp-nice-color">

            <label>Naughty List Color</label>
            <input type="color" name="naughty_color" value="<?= v('naughty_color') ?>" id="inp-naughty-color">
        </div>
    </section>
    <section>
        <h2>Text &amp; Scrolling</h2>
        <div class="fg">
            <label>Font Scale</label>
            <select name="font_scale" class="sld-select" style="max-width:140px" id="inp-font-scale">
                <option value="1" <?= $cfg['font_scale']==1?'selected':'' ?>>1× — small</option>
                <option value="2" <?= $cfg['font_scale']==2?'selected':'' ?>>2× — default</option>
                <option value="3" <?= $cfg['font_scale']==3?'selected':'' ?>>3× — large</option>
            </select>
            <span class="sld-hint">Scales the pixel font. 2× fits ~8 names at once on the 96×128 canvas.</span>

            <label>Scroll Speed (ms/step)</label>
            <input type="number" name="scroll_speed_ms" value="<?= v('scroll_speed_ms') ?>" min="10" max="500" style="max-width:110px">
            <span class="sld-hint">Lower = faster scroll. Default: 40ms.</span>
        </div>
    </section>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     TAB: Pixels
     ═════════════════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="tab-pixels">
    <section>
        <h2>PiHat Outline Pixel Strip</h2>
        <div class="fg">
            <label>GPIO Pin (BCM)</label>
            <input type="number" name="pixel_pin" value="<?= v('pixel_pin') ?>" min="1" max="40" style="max-width:90px">
            <span class="sld-hint">BCM pin number. Default: 18 (PWM0). Common choices: 12, 18, 21.</span>

            <label>Pixel Count</label>
            <input type="number" name="pixel_count" value="<?= v('pixel_count') ?>" min="1" max="2000" style="max-width:90px">

            <label>Brightness (0–255)</label>
            <input type="number" name="pixel_brightness" value="<?= v('pixel_brightness') ?>" min="0" max="255" style="max-width:90px">

            <label>Marquee Speed (ms/step)</label>
            <input type="number" name="marquee_speed_ms" value="<?= v('marquee_speed_ms') ?>" min="10" max="500" style="max-width:90px">
            <span class="sld-hint">Chase effect speed when a name is announced. Default: 50ms.</span>
        </div>
    </section>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     TAB: Preview
     ═════════════════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="tab-preview">
    <section>
        <h2>Live Display Preview</h2>
        <p style="color:var(--muted);font-size:.85rem;margin-bottom:1rem">
            Each panel is shown at 2× scale. Panels are rotated 90° (portrait = 32 wide × 64 tall after rotation).
            The name canvas spans 3 columns × 2 rows = 96×128 pixels total.
        </p>

        <div class="preview-outer">

            <!-- NICE -->
            <div class="preview-group">
                <div class="preview-group-title">✅ Nice List</div>
                <div class="preview-prop">
                    <!-- Header panel -->
                    <div class="panel-wrap">
                        <div class="panel-label">Port 1<br>Header</div>
                        <div class="panel"><canvas id="cv-hdr-nice" width="32" height="64"></canvas></div>
                    </div>
                    <!-- Name grid: 3 cols × 2 rows -->
                    <div style="display:flex;flex-direction:column;gap:4px">
                        <div class="panel-label" style="text-align:center">Ports 2–4 · Name Display</div>
                        <div class="name-grid">
                            <?php for ($col=1;$col<=3;$col++): ?>
                            <div class="name-col">
                                <?php for ($row=1;$row<=2;$row++): ?>
                                <div class="panel">
                                    <canvas id="cv-nice-c<?=$col?>r<?=$row?>" width="32" height="64"></canvas>
                                    <span class="port-badge">P<?=$col+1?></span>
                                </div>
                                <?php endfor; ?>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- NAUGHTY -->
            <div class="preview-group">
                <div class="preview-group-title">❌ Naughty List</div>
                <div class="preview-prop">
                    <div class="panel-wrap">
                        <div class="panel-label">Port 1<br>Header</div>
                        <div class="panel"><canvas id="cv-hdr-naughty" width="32" height="64"></canvas></div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:4px">
                        <div class="panel-label" style="text-align:center">Ports 2–4 · Name Display</div>
                        <div class="name-grid">
                            <?php for ($col=1;$col<=3;$col++): ?>
                            <div class="name-col">
                                <?php for ($row=1;$row<=2;$row++): ?>
                                <div class="panel">
                                    <canvas id="cv-naughty-c<?=$col?>r<?=$row?>" width="32" height="64"></canvas>
                                    <span class="port-badge">P<?=$col+1?></span>
                                </div>
                                <?php endfor; ?>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- preview-outer -->

        <div class="preview-controls">
            <label style="color:var(--muted)">Sample names:</label>
            <input type="text" id="preview-names"
                value="Emma S., Liam T., Olivia R., Noah B., Ava M., Lucas W., Sophia K., Mason L., Ella J., Aiden C."
                style="max-width:420px">
            <button type="button" class="btn btn-secondary" id="btn-refresh-preview">🔄 Refresh Preview</button>
        </div>
    </section>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     TAB: Setup Guide
     ═════════════════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="tab-guide">
    <section>
        <h2>How FPP Pixel Overlay Models Work</h2>
        <p style="color:var(--muted);margin-bottom:1rem;font-size:.875rem">
            The plugin writes pixel data into FPP's shared memory buffers.
            FPP reads those buffers and sends them to your ColorLight card via its normal Channel Output system.
            <strong>The plugin never talks to the ColorLight card directly.</strong>
        </p>
        <div style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:1rem;font-family:monospace;font-size:.8rem;color:var(--muted);margin-bottom:1rem;overflow-x:auto">
            WordPress → Python Plugin → <strong style="color:var(--gold)">FPP Shared Memory</strong> → FPP Channel Output → ColorLight Card → LED Panels
        </div>
    </section>

    <section>
        <h2>Step-by-Step FPP Setup</h2>
        <ol class="step-list">
            <li>
                <div>
                    <strong>Configure your ColorLight Channel Output in FPP</strong><br>
                    <span style="color:var(--muted);font-size:.85rem">
                        Go to <em>FPP → Channel Outputs</em>. Add or confirm your ColorLight output is set up with
                        the correct network interface (usually <code>eth0</code> or <code>eth1</code> for the
                        USB-Gigabit adapter). Your panels need to be working here before the plugin matters.
                    </span>
                </div>
            </li>
            <li>
                <div>
                    <strong>Create the Header Pixel Overlay Model</strong><br>
                    <span style="color:var(--muted);font-size:.85rem">
                        Go to <em>FPP → Content Setup → Pixel Overlay Models</em>. Click <strong>Add</strong> and fill in:
                    </span>
                    <table class="model-table" style="margin-top:.5rem">
                        <tr><th>Field</th><th>Value</th></tr>
                        <tr><td>Name</td><td><code>SantaHeader</code></td></tr>
                        <tr><td>Type</td><td>Channel Range</td></tr>
                        <tr><td>Start Channel</td><td>Your Port 1 panel start channel</td></tr>
                        <tr><td>Width</td><td><code>32</code></td></tr>
                        <tr><td>Height</td><td><code>64</code></td></tr>
                        <tr><td>Is Locked</td><td>No</td></tr>
                    </table>
                </div>
            </li>
            <li>
                <div>
                    <strong>Create the Names Pixel Overlay Model</strong><br>
                    <span style="color:var(--muted);font-size:.85rem">Same process, different values:</span>
                    <table class="model-table" style="margin-top:.5rem">
                        <tr><th>Field</th><th>Value</th></tr>
                        <tr><td>Name</td><td><code>SantaNames</code></td></tr>
                        <tr><td>Type</td><td>Channel Range</td></tr>
                        <tr><td>Start Channel</td><td>Your Port 2 panel start channel</td></tr>
                        <tr><td>Width</td><td><code>96</code></td></tr>
                        <tr><td>Height</td><td><code>128</code></td></tr>
                        <tr><td>Is Locked</td><td>No</td></tr>
                    </table>
                </div>
            </li>
            <li>
                <div>
                    <strong>Find your Start Channel numbers</strong><br>
                    <span style="color:var(--muted);font-size:.85rem">
                        In FPP → Channel Outputs, each output shows its start/end channel range.
                        Port 1 of your ColorLight card = the first channel in that output's range.
                        Port 2 starts immediately after Port 1 ends (Port 1 has 32×64×3 = 6,144 channels).
                        So if Port 1 starts at channel 1: Port 1 = 1–6144, Port 2 = 6145–18432, etc.
                    </span>
                </div>
            </li>
            <li>
                <div>
                    <strong>Enable the models</strong><br>
                    <span style="color:var(--muted);font-size:.85rem">
                        On the Pixel Overlay Models page, toggle both models to <strong>Active</strong>.
                        The plugin also enables them automatically at startup via FPP's API.
                    </span>
                </div>
            </li>
            <li>
                <div>
                    <strong>Come back to the Models tab and select the models</strong><br>
                    <span style="color:var(--muted);font-size:.85rem">
                        The dropdowns will now show <code>SantaHeader</code> and <code>SantaNames</code>.
                        Select them, confirm the green dimension-match badges appear, then Save.
                    </span>
                </div>
            </li>
            <li>
                <div>
                    <strong>Install Python dependencies and start the plugin</strong><br>
                    <span style="color:var(--muted);font-size:.85rem">
                        SSH into your Pi and run:<br>
                        <code>pip3 install requests rpi_ws281x</code><br>
                        The plugin starts automatically when FPP loads it, or manually:<br>
                        <code>python3 /home/fpp/media/plugins/santa-list-display/santa_list_plugin.py</code><br>
                        Logs: <code>/home/fpp/media/logs/santa_list_plugin.log</code>
                    </span>
                </div>
            </li>
        </ol>
    </section>

    <section>
        <h2>Panel Rotation Note</h2>
        <p style="color:var(--muted);font-size:.875rem">
            Your P5 panels are physically 64×32 but mounted in portrait (rotated 90°).
            Handle the rotation in <strong>FPP's Channel Output / Panel Matrix settings</strong> — set each panel
            to 90° rotation there. The plugin then treats the logical canvas as already-rotated
            (32 wide × 64 tall per panel) and FPP remaps the physical pixels automatically.
            This way rotation is configured once in FPP and the plugin code stays simple.
        </p>
    </section>
</div>

<!-- ── Global save bar ───────────────────────────────────────────────────── -->
<div class="btn-row" style="padding:1.2rem 0 0;border-top:1px solid var(--border);margin-top:1rem">
    <button type="submit" name="sld_save" class="btn btn-primary">💾 Save Settings</button>
    <span style="color:var(--muted);font-size:.82rem">Settings are hot-reloaded by the Python script within ~10 seconds.</span>
</div>

</form>
</div><!-- /wrap -->

<script>
// ── Model metadata from PHP ──────────────────────────────────────────────────
const FPP_MODELS = <?= json_encode(array_column($fpp_models, null, 'name')) ?>;

// ── Tabs ─────────────────────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn,.tab-panel').forEach(el => el.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
        if (btn.dataset.tab === 'preview') drawPreviews();
    });
});

// ── Refresh model list ────────────────────────────────────────────────────────
document.getElementById('refresh-models')?.addEventListener('click', () => {
    location.reload();
});

// ─────────────────────────────────────────────────────────────────────────────
// Canvas preview renderer
// ─────────────────────────────────────────────────────────────────────────────
const FONT = {
    A:[[0,1,0],[1,0,1],[1,1,1],[1,0,1],[1,0,1]], B:[[1,1,0],[1,0,1],[1,1,0],[1,0,1],[1,1,0]],
    C:[[0,1,1],[1,0,0],[1,0,0],[1,0,0],[0,1,1]], D:[[1,1,0],[1,0,1],[1,0,1],[1,0,1],[1,1,0]],
    E:[[1,1,1],[1,0,0],[1,1,0],[1,0,0],[1,1,1]], F:[[1,1,1],[1,0,0],[1,1,0],[1,0,0],[1,0,0]],
    G:[[0,1,1],[1,0,0],[1,0,1],[1,0,1],[0,1,1]], H:[[1,0,1],[1,0,1],[1,1,1],[1,0,1],[1,0,1]],
    I:[[1,1,1],[0,1,0],[0,1,0],[0,1,0],[1,1,1]], J:[[0,0,1],[0,0,1],[0,0,1],[1,0,1],[0,1,0]],
    K:[[1,0,1],[1,0,1],[1,1,0],[1,0,1],[1,0,1]], L:[[1,0,0],[1,0,0],[1,0,0],[1,0,0],[1,1,1]],
    M:[[1,0,1],[1,1,1],[1,0,1],[1,0,1],[1,0,1]], N:[[1,0,1],[1,1,1],[1,1,1],[1,0,1],[1,0,1]],
    O:[[0,1,0],[1,0,1],[1,0,1],[1,0,1],[0,1,0]], P:[[1,1,0],[1,0,1],[1,1,0],[1,0,0],[1,0,0]],
    R:[[1,1,0],[1,0,1],[1,1,0],[1,0,1],[1,0,1]], S:[[0,1,1],[1,0,0],[0,1,0],[0,0,1],[1,1,0]],
    T:[[1,1,1],[0,1,0],[0,1,0],[0,1,0],[0,1,0]], U:[[1,0,1],[1,0,1],[1,0,1],[1,0,1],[0,1,0]],
    V:[[1,0,1],[1,0,1],[1,0,1],[0,1,0],[0,1,0]], W:[[1,0,1],[1,0,1],[1,0,1],[1,1,1],[1,0,1]],
    X:[[1,0,1],[1,0,1],[0,1,0],[1,0,1],[1,0,1]], Y:[[1,0,1],[1,0,1],[0,1,0],[0,1,0],[0,1,0]],
    Z:[[1,1,1],[0,0,1],[0,1,0],[1,0,0],[1,1,1]],
    '.':[[0,0,0],[0,0,0],[0,0,0],[0,0,0],[0,1,0]],
    ',':[[0,0,0],[0,0,0],[0,0,0],[0,1,0],[1,0,0]],
    ' ':[[0,0,0],[0,0,0],[0,0,0],[0,0,0],[0,0,0]],
};

function hexToRgb(h) {
    return [parseInt(h.slice(1,3),16), parseInt(h.slice(3,5),16), parseInt(h.slice(5,7),16)];
}
function rgbStr([r,g,b]) { return `rgb(${r},${g},${b})`; }
function dimRgb([r,g,b], f=0.3) { return [Math.round(r*f), Math.round(g*f), Math.round(b*f)]; }

function drawChar(ctx, ch, x, y, color, scale) {
    const g = FONT[ch.toUpperCase()] || FONT[' '];
    ctx.fillStyle = rgbStr(color);
    g.forEach((row, ry) => row.forEach((bit, rx) => {
        if (bit) ctx.fillRect(x+rx*scale, y+ry*scale, scale, scale);
    }));
}
function drawText(ctx, text, x, y, color, scale) {
    const cw = 3*scale + scale;
    let cx = x;
    for (const ch of text.toUpperCase()) { drawChar(ctx, ch, cx, y, color, scale); cx += cw; }
    return cx;
}
function textW(text, scale) { return text.length * (3*scale + scale); }

function getSettings() {
    return {
        niceColor:    document.getElementById('inp-nice-color')?.value    || '#ffffff',
        naughtyColor: document.getElementById('inp-naughty-color')?.value || '#ff0000',
        fontScale:    parseInt(document.getElementById('inp-font-scale')?.value || 2),
    };
}

function renderHeader(canvasId, listType) {
    const s  = getSettings();
    const cv = document.getElementById(canvasId); if (!cv) return;
    const ctx = cv.getContext('2d');
    const [w, h] = [32, 64];
    const isNice = listType === 'nice';
    const color  = hexToRgb(isNice ? s.niceColor : s.naughtyColor);
    ctx.fillStyle = isNice ? '#001400' : '#140000';
    ctx.fillRect(0,0,w,h);
    const word  = isNice ? 'NICE' : 'NAUGHTY';
    // pick largest scale that fits
    for (const sc of [2,1]) {
        const tw = textW(word, sc);
        if (tw <= w) {
            drawText(ctx, word, Math.max(0,Math.floor((w-tw)/2)), Math.floor((h-5*sc)/2), color, sc);
            break;
        }
    }
    ctx.fillStyle = '#f8c800';
    [[1,1],[w-2,1],[1,h-2],[w-2,h-2]].forEach(([x,y]) => ctx.fillRect(x,y,1,1));
}

function renderNames(names, listType) {
    const s      = getSettings();
    const isNice = listType === 'nice';
    const color  = hexToRgb(isNice ? s.niceColor : s.naughtyColor);
    const dim    = dimRgb(color);
    const scale  = s.fontScale;
    const lineH  = 5*scale + scale + 2;
    const [w, h] = [96, 128];
    const off    = document.createElement('canvas');
    off.width = w; off.height = h;
    const ctx = off.getContext('2d');
    ctx.fillStyle = isNice ? '#001200' : '#120000';
    ctx.fillRect(0,0,w,h);
    names.forEach((name, i) => {
        const y = i * lineH;
        if (y > h) return;
        drawText(ctx, name, 3, y, i%2===0 ? color : dim, scale);
    });
    return off;
}

function drawPreviews() {
    const rawNames = document.getElementById('preview-names').value
        .split(',').map(s => s.trim()).filter(Boolean);
    const half = Math.ceil(rawNames.length / 2);
    const niceNames    = rawNames.slice(0, half);
    const naughtyNames = rawNames.slice(half);

    ['nice','naughty'].forEach(type => {
        renderHeader('cv-hdr-' + type, type);
        const composite = renderNames(type === 'nice' ? niceNames : naughtyNames, type);
        for (let col=1; col<=3; col++) {
            for (let row=1; row<=2; row++) {
                const cv = document.getElementById(`cv-${type}-c${col}r${row}`);
                if (!cv) continue;
                const ctx = cv.getContext('2d');
                ctx.clearRect(0,0,32,64);
                ctx.drawImage(composite, (col-1)*32, (row-1)*64, 32, 64, 0, 0, 32, 64);
            }
        }
    });
}

document.getElementById('btn-refresh-preview')?.addEventListener('click', drawPreviews);
['inp-nice-color','inp-naughty-color','inp-font-scale'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', () => {
        if (document.getElementById('tab-preview')?.classList.contains('active')) drawPreviews();
    });
});

// Initial preview draw if on that tab
if (document.getElementById('tab-preview')?.classList.contains('active')) drawPreviews();
</script>
</body>
</html>
