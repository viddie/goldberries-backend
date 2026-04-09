<?php

require_once('../api_bootstrap.inc.php');

// CLI-only script for aggregating old traffic data into traffic_agg
$is_debug = getenv('DEBUG') === 'true';

if (!$is_debug) {
  if (php_sapi_name() !== 'cli') {
    die_json(405, 'Method Not Allowed');
  }
}

$cutoff_days = 150;
$cutoff_date = date('Y-m-d', strtotime("-{$cutoff_days} days"));

echo "=== Traffic Data Aggregation ===\n";
echo "Cutoff: {$cutoff_date} ({$cutoff_days} days ago)\n\n";

// Clean traffic entries with NULL user_agent before aggregating
echo "Cleaning traffic entries with NULL user_agent...\n";
$clean_result = pg_query_params_or_die($DB, "DELETE FROM traffic WHERE user_agent IS NULL", [], "Failed to clean traffic entries");
$cleaned = pg_affected_rows($clean_result);
echo "Deleted {$cleaned} entries with NULL user_agent\n\n";

// Count old rows
$check = pg_query_params_or_die(
  $DB,
  "SELECT COUNT(*) AS cnt FROM traffic WHERE \"date\" < \$1::date",
  [$cutoff_date],
  "Failed to count old traffic entries"
);
$old_count = intval(pg_fetch_assoc($check)['cnt']);

if ($old_count === 0) {
  echo "No traffic entries older than {$cutoff_days} days. Nothing to do.\n";
  exit(0);
}

// Get date range
$min_date_result = pg_query_params_or_die(
  $DB,
  "SELECT MIN(\"date\"::date) AS min_date FROM traffic WHERE \"date\" < \$1::date",
  [$cutoff_date],
  "Failed to get min date"
);
$min_date = pg_fetch_assoc($min_date_result)['min_date'];

// Calculate total days to process
$total_days = intval((strtotime($cutoff_date) - strtotime($min_date)) / 86400);

echo "Raw rows to aggregate: {$old_count}\n";
echo "Date range: {$min_date} -> {$cutoff_date} ({$total_days} days)\n\n";

$current_date = $min_date;
$days_processed = 0;
$total_aggregated = 0;
$start_time = microtime(true);

while ($current_date < $cutoff_date) {
  $next_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
  $days_processed++;

  $query = "
    INSERT INTO traffic_agg (day, page, request_count, new_request_count, error_count, avg_serve_time, max_serve_time, ua_counts)
    SELECT
      \$1::date AS day,
      base.page,
      base.request_count,
      base.new_request_count,
      base.error_count,
      base.avg_serve_time,
      base.max_serve_time,
      COALESCE(ua.ua_counts, '{}'::jsonb) AS ua_counts
    FROM (
      SELECT
        page,
        COUNT(*) AS request_count,
        COUNT(*) FILTER (WHERE referrer IS NULL) AS new_request_count,
        COUNT(*) FILTER (WHERE status >= 400) AS error_count,
        ROUND(AVG(serve_time))::integer AS avg_serve_time,
        MAX(serve_time) AS max_serve_time
      FROM traffic
      WHERE \"date\" >= \$1::date AND \"date\" < \$2::date
      GROUP BY page
    ) base
    LEFT JOIN (
      SELECT page, jsonb_object_agg(COALESCE(user_agent, 'unknown'), cnt) AS ua_counts
      FROM (
        SELECT page, user_agent, COUNT(*)::text AS cnt
        FROM traffic
        WHERE \"date\" >= \$1::date AND \"date\" < \$2::date
        GROUP BY page, user_agent
      ) ua_sub
      GROUP BY page
    ) ua ON ua.page = base.page
    ON CONFLICT (day, page) DO UPDATE SET
      request_count = EXCLUDED.request_count,
      new_request_count = EXCLUDED.new_request_count,
      error_count = EXCLUDED.error_count,
      avg_serve_time = EXCLUDED.avg_serve_time,
      max_serve_time = EXCLUDED.max_serve_time,
      ua_counts = EXCLUDED.ua_counts
  ";
  $result = pg_query_params_or_die($DB, $query, [$current_date, $next_date], "Failed to aggregate traffic for {$current_date}");
  $day_rows = pg_affected_rows($result);
  $total_aggregated += $day_rows;

  $elapsed = microtime(true) - $start_time;
  $avg_per_day = $elapsed / $days_processed;
  $remaining_days = $total_days - $days_processed;
  $eta_seconds = intval($remaining_days * $avg_per_day);
  $eta_str = gmdate('H:i:s', $eta_seconds);

  echo "[{$days_processed}/{$total_days}] {$current_date}: {$day_rows} agg rows (ETA: {$eta_str})\n";

  $current_date = $next_date;
}

$agg_elapsed = round(microtime(true) - $start_time, 1);
echo "\nAggregation complete: {$total_aggregated} rows in {$agg_elapsed}s\n";

// Delete old raw traffic data
echo "Deleting raw traffic older than {$cutoff_date}...\n";
$delete_result = pg_query_params_or_die(
  $DB,
  "DELETE FROM traffic WHERE \"date\" < \$1::date",
  [$cutoff_date],
  "Failed to delete old traffic entries"
);
$deleted = pg_affected_rows($delete_result);

$total_elapsed = round(microtime(true) - $start_time, 1);
echo "Deleted {$deleted} raw rows\n";
echo "\n=== Done in {$total_elapsed}s ===\n";
echo "Aggregated: {$total_aggregated} rows\n";
echo "Deleted:    {$deleted} raw rows\n";

log_info("Aggregated {$total_aggregated} traffic_agg rows from {$deleted} raw rows (cutoff: {$cutoff_date})", "Traffic");
