<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Invalid request method');
}

// Check for grouping parameter
$group_by = isset($_REQUEST['group_by']) ? $_REQUEST['group_by'] : null;
$valid_group_by = ['country', 'input_method'];
if ($group_by !== null && !in_array($group_by, $valid_group_by)) {
  die_json(400, "Invalid group_by parameter. Valid values: " . implode(', ', $valid_group_by));
}

$date_start = isset($_REQUEST['date_start']) ? $_REQUEST['date_start'] : null;
$date_end = isset($_REQUEST['date_end']) ? $_REQUEST['date_end'] : null;

$submission_filters = [
  "submission.is_verified = TRUE",
  "challenge.is_rejected = FALSE",
  "submission.is_obsolete = FALSE",
];

if ($date_start !== null) {
  // Validate date to be in format: 2024-10-19
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) {
    die_json(400, "Invalid date_start format. Expected yyyy-mm-dd");
  }
  $submission_filters[] = "(submission.date_achieved AT TIME ZONE 'UTC')::date >= '$date_start'";
}

if ($date_end !== null) {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end)) {
    die_json(400, "Invalid date_end format. Expected yyyy-mm-dd");
  }
  $submission_filters[] = "(submission.date_achieved AT TIME ZONE 'UTC')::date <= '$date_end'";
}

$submission_filter_string = implode(' AND ', $submission_filters);

//Fetch all difficulties first
$query = "SELECT * FROM difficulty WHERE id NOT IN ($TRIVIAL_ID, $UNDETERMINED_ID) ORDER BY sort DESC";
$result = pg_query_params_or_die($DB, $query);


$difficulties = [];
while ($row = pg_fetch_assoc($result)) {
  $difficulty = new Difficulty();
  $difficulty->apply_db_data($row, '');
  $difficulties[] = $difficulty;
}

$selects = [];
foreach ($difficulties as $difficulty) {
  $selects[] = "COUNT(submission.id) FILTER (WHERE difficulty.name = '$difficulty->name') AS t$difficulty->sort";
}
$selects_str = implode(', ', $selects);


if ($group_by === null) {
  // Original behavior: group by player
  $query = "SELECT
      player.id,
      player.name,
      account.role AS account_role,
      account.name_color_start AS account_name_color_start,
      account.name_color_end AS account_name_color_end,
      account.input_method AS account_input_method,
      $selects_str,
      COUNT(submission.id) AS total
    FROM player
    LEFT JOIN submission ON submission.player_id = player.id
    LEFT JOIN account ON player.id = account.player_id
    LEFT JOIN challenge ON submission.challenge_id = challenge.id
    LEFT JOIN objective ON challenge.objective_id = objective.id
    LEFT JOIN difficulty ON challenge.difficulty_id = difficulty.id
    LEFT JOIN map ON challenge.map_id = map.id
    WHERE (submission.id IS NULL OR 
      ($submission_filter_string))
      AND (account.is_suspended = FALSE OR account.is_suspended IS NULL)
    GROUP BY player.id, account.id
    ORDER BY total DESC";
  $result = pg_query($DB, $query);
  if (!$result) {
    die_json(500, "Failed to query database");
  }

  $data = array();
  while ($row = pg_fetch_assoc($result)) {
    $player = new Player();
    $player->apply_db_data($row, '', false);

    $row_data = [];
    $row_data['player'] = $player;
    $row_data['clears'] = [];
    foreach ($difficulties as $difficulty) {
      $row_data['clears'][$difficulty->id] = intval($row["t$difficulty->sort"]);
    }
    $row_data['total'] = intval($row['total']);

    $data[] = $row_data;
  }
} else {
  // Group by country or input_method
  $group_column = $group_by === 'country' ? 'account.country' : 'account.input_method';

  $query = "SELECT
      $group_column AS group_value,
      $selects_str,
      COUNT(submission.id) AS total
    FROM player
    LEFT JOIN account ON player.id = account.player_id
    LEFT JOIN submission ON submission.player_id = player.id
    LEFT JOIN challenge ON submission.challenge_id = challenge.id
    LEFT JOIN objective ON challenge.objective_id = objective.id
    LEFT JOIN difficulty ON challenge.difficulty_id = difficulty.id
    LEFT JOIN map ON challenge.map_id = map.id
    WHERE (submission.id IS NULL OR 
      ($submission_filter_string))
      AND (account.is_suspended = FALSE OR account.is_suspended IS NULL)
    GROUP BY $group_column
    ORDER BY total DESC";
  $result = pg_query($DB, $query);
  if (!$result) {
    die_json(500, "Failed to query database");
  }

  $data = array();
  while ($row = pg_fetch_assoc($result)) {
    $row_data = [];
    $row_data[$group_by] = $row['group_value'];
    $row_data['clears'] = [];
    foreach ($difficulties as $difficulty) {
      $row_data['clears'][$difficulty->id] = intval($row["t$difficulty->sort"]);
    }
    $row_data['total'] = intval($row['total']);

    $data[] = $row_data;
  }
}

api_write($data);
#endregion