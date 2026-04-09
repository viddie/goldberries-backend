<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_access($account, true);
if (!is_admin($account)) {
  die_json(403, "Forbidden");
}

//Time filters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
$where = [];
if ($start_date !== null) {
  //Validate date to be in ISO format: 2024-10-19T22:00:00.000Z
  if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}.\d{3}Z$/', $start_date)) {
    die_json(400, "Invalid start_date format");
  }
  $where[] = "\"date\" AT TIME ZONE 'UTC' >= '$start_date'";
}
if ($end_date !== null) {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}.\d{3}Z$/', $end_date)) {
    die_json(400, "Invalid end_date format");
  }
  $where[] = "\"date\" AT TIME ZONE 'UTC' <= '$end_date'";
}
$where_str = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

// Determine if the query range extends into aggregate-only territory
$cutoff_result = pg_query_params_or_die($DB, "SELECT MIN(\"date\")::date AS cutoff FROM traffic", [], "Failed to get traffic cutoff");
$cutoff_row = pg_fetch_assoc($cutoff_result);
$raw_cutoff_date = $cutoff_row['cutoff'];

$needs_aggregate = false;
if ($raw_cutoff_date !== null) {
  if ($start_date === null) {
    $agg_check = pg_query_params_or_die($DB, "SELECT EXISTS(SELECT 1 FROM traffic_agg) AS has_agg", []);
    $needs_aggregate = pg_fetch_assoc($agg_check)['has_agg'] === 't';
  } else {
    $needs_aggregate = substr($start_date, 0, 10) < $raw_cutoff_date;
  }
} else {
  $needs_aggregate = true;
}

$response = [];

// last_requests: only available from raw data — return empty if range is entirely in aggregate territory
$entirely_aggregated = false;
if ($raw_cutoff_date === null) {
  $entirely_aggregated = true;
} else if ($end_date !== null && substr($end_date, 0, 10) < $raw_cutoff_date) {
  $entirely_aggregated = true;
}

if ($entirely_aggregated) {
  $response['last_requests'] = [];
  $response['last_requests_unavailable'] = true;
} else {
  $query = "SELECT * FROM traffic $where_str ORDER BY date DESC LIMIT 500";
  $result = pg_query_params_or_die($DB, $query, []);
  $response['last_requests'] = pg_fetch_all($result);
}

// most_requested: combine raw + aggregate data when needed
if ($needs_aggregate) {
  // Build aggregate WHERE clause
  $agg_where = [];
  if ($start_date !== null) {
    $agg_where[] = "day >= '" . substr($start_date, 0, 10) . "'";
  }
  if ($raw_cutoff_date !== null) {
    $agg_where[] = "day < '{$raw_cutoff_date}'";
  }
  if ($end_date !== null) {
    $end_day = substr($end_date, 0, 10);
    if ($raw_cutoff_date !== null && $end_day < $raw_cutoff_date) {
      $agg_where[] = "day <= '{$end_day}'";
    }
  }
  $agg_where_str = count($agg_where) > 0 ? "WHERE " . implode(" AND ", $agg_where) : "";

  // Get raw page counts
  $page_map = []; // page => [count, total_serve_time]
  if (!$entirely_aggregated) {
    $raw_result = pg_query_params_or_die(
      $DB,
      "SELECT page, COUNT(*) AS count, ROUND(AVG(serve_time)) AS avg_serve_time FROM traffic $where_str GROUP BY page",
      []
    );
    while ($row = pg_fetch_assoc($raw_result)) {
      $page_map[$row['page']] = [intval($row['count']), intval($row['avg_serve_time']) * intval($row['count'])];
    }
  }

  // Get aggregate page counts
  $agg_result = pg_query_params_or_die(
    $DB,
    "SELECT page, SUM(request_count) AS count, SUM(request_count * avg_serve_time) AS total_serve_time
     FROM traffic_agg $agg_where_str GROUP BY page",
    []
  );
  while ($row = pg_fetch_assoc($agg_result)) {
    $page = $row['page'];
    $count = intval($row['count']);
    $total_st = intval($row['total_serve_time']);
    if (isset($page_map[$page])) {
      $page_map[$page][0] += $count;
      $page_map[$page][1] += $total_st;
    } else {
      $page_map[$page] = [$count, $total_st];
    }
  }

  // Sort by count descending, take top 100
  uasort($page_map, function ($a, $b) {
    return $b[0] - $a[0]; });
  $most_requested = [];
  $i = 0;
  foreach ($page_map as $page => $data) {
    if ($i >= 100)
      break;
    $most_requested[] = [
      'page' => $page,
      'count' => strval($data[0]),
      'avg_serve_time' => strval($data[0] > 0 ? round($data[1] / $data[0]) : 0),
    ];
    $i++;
  }
  $response['most_requested'] = $most_requested;
} else {
  $query = "SELECT page, COUNT(*) AS count, ROUND(AVG(serve_time)) AS avg_serve_time FROM traffic $where_str GROUP BY page ORDER BY count DESC LIMIT 100";
  $result = pg_query_params_or_die($DB, $query, []);
  $response['most_requested'] = pg_fetch_all($result);
}

if ($needs_aggregate) {
  $response['aggregate_cutoff'] = $raw_cutoff_date;
}

api_write($response, true);
#endregion