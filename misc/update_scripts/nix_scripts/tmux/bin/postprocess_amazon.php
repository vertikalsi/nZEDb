<?php
require_once(dirname(__FILE__)."/../../../config.php");
require_once(WWW_DIR."lib/postprocess.php");

if (isset($argv[1]) && is_numeric($argv[1]))
{
	sleep($argv[1] - 1);
	$postprocess = new PostProcess(true);
	$postprocess->processBooks($argv[1]);
	$postprocess->processMusic($argv[1]);
	$postprocess->processGames($argv[1]);
}
