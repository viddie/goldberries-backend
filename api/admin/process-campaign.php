<?php

require_once('../api_bootstrap.inc.php');
require_once(__DIR__ . '/process_functions.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_role($account, $HELPER);

$id = $_REQUEST['id'] ?? null;
if ($id === null) {
  die_json(400, "Missing 'id' parameter");
}

$regenerate = ($_REQUEST['regenerate'] ?? 'false') === 'true';

$id_list = array_map('trim', explode(',', $id));
$results = [];

foreach ($id_list as $id) {
  if (!is_numeric($id)) {
    $results[] = ['id' => $id, 'success' => false, 'error' => "Invalid campaign ID"];
    continue;
  }
  $results[] = process_campaign($DB, intval($id), $regenerate);
}

api_write($results);


#region Process Campaign
/**
 * Processes the data for a single campaign.
 * Downloads mod files from GameBanana, extracts archives, finds English.txt and .bin map files,
 * caches them, and converts .bin files to JSON via maddie480's bin-to-json service.
 * @param resource $DB
 * @param int $id Campaign ID
 * @param bool $regenerate If true, forces re-download of ZIP files even if they already exist
 * @return array Result with 'id', 'success', and optionally 'error' or processing details
 */
function process_campaign($DB, $id, $regenerate = false)
{
  global $MAX_TEMP_MODS_FOLDER_SIZE;

  /** @var Campaign|null $campaign */
  $campaign = Campaign::get_by_id($DB, $id, 0, false);
  if ($campaign === null) {
    return ['id' => $id, 'success' => false, 'error' => "Campaign not found"];
  }

  $mod_id = $campaign->get_gamebanana_mod_id();
  $cache_dir = GB_ROOT_LOCAL . "/cache/campaign_data/{$id}";
  if ($mod_id === null) {
    $error = "No valid GameBanana URL";
    write_error_index($cache_dir, $error);
    return ['id' => $id, 'success' => false, 'error' => $error];
  }

  // Temp dir uses GameBanana mod ID so it's shared with process-gamebanana.php
  $temp_dir = GB_ROOT_LOCAL . "/temp/campaign_data/{$mod_id}";

  // Download and scan mod files (shared pipeline)
  $scan = download_and_scan_mod($mod_id, $temp_dir, $cache_dir, $regenerate);
  if (!$scan['success']) {
    return ['id' => $id, 'success' => false, 'error' => $scan['error']];
  }

  $bin_files = $scan['bin_files'];
  $map_bins = $scan['map_bins'];

  // Read mapping.json from cache dir before wiping (maps bin paths to database map IDs)
  $path_mapping = [];
  $mapping_path = "{$cache_dir}/mapping.json";
  if (file_exists($mapping_path)) {
    $mapping_content = file_get_contents($mapping_path);
    if ($mapping_content !== false) {
      $mapping_json = json_decode($mapping_content, true) ?? [];
      $path_mapping = $mapping_json['data'] ?? [];
    }
  }

  // Fetch all maps in the campaign from the database
  $campaign->fetch_maps($DB);
  $unmatched_maps = [];

  // Match bin files to database map IDs.
  // Priority 1: mapping.json (explicit bin path => map ID mapping)
  // Priority 2: name matching via English.txt dialog names
  $matched_bins = []; // index => map_id

  if ($campaign->maps !== null) {
    // Build a set of map IDs in this campaign for validation
    $campaign_map_ids = [];
    foreach ($campaign->maps as $map) {
      $campaign_map_ids[$map->id] = true;
    }

    // First pass: apply mapping.json matches
    foreach ($map_bins as $i => $entry) {
      $rel_path = $entry['path'];
      if (isset($path_mapping[$rel_path]) && isset($campaign_map_ids[$path_mapping[$rel_path]])) {
        $matched_bins[$i] = intval($path_mapping[$rel_path]);
        $map_bins[$i]['map_id'] = $matched_bins[$i];
      }
    }

    // Collect already-matched map IDs to avoid double-matching
    $used_map_ids = array_flip($matched_bins);

    // Build name lookup for remaining unmatched entries
    $name_to_index = [];
    foreach ($map_bins as $i => $entry) {
      if (!isset($matched_bins[$i]) && isset($entry['name'])) {
        $name_to_index[strtolower(trim($entry['name']))] = $i;
      }
    }

    // Second pass: match by name for maps not yet matched via mapping.json
    foreach ($campaign->maps as $map) {
      if (isset($used_map_ids[$map->id])) {
        continue;
      }
      $map_name_lower = strtolower(trim($map->name));
      if (isset($name_to_index[$map_name_lower])) {
        $idx = $name_to_index[$map_name_lower];
        $matched_bins[$idx] = $map->id;
        $map_bins[$idx]['map_id'] = $map->id;
      } else {
        $unmatched_maps[] = ['id' => $map->id, 'name' => $map->name];
      }
    }
  }

  $matched_count = count($matched_bins);

  // Generate hashes for unmatched bins and store in index
  foreach ($map_bins as $i => &$entry) {
    if (!isset($matched_bins[$i])) {
      $entry['hash'] = substr(md5($entry['path']), 0, 12);
    }
  }
  unset($entry);

  // Build file keys map for bin conversion
  $file_keys = [];
  foreach ($map_bins as $bin_idx => $entry) {
    if (isset($matched_bins[$bin_idx])) {
      $file_keys[$bin_idx] = strval($matched_bins[$bin_idx]);
    } else {
      $file_keys[$bin_idx] = $entry['hash'];
    }
  }

  // Delete previously indexed files before writing new ones
  delete_old_indexed_files($cache_dir);

  // Convert ALL .bin files to JSON and write to cache
  $conversion_errors = convert_bins_to_json($map_bins, $bin_files, $cache_dir, $file_keys);

  // Write index.json with metadata
  $unmatched_map_count = count($unmatched_maps);
  write_index_json($cache_dir, $map_bins, $matched_count, $unmatched_map_count, $conversion_errors);

  // Clean up temp folder if total size exceeds the limit
  cleanup_temp_folder(GB_ROOT_LOCAL . '/temp/campaign_data', $MAX_TEMP_MODS_FOLDER_SIZE);

  $result = [
    'id' => $id,
    'success' => true,
    'name' => $campaign->name,
    'mod_id' => $mod_id,
    'english_txt_found' => $scan['english_txt_found'],
    'bin_count' => count($bin_files),
    'matched_maps' => $matched_count,
  ];

  if (count($conversion_errors) > 0) {
    $result['conversion_errors'] = $conversion_errors;
  }

  // Collect unmatched bins (bins not matched to any database map)
  $unmatched_bins = [];
  foreach ($map_bins as $i => $entry) {
    if (!isset($matched_bins[$i])) {
      $unmatched_bins[] = $entry;
    }
  }
  if (count($unmatched_bins) > 0) {
    $result['unmatched_bins'] = $unmatched_bins;
  }
  if (count($unmatched_maps) > 0) {
    $result['unmatched_maps'] = $unmatched_maps;
  }

  return $result;
}
#endregion