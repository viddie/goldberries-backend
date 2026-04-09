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
$where_cond = count($where) > 0 ? "AND " . implode(" AND ", $where) : "";

$interval = isset($_GET['interval']) ? $_GET['interval'] : "all";
//Acceptable values: minute, hour, day, month, all (no interval)
if (!in_array($interval, ['minute', 'hour', 'day', 'month', 'all'])) {
  die_json(400, "Invalid interval value");
}
$select_interval_str = $interval === "all" ? "" : "date_trunc('$interval', \"date\" AT TIME ZONE 'UTC') as interval_date, ";
$group_by_interval_str = $interval === "all" ? "" : "GROUP BY interval_date";
$group_by_interval_pre = $interval === "all" ? "" : "interval_date, ";
$order_by_interval_str = $interval === "all" ? "" : "ORDER BY interval_date DESC";
$order_by_interval_pre = $interval === "all" ? "" : "interval_date DESC, ";

// Determine if the query range overlaps with aggregated-only data
// The cutoff is the date of the oldest raw traffic entry — anything before that is aggregate-only
$cutoff_result = pg_query_params_or_die($DB, "SELECT MIN(\"date\")::date AS cutoff FROM traffic", [], "Failed to get traffic cutoff");
$cutoff_row = pg_fetch_assoc($cutoff_result);
$raw_cutoff_date = $cutoff_row['cutoff']; // null if traffic table is empty

// Determine if the requested range extends into aggregate-only territory
$needs_aggregate = false;
if ($raw_cutoff_date !== null) {
  if ($start_date === null) {
    // No start date = all time, check if aggregate data exists
    $agg_check = pg_query_params_or_die($DB, "SELECT EXISTS(SELECT 1 FROM traffic_agg) AS has_agg", []);
    $needs_aggregate = pg_fetch_assoc($agg_check)['has_agg'] === 't';
  } else {
    $needs_aggregate = substr($start_date, 0, 10) < $raw_cutoff_date;
  }
} else {
  // No raw data at all — use aggregate only
  $needs_aggregate = true;
}

// For minute/hour intervals, aggregate data cannot provide that granularity
$agg_interval_incompatible = in_array($interval, ['minute', 'hour']);

// Build aggregate WHERE clause (date-based)
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
    // Entire range is in aggregate-only territory
    $agg_where[] = "day <= '{$end_day}'";
  }
}
$agg_where_str = count($agg_where) > 0 ? "WHERE " . implode(" AND ", $agg_where) : "";

$response = [];

//===== 1. USER AGENTS =====
if ($needs_aggregate && $agg_interval_incompatible) {
  // Cannot provide per-interval UA data from aggregates at minute/hour granularity
  // Fall back to raw-only data (which won't cover the full range)
  $query = "SELECT $select_interval_str user_agent, COUNT(*) as count FROM traffic $where_str GROUP BY $group_by_interval_pre user_agent ORDER BY $order_by_interval_pre count DESC";
  $result = pg_query_params_or_die($DB, $query, []);
  $response['user_agents'] = unpack_all_interval_response($interval, $result, false, "user_agent", "count");
  $response['user_agents_partial'] = true;
} else if ($needs_aggregate && $interval === "all") {
  // Combine: aggregate UA counts + raw UA counts into one result
  $response['user_agents'] = get_combined_ua_all($DB, $where_str, $agg_where_str);
} else if ($needs_aggregate) {
  // day/month interval — combine aggregate + raw with matching intervals
  $response['user_agents'] = get_combined_ua_interval($DB, $interval, $where_str, $agg_where_str);
} else {
  $query = "SELECT $select_interval_str user_agent, COUNT(*) as count FROM traffic $where_str GROUP BY $group_by_interval_pre user_agent ORDER BY $order_by_interval_pre count DESC";
  $result = pg_query_params_or_die($DB, $query, []);
  $response['user_agents'] = unpack_all_interval_response($interval, $result, false, "user_agent", "count");
}

//===== 2. MOBILE VS. DESKTOP =====
//Commented out, because currently not in use and it's a very expensive query (2 seconds with 2M rows)
// $response["device_usage"] = ...;

//===== 3. REFERRERS =====
if ($needs_aggregate) {
  // Referrer data is not available in aggregates — only return raw data
  $query = "SELECT
    referrer,
    COUNT(*) AS count
  FROM traffic
    WHERE (referrer IS NULL OR (referrer NOT ILIKE '/%' AND referrer <> 'node' AND page NOT ILIKE '/post-oauth%' AND page <>'/api/auth/discord_auth.php')) $where_cond
  GROUP BY referrer
  ORDER BY count DESC
  ";
  $result = pg_query_params_or_die($DB, $query, []);
  $response['referrers'] = unpack_all_interval_response("all", $result, false, "referrer", "count");
  $response['referrers_partial'] = true;
} else {
  $query = "SELECT
    referrer,
    COUNT(*) AS count
  FROM traffic
    WHERE (referrer IS NULL OR (referrer NOT ILIKE '/%' AND referrer <> 'node' AND page NOT ILIKE '/post-oauth%' AND page <>'/api/auth/discord_auth.php')) $where_cond
  GROUP BY referrer
  ORDER BY count DESC
  ";
  $result = pg_query_params_or_die($DB, $query, []);
  $response['referrers'] = unpack_all_interval_response("all", $result, false, "referrer", "count");
}

//===== 4. SIMPLE FIELDS =====
if ($needs_aggregate && $agg_interval_incompatible) {
  // minute/hour interval — can only return raw data
  $query = "SELECT
    $select_interval_str
    ROUND(AVG(serve_time)) AS avg_serve_time,
    COUNT(*) AS total_requests,
    COUNT(*) filter (WHERE referrer IS NULL) AS total_new_requests
  FROM traffic
  $where_str
  $group_by_interval_str
  $order_by_interval_str
  ";
  $result = pg_query_params_or_die($DB, $query, []);
  $response["basic"] = unpack_all_interval_response($interval, $result, true);
  $response['basic_partial'] = true;
} else if ($needs_aggregate && $interval === "all") {
  $response["basic"] = get_combined_basic_all($DB, $where_str, $agg_where_str);
} else if ($needs_aggregate) {
  $response["basic"] = get_combined_basic_interval($DB, $interval, $where_str, $agg_where_str);
} else {
  $query = "SELECT
    $select_interval_str
    ROUND(AVG(serve_time)) AS avg_serve_time,
    COUNT(*) AS total_requests,
    COUNT(*) filter (WHERE referrer IS NULL) AS total_new_requests
  FROM traffic
  $where_str
  $group_by_interval_str
  $order_by_interval_str
  ";
  $result = pg_query_params_or_die($DB, $query, []);
  $response["basic"] = unpack_all_interval_response($interval, $result, true);
}

if ($needs_aggregate) {
  $response['aggregate_cutoff'] = $raw_cutoff_date;
}

api_write($response, true);
#endregion

#region Aggregate Helper Functions

function get_combined_ua_all($DB, $raw_where_str, $agg_where_str)
{
  // Get raw UA counts
  $raw_result = pg_query_params_or_die(
    $DB,
    "SELECT user_agent, COUNT(*) as count FROM traffic $raw_where_str GROUP BY user_agent ORDER BY count DESC",
    []
  );
  $ua_map = [];
  while ($row = pg_fetch_assoc($raw_result)) {
    $key = $row['user_agent'] ?? 'null';
    $ua_map[$key] = intval($row['count']);
  }

  // Get aggregate UA counts from JSONB
  $agg_result = pg_query_params_or_die(
    $DB,
    "SELECT key AS user_agent, SUM(value::bigint) AS count
     FROM traffic_agg, jsonb_each_text(ua_counts)
     $agg_where_str
     GROUP BY key",
    []
  );
  while ($row = pg_fetch_assoc($agg_result)) {
    $key = $row['user_agent'] ?? 'null';
    $ua_map[$key] = ($ua_map[$key] ?? 0) + intval($row['count']);
  }

  // Sort descending and format
  arsort($ua_map);
  $result = [];
  foreach ($ua_map as $ua => $count) {
    $result[] = ['user_agent' => $ua === 'null' ? null : $ua, 'count' => strval($count)];
  }
  return $result;
}

function get_combined_ua_interval($DB, $interval, $raw_where_str, $agg_where_str)
{
  // Get raw UA counts with interval
  $raw_result = pg_query_params_or_die(
    $DB,
    "SELECT date_trunc('$interval', \"date\" AT TIME ZONE 'UTC') as interval_date, user_agent, COUNT(*) as count
     FROM traffic $raw_where_str GROUP BY interval_date, user_agent ORDER BY interval_date DESC, count DESC",
    []
  );
  $raw_data = unpack_all_interval_response($interval, $raw_result, false, "user_agent", "count");

  // Get aggregate UA counts with interval
  $agg_select_interval = "date_trunc('$interval', day) as interval_date";
  $agg_result = pg_query_params_or_die(
    $DB,
    "SELECT $agg_select_interval, key AS user_agent, SUM(value::bigint) AS count
     FROM traffic_agg, jsonb_each_text(ua_counts)
     $agg_where_str
     GROUP BY interval_date, key
     ORDER BY interval_date DESC, count DESC",
    []
  );
  $agg_data = unpack_all_interval_response($interval, $agg_result, false, "user_agent", "count");

  return merge_interval_data_kv($raw_data, $agg_data);
}

function get_combined_basic_all($DB, $raw_where_str, $agg_where_str)
{
  // Get raw basic stats
  $raw_result = pg_query_params_or_die(
    $DB,
    "SELECT ROUND(AVG(serve_time)) AS avg_serve_time, COUNT(*) AS total_requests,
            COUNT(*) FILTER (WHERE referrer IS NULL) AS total_new_requests
     FROM traffic $raw_where_str",
    []
  );
  $raw = pg_fetch_assoc($raw_result);

  // Get aggregate basic stats
  $agg_result = pg_query_params_or_die(
    $DB,
    "SELECT ROUND(SUM(request_count * avg_serve_time)::numeric / NULLIF(SUM(request_count), 0)) AS avg_serve_time,
            SUM(request_count) AS total_requests,
            SUM(new_request_count) AS total_new_requests
     FROM traffic_agg $agg_where_str",
    []
  );
  $agg = pg_fetch_assoc($agg_result);

  // Combine: weighted average for serve time, sum for counts
  $raw_total = intval($raw['total_requests'] ?? 0);
  $agg_total = intval($agg['total_requests'] ?? 0);
  $combined_total = $raw_total + $agg_total;

  $combined_avg = 0;
  if ($combined_total > 0) {
    $raw_avg = intval($raw['avg_serve_time'] ?? 0);
    $agg_avg = intval($agg['avg_serve_time'] ?? 0);
    $combined_avg = round(($raw_avg * $raw_total + $agg_avg * $agg_total) / $combined_total);
  }

  return [
    'avg_serve_time' => strval($combined_avg),
    'total_requests' => strval($combined_total),
    'total_new_requests' => strval(intval($raw['total_new_requests'] ?? 0) + intval($agg['total_new_requests'] ?? 0)),
  ];
}

function get_combined_basic_interval($DB, $interval, $raw_where_str, $agg_where_str)
{
  // Get raw basic stats with interval
  $raw_result = pg_query_params_or_die(
    $DB,
    "SELECT date_trunc('$interval', \"date\" AT TIME ZONE 'UTC') as interval_date,
            ROUND(AVG(serve_time)) AS avg_serve_time, COUNT(*) AS total_requests,
            COUNT(*) FILTER (WHERE referrer IS NULL) AS total_new_requests
     FROM traffic $raw_where_str GROUP BY interval_date ORDER BY interval_date DESC",
    []
  );
  $raw_data = [];
  while ($row = pg_fetch_assoc($raw_result)) {
    $raw_data[$row['interval_date']] = [
      'date' => $row['interval_date'],
      'avg_serve_time' => $row['avg_serve_time'],
      'total_requests' => $row['total_requests'],
      'total_new_requests' => $row['total_new_requests'],
    ];
  }

  // Get aggregate basic stats with interval
  $agg_select_interval = "date_trunc('$interval', day) as interval_date";
  $agg_result = pg_query_params_or_die(
    $DB,
    "SELECT $agg_select_interval,
            ROUND(SUM(request_count * avg_serve_time)::numeric / NULLIF(SUM(request_count), 0)) AS avg_serve_time,
            SUM(request_count) AS total_requests,
            SUM(new_request_count) AS total_new_requests
     FROM traffic_agg $agg_where_str GROUP BY interval_date ORDER BY interval_date DESC",
    []
  );
  while ($row = pg_fetch_assoc($agg_result)) {
    $date_key = $row['interval_date'];
    if (isset($raw_data[$date_key])) {
      // Merge: weighted avg for serve_time, sum for counts
      $r = $raw_data[$date_key];
      $raw_total = intval($r['total_requests']);
      $agg_total = intval($row['total_requests']);
      $combined_total = $raw_total + $agg_total;
      $combined_avg = round((intval($r['avg_serve_time']) * $raw_total + intval($row['avg_serve_time']) * $agg_total) / $combined_total);
      $raw_data[$date_key] = [
        'date' => $date_key,
        'avg_serve_time' => strval($combined_avg),
        'total_requests' => strval($combined_total),
        'total_new_requests' => strval(intval($r['total_new_requests']) + intval($row['total_new_requests'])),
      ];
    } else {
      $raw_data[$date_key] = [
        'date' => $date_key,
        'avg_serve_time' => $row['avg_serve_time'],
        'total_requests' => $row['total_requests'],
        'total_new_requests' => $row['total_new_requests'],
      ];
    }
  }

  // Sort by date descending
  krsort($raw_data);
  return array_values($raw_data);
}

function merge_interval_data_kv($raw_data, $agg_data)
{
  // Both are arrays of {date: ..., key1: val1, key2: val2, ...}
  // Merge by date, summing values for matching keys
  $merged = [];
  foreach ($agg_data as $agg_row) {
    $merged[$agg_row['date']] = $agg_row;
  }
  foreach ($raw_data as $raw_row) {
    $date = $raw_row['date'];
    if (isset($merged[$date])) {
      foreach ($raw_row as $key => $value) {
        if ($key === 'date')
          continue;
        $merged[$date][$key] = strval(intval($merged[$date][$key] ?? 0) + intval($value));
      }
    } else {
      $merged[$date] = $raw_row;
    }
  }
  krsort($merged);
  return array_values($merged);
}
#endregion

#region Utility Functions
function unpack_all_interval_response($interval, $result, $assoc = false, $key_key = null, $value_key = null)
{
  if ($interval === "all") {
    if ($assoc) {
      return pg_fetch_assoc($result);
    }
    return pg_fetch_all($result);
  }

  $ret = [];
  while ($row = pg_fetch_assoc($result)) {
    $interval_date = $row['interval_date'];
    unset($row['interval_date']);

    //Problem: if 2 group-by fields are used, the data has to be inserted into the previous row, unless the date changes
    //If only 1 group-by field is used, the data can just be inserted as a new row

    if ($assoc) {
      //1 group by
      $row['date'] = $interval_date;
      $ret[] = $row;
    } else {
      //2 group by's
      //Last row
      $size = count($ret);
      $key = $row[$key_key] ?? "null";
      $value = $row[$value_key];
      if ($size > 0 && $ret[$size - 1]['date'] === $interval_date) {
        $ret[$size - 1][$key] = $value;
      } else {
        $ret[] = [
          "date" => $interval_date,
          $key => $value
        ];
      }
    }
  }
  return $ret;
}
#endregion