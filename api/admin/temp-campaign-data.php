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

$json_path = GB_ROOT_LOCAL . "/cache/campaign_data_temp/{$dir_key}/index.json";
if (!file_exists($json_path)) {
  die_json(404, "Temporary campaign data not found for GameBanana URL {$gamebanana_url}");
}

$data = file_get_contents($json_path);
if ($data === false) {
  die_json(500, "Failed to read data");
}

header('Content-Type: application/json; charset=utf-8');
echo $data;
exit;
