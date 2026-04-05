<?php

require_once('../api_bootstrap.inc.php');

$account = get_user_data();
check_role($account, $ADMIN);

$CAMPAIGN_LIMIT = 9999;

$cache_base = GB_ROOT_LOCAL . "/cache/campaign_data";
if (!is_dir($cache_base)) {
  die_json(404, "No campaign data cache directory found");
}

// Scan for numeric subdirectories (campaign IDs)
$campaign_dirs = [];
foreach (scandir($cache_base) as $entry) {
  if ($entry === '.' || $entry === '..')
    continue;
  if (!is_numeric($entry))
    continue;
  $path = "{$cache_base}/{$entry}";
  if (!is_dir($path))
    continue;
  $campaign_dirs[] = ['id' => intval($entry), 'path' => $path];
}

if (count($campaign_dirs) === 0) {
  api_write(['message' => 'No campaign data directories found', 'processed' => 0]);
  exit;
}

// Limit to first N campaigns for testing
$campaign_dirs = array_slice($campaign_dirs, 0, $CAMPAIGN_LIMIT);

$results = [];

foreach ($campaign_dirs as $campaign_entry) {
  $campaign_id = $campaign_entry['id'];
  $cache_dir = $campaign_entry['path'];

  $campaign_result = [
    'campaign_id' => $campaign_id,
    'success' => false,
    'maps_updated' => 0,
    'files_renamed' => 0,
    'mapping_deleted' => false,
    'errors' => [],
  ];

  // Check for index.json
  $index_path = "{$cache_dir}/index.json";
  $mapping_path = "{$cache_dir}/mapping.json";
  $has_index = file_exists($index_path);
  $has_mapping = file_exists($mapping_path);

  if (!$has_index) {
    $campaign_result['errors'][] = 'No index.json found';
    $results[] = $campaign_result;
    continue;
  }

  // Read index.json
  $index_json = json_decode(file_get_contents($index_path), true);
  if (!is_array($index_json) || !isset($index_json['data'])) {
    $campaign_result['errors'][] = 'Invalid index.json format';
    $results[] = $campaign_result;
    continue;
  }

  $index_data = $index_json['data'];
  if (!is_array($index_data)) {
    $campaign_result['errors'][] = 'index.json data is not an array';
    $results[] = $campaign_result;
    continue;
  }

  // Process each entry in the index
  $updated_data = [];
  foreach ($index_data as $entry) {
    $path = $entry['path'] ?? null;
    $map_id = $entry['map_id'] ?? null;
    $name = $entry['name'] ?? null;

    // If this entry has a map_id, update the database and rename the file
    if ($map_id !== null && $path !== null) {
      // Update database: set bin path on the map
      $result = pg_query_params_or_die(
        $DB,
        "UPDATE map SET bin = $1 WHERE id = $2",
        [$path, $map_id],
        "Failed to update map bin path for map {$map_id}"
      );
      $campaign_result['maps_updated']++;

      // Rename {map_id}.json to {hash}.json
      $hash = substr(md5($path), 0, 12);
      $old_file = "{$cache_dir}/{$map_id}.json";
      $new_file = "{$cache_dir}/{$hash}.json";
      if (file_exists($old_file) && $old_file !== $new_file) {
        rename($old_file, $new_file);
        $campaign_result['files_renamed']++;
      }
    }

    // Build cleaned entry: only path and name, no map_id or hash
    $clean_entry = [];
    if ($path !== null) {
      $clean_entry['path'] = $path;
    }
    if ($name !== null) {
      $clean_entry['name'] = $name;
    }
    if (isset($entry['conversion_error'])) {
      $clean_entry['conversion_error'] = true;
    }
    $updated_data[] = $clean_entry;
  }

  // Save updated index.json
  $updated_index = [
    'status' => $index_json['status'] ?? 'ok',
    'message' => $index_json['message'] ?? null,
    'bin_count' => count($updated_data),
    'data' => $updated_data,
  ];
  file_put_contents(
    $index_path,
    json_encode($updated_index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
  );

  // Delete mapping.json if it exists
  if ($has_mapping) {
    unlink($mapping_path);
    $campaign_result['mapping_deleted'] = true;
  }

  $campaign_result['success'] = true;
  $results[] = $campaign_result;
}

api_write([
  'message' => 'Migration complete',
  'processed' => count($results),
  'results' => $results,
]);
