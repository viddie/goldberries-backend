<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $id = $_REQUEST['id'] ?? null;
  $hash = $_REQUEST['hash'] ?? null;

  if ($id !== null && is_numeric($id)) {
    // Lookup by map ID
    $id = intval($id);
    $campaign_id = $_REQUEST['campaign_id'] ?? null;

    if ($campaign_id !== null) {
      if (!is_numeric($campaign_id)) {
        die_json(400, "Invalid 'campaign_id' parameter");
      }
      $campaign_id = intval($campaign_id);
    } else {
      $map = Map::get_by_id($DB, $id, 0, false);
      if ($map === null) {
        die_json(404, "Map not found");
      }
      $campaign_id = $map->campaign_id;
    }

    $file_key = strval($id);
  } elseif ($hash !== null && preg_match('/^[a-f0-9]+$/i', $hash)) {
    // Lookup by hash (unmatched bin)
    $campaign_id = $_REQUEST['campaign_id'] ?? null;
    if ($campaign_id === null || !is_numeric($campaign_id)) {
      die_json(400, "'campaign_id' is required when using 'hash' parameter");
    }
    $campaign_id = intval($campaign_id);
    $file_key = $hash;
  } else {
    die_json(400, "Missing or invalid 'id' or 'hash' parameter");
  }

  $check_exists = ($_REQUEST['check_exists'] ?? 'false') === 'true';

  $json_path = GB_ROOT_LOCAL . "/cache/campaign_data/{$campaign_id}/{$file_key}.json";
  if (!file_exists($json_path)) {
    die_json(404, "Map data not found");
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
}
#endregion

#region POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $account = get_user_data();
  check_role($account, $HELPER);

  $id = $_REQUEST['id'] ?? null;
  if ($id === null || !is_numeric($id)) {
    die_json(400, "Missing or invalid 'id' parameter");
  }
  $id = intval($id);

  $map = Map::get_by_id($DB, $id, 0, false);
  if ($map === null) {
    die_json(404, "Map not found");
  }

  $campaign_id = $map->campaign_id;
  $cache_dir = GB_ROOT_LOCAL . "/cache/campaign_data/{$campaign_id}";
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

  $dest = "{$cache_dir}/{$id}.json";
  file_put_contents($dest, $body);

  api_write(['success' => true, 'map_id' => $id, 'campaign_id' => $campaign_id]);
}
#endregion

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  die_json(405, 'Method Not Allowed');
}
