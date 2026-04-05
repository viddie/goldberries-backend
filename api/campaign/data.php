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

#region DELETE Request
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
  $account = get_user_data();
  check_role($account, $HELPER);

  $cache_dir = GB_ROOT_LOCAL . "/cache/campaign_data/{$id}";
  if (!is_dir($cache_dir)) {
    die_json(404, "Campaign data not found");
  }

  // Delete all files in the campaign data directory
  $files = scandir($cache_dir);
  foreach ($files as $file) {
    if ($file === '.' || $file === '..') {
      continue;
    }
    $file_path = "{$cache_dir}/{$file}";
    if (is_file($file_path)) {
      unlink($file_path);
    }
  }

  // Write a new index.json with an error status
  $index = CampaignDataIndex::create_error($cache_dir, 'Campaign data has been removed by a team member');
  $index->save();

  // Unset map.bin for all maps in this campaign
  pg_query_params_or_die(
    $DB,
    "UPDATE map SET bin = NULL WHERE campaign_id = $1",
    [$id],
    "Failed to unset map.bin for campaign"
  );

  api_write(['success' => true, 'campaign_id' => $id]);
}
#endregion

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
  die_json(405, 'Method Not Allowed');
}
