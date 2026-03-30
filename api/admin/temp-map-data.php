<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_role($account, $HELPER);

$gamebanana_id = $_REQUEST['gamebanana_id'] ?? null;
if ($gamebanana_id === null || !is_numeric($gamebanana_id)) {
  die_json(400, "Missing or invalid 'gamebanana_id' parameter");
}
$gamebanana_id = intval($gamebanana_id);

$hash = $_REQUEST['hash'] ?? null;
if ($hash === null || !preg_match('/^[a-f0-9]+$/i', $hash)) {
  die_json(400, "Missing or invalid 'hash' parameter");
}

$check_exists = ($_REQUEST['check_exists'] ?? 'false') === 'true';

$json_path = GB_ROOT_LOCAL . "/cache/campaign_data_temp/{$gamebanana_id}/{$hash}.json";
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
