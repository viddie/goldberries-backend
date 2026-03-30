<?php

require_once('../api_bootstrap.inc.php');
require_once(__DIR__ . '/process_functions.inc.php');

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

$regenerate = ($_REQUEST['regenerate'] ?? 'false') === 'true';

$result = process_gb_campaign($gamebanana_id, $regenerate);

api_write($result);


#region Process GameBanana Mod
/**
 * Processes the data for a GameBanana mod without requiring a database campaign.
 * Used during campaign creation workflow to preview/prepare map data.
 * All bins are hash-keyed (no map matching — that happens after campaign creation).
 *
 * @param int $mod_id GameBanana mod ID
 * @param bool $regenerate If true, forces re-download and wipes previous temp cache
 * @return array Result with 'gamebanana_id', 'success', and processing details
 */
function process_gb_campaign($mod_id, $regenerate = false)
{
  global $MAX_TEMP_MODS_FOLDER_SIZE;

  $temp_dir = GB_ROOT_LOCAL . "/temp/campaign_data/{$mod_id}";
  $cache_dir = GB_ROOT_LOCAL . "/cache/campaign_data_temp/{$mod_id}";

  // If regenerate is requested, wipe the temp cache (processed JSONs) to force full reprocessing
  if ($regenerate && is_dir($cache_dir)) {
    delete_directory_recursive($cache_dir);
  }

  // Download and scan mod files (shared pipeline)
  $scan = download_and_scan_mod($mod_id, $temp_dir, $cache_dir, $regenerate);
  if (!$scan['success']) {
    return ['gamebanana_id' => $mod_id, 'success' => false, 'error' => $scan['error']];
  }

  $bin_files = $scan['bin_files'];
  $map_bins = $scan['map_bins'];

  // No map matching — all bins get hash-based file keys
  foreach ($map_bins as $i => &$entry) {
    $entry['hash'] = substr(md5($entry['path']), 0, 12);
  }
  unset($entry);

  // Build file keys map
  $file_keys = [];
  foreach ($map_bins as $bin_idx => $entry) {
    $file_keys[$bin_idx] = $entry['hash'];
  }

  // Delete previously indexed files before writing new ones
  delete_old_indexed_files($cache_dir);

  // Convert ALL .bin files to JSON and write to temp cache
  $conversion_errors = convert_bins_to_json($map_bins, $bin_files, $cache_dir, $file_keys);

  // Write index.json (no matched maps, no unmatched maps — no campaign context)
  write_index_json($cache_dir, $map_bins, 0, 0, $conversion_errors);

  // Clean up temp folder if total size exceeds the limit
  cleanup_temp_folder(GB_ROOT_LOCAL . '/temp/campaign_data', $MAX_TEMP_MODS_FOLDER_SIZE);

  $result = [
    'gamebanana_id' => $mod_id,
    'success' => true,
    'english_txt_found' => $scan['english_txt_found'],
    'bin_count' => count($bin_files),
  ];

  if (count($conversion_errors) > 0) {
    $result['conversion_errors'] = $conversion_errors;
  }

  $result['bins'] = $map_bins;

  return $result;
}
#endregion
