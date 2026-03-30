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
  $json_path = GB_ROOT_LOCAL . "/cache/campaign_data/{$id}/mapping.json";
  if (!file_exists($json_path)) {
    die_json(404, "Mapping data not found");
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
  $decoded = json_decode($body, true);
  if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
    die_json(400, "Invalid JSON in request body");
  }

  // Wrap the mapping data in {data: ...} envelope
  $wrapped = json_encode(
    ['data' => $decoded],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
  );

  $dest = "{$cache_dir}/mapping.json";
  file_put_contents($dest, $wrapped);

  // Update index.json if it exists, re-matching bins to database maps
  $index_update = update_index_matching($DB, $id, $cache_dir);

  $response = ['success' => true, 'campaign_id' => $id, 'file' => 'mapping.json'];
  if ($index_update !== null) {
    $response['index_updated'] = true;
    $response['matched_maps'] = $index_update['matched_maps'];
    if (count($index_update['unmatched_bins']) > 0) {
      $response['unmatched_bins'] = $index_update['unmatched_bins'];
    }
    if (count($index_update['unmatched_maps']) > 0) {
      $response['unmatched_maps'] = $index_update['unmatched_maps'];
    }
  }

  api_write($response);
}
#endregion

#region Update Index Matching
/**
 * Re-runs the bin-to-map matching algorithm on the existing index.json,
 * using the same logic as process-campaign.php:
 *   Priority 1: mapping.json (explicit bin path => map ID)
 *   Priority 2: name matching (English.txt dialog name vs database map name)
 * Renames cached .json data files as needed and saves the updated index.json.
 *
 * @param resource $DB
 * @param int $campaign_id
 * @param string $cache_dir
 * @return array|null Result summary, or null if index.json does not exist
 */
function update_index_matching($DB, $campaign_id, $cache_dir)
{
  $index_path = "{$cache_dir}/index.json";
  if (!file_exists($index_path)) {
    return null;
  }

  $index_json = json_decode(file_get_contents($index_path), true);
  if (!is_array($index_json) || !isset($index_json['data'])) {
    return null;
  }
  $index = $index_json['data'];
  if (!is_array($index)) {
    return null;
  }

  // Read mapping.json
  $mapping_path = "{$cache_dir}/mapping.json";
  $path_mapping = [];
  if (file_exists($mapping_path)) {
    $mapping_content = file_get_contents($mapping_path);
    if ($mapping_content !== false) {
      $mapping_json = json_decode($mapping_content, true) ?? [];
      $path_mapping = $mapping_json['data'] ?? [];
    }
  }

  // Fetch campaign maps from database
  $campaign = Campaign::get_by_id($DB, $campaign_id, 0, false);
  if ($campaign === null) {
    return null;
  }
  $campaign->fetch_maps($DB);

  if ($campaign->maps === null) {
    return null;
  }

  // Build a set of valid campaign map IDs
  $campaign_map_ids = [];
  foreach ($campaign->maps as $map) {
    $campaign_map_ids[$map->id] = true;
  }

  // Record old file keys for each index entry (to rename files later)
  $old_file_keys = [];
  foreach ($index as $i => $entry) {
    if (isset($entry['map_id'])) {
      $old_file_keys[$i] = strval($entry['map_id']);
    } elseif (isset($entry['hash'])) {
      $old_file_keys[$i] = $entry['hash'];
    } else {
      $old_file_keys[$i] = null;
    }
  }

  // Clear old matching fields
  foreach ($index as $i => &$entry) {
    unset($entry['map_id']);
    unset($entry['hash']);
  }
  unset($entry);

  // First pass: apply mapping.json matches
  $matched_bins = [];
  foreach ($index as $i => $entry) {
    $rel_path = $entry['path'];
    if (isset($path_mapping[$rel_path]) && isset($campaign_map_ids[$path_mapping[$rel_path]])) {
      $matched_bins[$i] = intval($path_mapping[$rel_path]);
      $index[$i]['map_id'] = $matched_bins[$i];
    }
  }

  // Collect already-matched map IDs to avoid double-matching
  $used_map_ids = array_flip($matched_bins);

  // Build name lookup for remaining unmatched entries
  $name_to_index = [];
  foreach ($index as $i => $entry) {
    if (!isset($matched_bins[$i]) && isset($entry['name'])) {
      $name_to_index[strtolower(trim($entry['name']))] = $i;
    }
  }

  // Second pass: match by name
  $unmatched_maps = [];
  foreach ($campaign->maps as $map) {
    if (isset($used_map_ids[$map->id])) {
      continue;
    }
    $map_name_lower = strtolower(trim($map->name));
    if (isset($name_to_index[$map_name_lower])) {
      $idx = $name_to_index[$map_name_lower];
      $matched_bins[$idx] = $map->id;
      $index[$idx]['map_id'] = $map->id;
    } else {
      $unmatched_maps[] = ['id' => $map->id, 'name' => $map->name];
    }
  }

  // Generate hashes for unmatched bins
  foreach ($index as $i => &$entry) {
    if (!isset($matched_bins[$i]) && !isset($entry['conversion_error'])) {
      $entry['hash'] = substr(md5($entry['path']), 0, 12);
    }
  }
  unset($entry);

  // Rename cached .json data files where the file key changed.
  // Use a two-phase rename (old → temp, then temp → final) to avoid collisions
  // when one entry's new key equals another entry's old key.
  $renames = []; // [{old_key, new_key}, ...]
  foreach ($index as $i => $entry) {
    if (isset($entry['map_id'])) {
      $new_key = strval($entry['map_id']);
    } elseif (isset($entry['hash'])) {
      $new_key = $entry['hash'];
    } else {
      continue; // conversion_error entries have no file
    }

    $old_key = $old_file_keys[$i] ?? null;
    if ($old_key !== null && $old_key !== $new_key) {
      $renames[] = ['old_key' => $old_key, 'new_key' => $new_key];
    }
  }

  // Phase 1: rename all affected files to unique temp names
  $temp_renames = [];
  foreach ($renames as $r) {
    $old_file = "{$cache_dir}/{$r['old_key']}.json";
    if (file_exists($old_file)) {
      $temp_key = "_tmp_{$r['old_key']}";
      $temp_file = "{$cache_dir}/{$temp_key}.json";
      rename($old_file, $temp_file);
      $temp_renames[] = ['temp_key' => $temp_key, 'new_key' => $r['new_key']];
    }
  }

  // Phase 2: rename temp files to final destinations
  foreach ($temp_renames as $r) {
    $temp_file = "{$cache_dir}/{$r['temp_key']}.json";
    $new_file = "{$cache_dir}/{$r['new_key']}.json";
    rename($temp_file, $new_file);
  }

  // Collect unmatched counts
  $unmatched_bin_count = count($index) - count($matched_bins);
  $unmatched_map_count = count($unmatched_maps);

  // Build and save updated index.json with metadata wrapper
  $index_wrapper = [
    'status' => $index_json['status'] ?? 'ok',
    'message' => $index_json['message'] ?? null,
    'bin_count' => count($index),
    'unmatched_bin_count' => $unmatched_bin_count,
    'unmatched_map_count' => $unmatched_map_count,
    'data' => $index,
  ];
  file_put_contents(
    $index_path,
    json_encode($index_wrapper, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
  );

  // Collect unmatched bins for the response
  $unmatched_bins = [];
  foreach ($index as $i => $entry) {
    if (!isset($matched_bins[$i])) {
      $unmatched_bins[] = $entry;
    }
  }

  return [
    'matched_maps' => count($matched_bins),
    'unmatched_bins' => $unmatched_bins,
    'unmatched_maps' => $unmatched_maps,
  ];
}
#endregion

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  die_json(405, 'Method Not Allowed');
}
