<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

if (!isset($_REQUEST['id'])) {
  die_json(400, "Missing id parameter");
}

$id = intval($_REQUEST['id']);
if ($id == 0) {
  die_json(400, "Invalid player id");
}

#region Count by Difficulty
$query = "SELECT
    difficulty_id,
    COUNT(difficulty_id)
  FROM view_submissions 
  WHERE player_id = $id 
    AND submission_is_verified = true
    AND submission_is_obsolete = false
  GROUP BY difficulty_id";

$result = pg_query_params_or_die($DB, $query);

$response = [
  "count_by_difficulty" => [],
  "total_count" => 0,
  "total_time" => 0,
  "account" => []
];

while ($row = pg_fetch_assoc($result)) {
  $diff_id = intval($row["difficulty_id"]);
  $count = intval($row["count"]);
  $response["count_by_difficulty"][$diff_id] = $count;
  $response["total_count"] += $count;
}
#endregion

#region Total Time
$query = "SELECT
    SUM(submission_time_taken) as total_time
  FROM view_submissions 
  WHERE player_id = $id 
    AND submission_is_verified = true
    AND submission_is_obsolete = false";
$result = pg_query_params_or_die($DB, $query);
if ($row = pg_fetch_assoc($result)) {
  $response["total_time"] = intval($row["total_time"]);
}
#endregion

#region Account Registered
$query = "SELECT
    date_created
  FROM account JOIN player ON player.id = account.player_id
  WHERE player.id = $id";
$result = pg_query_params_or_die($DB, $query);
if ($row = pg_fetch_assoc($result)) {
  $response["account"] = ["date_created" => $row["date_created"]];
}
#endregion

api_write($response);