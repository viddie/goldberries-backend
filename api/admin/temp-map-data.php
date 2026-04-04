<?php

require_once('../api_bootstrap.inc.php');
require_once(__DIR__ . '/process_functions.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_role($account, $HELPER);

$gamebanana_url = $_REQUEST['gamebanana_url'] ?? null;
$gb_info = parse_gamebanana_url($gamebanana_url);
if ($gb_info === null) {
  die_json(400, "Missing or invalid 'gamebanana_url' parameter. Expected format: https://gamebanana.com/mods/<id> or https://gamebanana.com/wips/<id>");
}

$dir_key = gamebanana_dir_key($gb_info['category'], $gb_info['id']);

$bin_path = $_REQUEST['bin_path'] ?? null;
if ($bin_path === null || $bin_path === '') {
  die_json(400, "Missing or invalid 'bin_path' parameter");
}
$hash = substr(md5($bin_path), 0, 12);

$check_exists = ($_REQUEST['check_exists'] ?? 'false') === 'true';

$json_path = GB_ROOT_LOCAL . "/cache/campaign_data_temp/{$dir_key}/{$hash}.json";
if (!file_exists($json_path)) {
  die_json(404, "Temporary map data not found");
}

if ($check_exists) {
  api_write(['exists' => true]);
  exit;
}

$data = file_get_contents($json_path);
if ($data === false) {
  die_json(500, "Failed to read map data");
}

header('Content-Type: application/json; charset=utf-8');
echo $data;
exit;
