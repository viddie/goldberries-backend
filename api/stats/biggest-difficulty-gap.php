<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Invalid request method');
}

// Optional player_id parameter to include specific player's stats
$player_id = isset($_REQUEST['player_id']) ? intval($_REQUEST['player_id']) : null;

// Find players with the biggest difficulty gap between their 1st and 2nd hardest verified submissions
// Query base tables directly instead of the view for better performance
$query = "SELECT
  t.player_id,
  
  MAX(t.submission_id) FILTER (WHERE t.rank = 1) AS first_submission_id,
  MAX(t.submission_id) FILTER (WHERE t.rank = 2) AS second_submission_id,
  
  MAX(t.difficulty_sort) FILTER (WHERE t.rank = 1) AS first_difficulty_sort,
  MAX(t.difficulty_sort) FILTER (WHERE t.rank = 2) AS second_difficulty_sort,
  
  MAX(t.difficulty_sort) FILTER (WHERE t.rank = 1) - MAX(t.difficulty_sort) FILTER (WHERE t.rank = 2) AS difficulty_gap

FROM (
  SELECT
    s.player_id,
    s.id AS submission_id,
    d.sort AS difficulty_sort,
    ROW_NUMBER() OVER (PARTITION BY s.player_id ORDER BY d.sort DESC, s.date_achieved ASC, s.id ASC) AS rank
  FROM submission s
  JOIN challenge c ON s.challenge_id = c.id
  JOIN difficulty d ON c.difficulty_id = d.id
  LEFT JOIN account a ON s.player_id = a.player_id
  WHERE s.is_verified = TRUE 
    AND s.is_obsolete = FALSE
    AND c.is_rejected = FALSE
    AND d.sort > 0
    AND (a.is_suspended = FALSE OR a.is_suspended IS NULL)
) t
WHERE t.rank <= 2
GROUP BY t.player_id
HAVING COUNT(*) = 2
ORDER BY difficulty_gap DESC, first_difficulty_sort DESC
LIMIT 100;
";

$result = pg_query_params_or_die($DB, $query);

// Collect all submission IDs and player data from the ranking query
$ranking_data = [];
$all_submission_ids = [];

while ($row = pg_fetch_assoc($result)) {
  $first_id = intval($row['first_submission_id']);
  $second_id = intval($row['second_submission_id']);

  $ranking_data[] = [
    'player_id' => intval($row['player_id']),
    'first_submission_id' => $first_id,
    'second_submission_id' => $second_id,
    'difficulty_gap' => intval($row['difficulty_gap'])
  ];

  $all_submission_ids[] = $first_id;
  $all_submission_ids[] = $second_id;
}

$player_data = null;
$player_ranking = null;

// If player_id is specified, fetch their ranking data
if ($player_id !== null) {
  $player_query = "SELECT
    t.player_id,
    MAX(t.submission_id) FILTER (WHERE t.rank = 1) AS first_submission_id,
    MAX(t.submission_id) FILTER (WHERE t.rank = 2) AS second_submission_id,
    MAX(t.difficulty_sort) FILTER (WHERE t.rank = 1) AS first_difficulty_sort,
    MAX(t.difficulty_sort) FILTER (WHERE t.rank = 2) AS second_difficulty_sort,
    MAX(t.difficulty_sort) FILTER (WHERE t.rank = 1) - MAX(t.difficulty_sort) FILTER (WHERE t.rank = 2) AS difficulty_gap
  FROM (
    SELECT
      s.player_id,
      s.id AS submission_id,
      d.sort AS difficulty_sort,
      ROW_NUMBER() OVER (PARTITION BY s.player_id ORDER BY d.sort DESC, s.date_achieved ASC, s.id ASC) AS rank
    FROM submission s
    JOIN challenge c ON s.challenge_id = c.id
    JOIN difficulty d ON c.difficulty_id = d.id
    WHERE s.is_verified = TRUE 
      AND s.is_obsolete = FALSE
      AND c.is_rejected = FALSE
      AND s.player_id = $1
  ) t
  WHERE t.rank <= 2
  GROUP BY t.player_id
  HAVING COUNT(*) = 2;
  ";
  $player_result = pg_query_params_or_die($DB, $player_query, [$player_id]);
  $player_row = pg_fetch_assoc($player_result);

  if ($player_row) {
    $first_id = intval($player_row['first_submission_id']);
    $second_id = intval($player_row['second_submission_id']);

    $player_ranking = [
      'player_id' => intval($player_row['player_id']),
      'first_submission_id' => $first_id,
      'second_submission_id' => $second_id,
      'difficulty_gap' => intval($player_row['difficulty_gap'])
    ];

    // Add player's submission IDs if not already in the list
    if (!in_array($first_id, $all_submission_ids)) {
      $all_submission_ids[] = $first_id;
    }
    if (!in_array($second_id, $all_submission_ids)) {
      $all_submission_ids[] = $second_id;
    }
  }
}

// Batch fetch all submissions from view_submissions in a single query
$submissions_map = [];
if (count($all_submission_ids) > 0) {
  $ids_placeholder = implode(',', $all_submission_ids);
  $batch_query = "SELECT * FROM view_submissions WHERE submission_id IN ($ids_placeholder)";
  $batch_result = pg_query_params_or_die($DB, $batch_query);

  while ($row = pg_fetch_assoc($batch_result)) {
    $submission = new Submission();
    $submission->apply_db_data($row, 'submission_');
    $submission->expand_foreign_keys($row, 5);
    $submissions_map[intval($row['submission_id'])] = $submission;
  }
}

// Build the response data using the pre-fetched submissions
$data = [];
foreach ($ranking_data as $rank) {
  $first_submission = $submissions_map[$rank['first_submission_id']];
  $second_submission = $submissions_map[$rank['second_submission_id']];

  $data[] = [
    'player' => $first_submission->player,
    'first_submission' => $first_submission,
    'second_submission' => $second_submission,
    'difficulty_gap' => $rank['difficulty_gap']
  ];
}

// Build player data if available
if ($player_ranking !== null) {
  $first_submission = $submissions_map[$player_ranking['first_submission_id']];
  $second_submission = $submissions_map[$player_ranking['second_submission_id']];

  $player_data = [
    'player' => $first_submission->player,
    'first_submission' => $first_submission,
    'second_submission' => $second_submission,
    'difficulty_gap' => $player_ranking['difficulty_gap']
  ];
}

$response = [
  'player' => $player_data,
  'list' => $data
];

api_write($response);
