<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $id = $_REQUEST['id'] ?? null;
  $bin_path = $_REQUEST['bin_path'] ?? null;
  if ($bin_path !== null && !str_ends_with($bin_path, '.bin')) {
    $bin_path .= '.bin';
  }

  if ($id !== null && is_numeric($id)) {
    // Lookup by map ID — derive file hash from map.bin
    $id = intval($id);
    $campaign_id = $_REQUEST['campaign_id'] ?? null;

    $map = Map::get_by_id($DB, $id, 0, false);
    if ($map === null) {
      die_json(404, "Map not found");
    }
    if ($map->bin === null) {
      die_json(404, "Map has no bin data");
    }

    if ($campaign_id !== null) {
      if (!is_numeric($campaign_id)) {
        die_json(400, "Invalid 'campaign_id' parameter");
      }
      $campaign_id = intval($campaign_id);
    } else {
      $campaign_id = $map->campaign_id;
    }

    $file_key = substr(md5($map->bin), 0, 12);
  } elseif ($bin_path !== null && $bin_path !== '') {
    // Lookup by bin path
    $campaign_id = $_REQUEST['campaign_id'] ?? null;

    // Search DB for a map with this bin path
    $result = pg_query_params_or_die(
      $DB,
      "SELECT * FROM map WHERE bin = $1",
      [$bin_path],
      "Failed to search maps by bin path"
    );
    $rows = pg_fetch_all($result) ?: [];

    if (count($rows) > 1 && ($campaign_id === null || !is_numeric($campaign_id))) {
      die_json(400, "Multiple maps found with this bin path. Provide 'campaign_id' to disambiguate.");
    }

    if (count($rows) === 1) {
      $map = new Map();
      $map->apply_db_data($rows[0]);
      $campaign_id = $campaign_id !== null ? intval($campaign_id) : $map->campaign_id;
    } elseif (count($rows) > 1) {
      $campaign_id = intval($campaign_id);
      $found = false;
      foreach ($rows as $row) {
        if (intval($row['campaign_id']) === $campaign_id) {
          $found = true;
          break;
        }
      }
      if (!$found) {
        die_json(404, "No map with this bin path found in the specified campaign");
      }
    } else {
      // No map found in DB — try hash-based file lookup if campaign_id provided
      if ($campaign_id === null || !is_numeric($campaign_id)) {
        die_json(404, "Map data not found");
      }
      $campaign_id = intval($campaign_id);
    }

    $file_key = substr(md5($bin_path), 0, 12);
  } else {
    die_json(400, "Missing or invalid 'id' or 'bin_path' parameter");
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
  $bin_path = $_REQUEST['bin_path'] ?? null;
  if ($bin_path !== null && !str_ends_with($bin_path, '.bin')) {
    $bin_path .= '.bin';
  }

  if ($id !== null && is_numeric($id)) {
    // Upload by map ID — derive file hash from map.bin
    $id = intval($id);

    $map = Map::get_by_id($DB, $id, 0, false);
    if ($map === null) {
      die_json(404, "Map not found");
    }
    if ($map->bin === null) {
      die_json(400, "Map has no bin path assigned");
    }

    $campaign_id = $map->campaign_id;
    $file_key = substr(md5($map->bin), 0, 12);
    $bin_path = $map->bin;
  } elseif ($bin_path !== null && $bin_path !== '') {
    // Upload by bin path
    $campaign_id = $_REQUEST['campaign_id'] ?? null;
    if ($campaign_id === null || !is_numeric($campaign_id)) {
      die_json(400, "'campaign_id' is required when using 'bin_path' parameter");
    }
    $campaign_id = intval($campaign_id);

    $file_key = substr(md5($bin_path), 0, 12);
  } else {
    die_json(400, "Missing or invalid 'id' or 'bin_path' parameter");
  }

  $cache_dir = GB_ROOT_LOCAL . "/cache/campaign_data/{$campaign_id}";
  if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0755, true);
  }

  $body = file_get_contents('php://input');
  if ($body === false || $body === '') {
    die_json(400, "Empty request body");
  }

  // Detect if the body is a .bin file (binary) or JSON
  $decoded = json_decode($body);
  if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
    // Not valid JSON — treat as raw .bin data and convert via maddie480's service
    require_once(__DIR__ . '/../admin/process_functions.inc.php');
    $json_result = post_bin_to_json($body);
    if ($json_result === false) {
      die_json(502, "Failed to convert .bin file to JSON");
    }

    // Inject BinPath attribute into the converted data
    $parsed = json_decode($json_result, true);
    if ($parsed !== null && isset($parsed['attributes'])) {
      $parsed['attributes']['BinPath'] = $bin_path;
      $body = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
      $body = $json_result;
    }
  }

  $dest = "{$cache_dir}/{$file_key}.json";
  $is_new = !file_exists($dest);
  file_put_contents($dest, $body);

  // If this is a new file, add an entry to index.json
  if ($is_new) {
    $index = CampaignDataIndex::load($cache_dir);
    if ($index !== null) {
      $entry = ['path' => $bin_path];
      if ($id !== null) {
        $entry['name'] = $map->name;
      }
      $index->add_entry($entry);
      $index->save();
    }
  }

  api_write(['success' => true, 'file_key' => $file_key, 'campaign_id' => $campaign_id]);
}
#endregion

#region DELETE Request
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
  $account = get_user_data();
  check_role($account, $HELPER);

  $id = $_REQUEST['id'] ?? null;
  $bin_path = $_REQUEST['bin_path'] ?? null;
  if ($bin_path !== null && !str_ends_with($bin_path, '.bin')) {
    $bin_path .= '.bin';
  }

  if ($id !== null && is_numeric($id)) {
    // Delete by map ID — derive file hash from map.bin
    $id = intval($id);

    $map = Map::get_by_id($DB, $id, 0, false);
    if ($map === null) {
      die_json(404, "Map not found");
    }
    if ($map->bin === null) {
      die_json(404, "Map has no bin data");
    }

    $campaign_id = $_REQUEST['campaign_id'] ?? null;
    if ($campaign_id !== null) {
      if (!is_numeric($campaign_id)) {
        die_json(400, "Invalid 'campaign_id' parameter");
      }
      $campaign_id = intval($campaign_id);
    } else {
      $campaign_id = $map->campaign_id;
    }

    $file_key = substr(md5($map->bin), 0, 12);
    $bin_path = $map->bin;
  } elseif ($bin_path !== null && $bin_path !== '') {
    // Delete by bin path
    $campaign_id = $_REQUEST['campaign_id'] ?? null;
    if ($campaign_id === null || !is_numeric($campaign_id)) {
      die_json(400, "'campaign_id' is required when using 'bin_path' parameter");
    }
    $campaign_id = intval($campaign_id);

    $file_key = substr(md5($bin_path), 0, 12);
  } else {
    die_json(400, "Missing or invalid 'id' or 'bin_path' parameter");
  }

  $json_path = GB_ROOT_LOCAL . "/cache/campaign_data/{$campaign_id}/{$file_key}.json";
  if (!file_exists($json_path)) {
    die_json(404, "Map data not found");
  }

  unlink($json_path);

  // Remove the entry from index.json if it exists
  $cache_dir = GB_ROOT_LOCAL . "/cache/campaign_data/{$campaign_id}";
  $index = CampaignDataIndex::load($cache_dir);
  if ($index !== null) {
    $index->remove_by_path($bin_path);
    $index->save();
  }

  api_write(['success' => true, 'file_key' => $file_key, 'campaign_id' => $campaign_id]);
}
#endregion

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
  die_json(405, 'Method Not Allowed');
}
