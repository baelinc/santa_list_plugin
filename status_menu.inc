<?php
$pages = Array(
	'name' => "Santa's List",
	'type' => "content",
	'page' => "santa_list_ui.php");
?>

<li><a href="<?php echo preg_replace('/.*\/plugins\/(.*)/', 'plugin.php?plugin=$1&page=santa_list_ui.php', dirname(__FILE__)); ?>">Santa's List</a></li>
