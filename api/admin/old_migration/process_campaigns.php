<?php

require_once('../api_bootstrap.inc.php');
require_once(__DIR__ . '/process_functions.inc.php');

// This file is run from the command line, so no $_SERVER variable checks are possible.
// Instead, check if the script is being run from the command line.

$is_debug = getenv('DEBUG') === 'true';

if (!$is_debug) {
  if (php_sapi_name() !== 'cli') {
    die_json(405, 'Method Not Allowed');
  }
}

header('Content-Type: text/plain');

$start_time = time();
$time_limit = 60 * 30; // in seconds (kept low for testing)
$sleep = 5;

// Fetch all campaigns ordered by ID
$result = pg_query_params_or_die($DB, "SELECT id, name FROM campaign ORDER BY id ASC", [], "Failed to fetch campaigns");
$campaigns = [];
while ($row = pg_fetch_assoc($result)) {
  $campaigns[] = $row;
}
$total_campaigns = count($campaigns);

$cache_base = GB_ROOT_LOCAL . '/cache/campaign_data';

// Count how many already have cache
$already_cached = 0;
foreach ($campaigns as $campaign) {
  if (is_dir("{$cache_base}/" . intval($campaign['id']))) {
    $already_cached++;
  }
}
$to_process = $total_campaigns - $already_cached;

echo "=== Mass Campaign Processing ===\n";
echo "Total campaigns: {$total_campaigns}\n";
echo "Already cached:  {$already_cached}\n";
echo "To process:      {$to_process}\n";
echo "Time limit:      {$time_limit}s\n";
echo "\n";

if ($to_process === 0) {
  echo "Nothing to process. All campaigns already have cached data.\n";
  exit(0);
}

$processed = 0;
$successes = 0;
$failures = 0;
$stopped_by_time_limit = false;

foreach ($campaigns as $campaign) {
  $id = intval($campaign['id']);
  $name = $campaign['name'];
  $dir_path = "{$cache_base}/{$id}";

  // Skip campaigns that already have a cache folder
  if (is_dir($dir_path)) {
    continue;
  }

  $processed++;
  $camp_start = time();
  echo "[{$processed}/{$to_process}] Processing campaign #{$id}: {$name}...\n";

  $camp_result = process_campaign($DB, $id);

  $camp_elapsed = time() - $camp_start;

  if ($camp_result['success']) {
    $successes++;
    $bin_count = $camp_result['bin_count'] ?? '?';
    $matched = $camp_result['matched_maps'] ?? '?';
    $errors = isset($camp_result['conversion_errors']) ? count($camp_result['conversion_errors']) : 0;
    echo "  OK ({$camp_elapsed}s) - bins: {$bin_count}, matched maps: {$matched}";
    if ($errors > 0) {
      echo ", conversion errors: {$errors}";
    }
    echo "\n";
  } else {
    $failures++;
    $error = $camp_result['error'] ?? 'Unknown error';
    echo "  FAILED ({$camp_elapsed}s) - {$error}\n";
  }

  // Check time limit after each processing
  $total_elapsed = time() - $start_time;
  if ($total_elapsed >= $time_limit) {
    $stopped_by_time_limit = true;
    echo "\nTime limit reached ({$time_limit}s). Stopping.\n";
    break;
  }

  echo "  Sleeping for {$sleep}s...\n";
  sleep($sleep);
}

// Count remaining unprocessed campaigns
$remaining = 0;
foreach ($campaigns as $campaign) {
  $id = intval($campaign['id']);
  if (!is_dir("{$cache_base}/{$id}")) {
    $remaining++;
  }
}

$total_elapsed = time() - $start_time;
echo "\n=== Summary ===\n";
echo "Processed: {$processed}\n";
echo "Successes: {$successes}\n";
echo "Failures:  {$failures}\n";
echo "Remaining: {$remaining}\n";
echo "Elapsed:   {$total_elapsed}s\n";
if ($stopped_by_time_limit) {
  echo "Note: Stopped early due to time limit. Run again to continue.\n";
}
