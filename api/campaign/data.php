<?php

require_once('../api_bootstrap.inc.php');

#region Parse Parameters
$id = $_REQUEST['id'] ?? null;
if ($id === null || !is_numeric($id)) {
  die_json(400, "Missing or invalid 'id' parameter");
}
$id = intval($id);
#endregion

#region GET Request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $json_path = GB_ROOT_LOCAL . "/cache/campaign_data/{$id}/index.json";
  if (!file_exists($json_path)) {
    die_json(404, "Campaign data not found");
  }

  $data = file_get_contents($json_path);
  if ($data === false) {
    die_json(500, "Failed to read data");
  }

  header('Content-Type: application/json; charset=utf-8');
  echo $data;
  exit;
}
#endregion

#region POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $account = get_user_data();
  check_role($account, $HELPER);

  $campaign = Campaign::get_by_id($DB, $id, 0, false);
  if ($campaign === null) {
    die_json(404, "Campaign not found");
  }

  $cache_dir = GB_ROOT_LOCAL . "/cache/campaign_data/{$id}";
  if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0755, true);
  }

  $body = file_get_contents('php://input');
  if ($body === false || $body === '') {
    die_json(400, "Empty request body");
  }

  // Validate that the body is valid JSON
  $decoded = json_decode($body);
  if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
    die_json(400, "Invalid JSON in request body");
  }

  $dest = "{$cache_dir}/index.json";
  file_put_contents($dest, $body);

  api_write(['success' => true, 'campaign_id' => $id, 'file' => 'index.json']);
}
#endregion

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  die_json(405, 'Method Not Allowed');
}
