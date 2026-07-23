<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Invalid request method');
}

$query = "SELECT ss.stamp_id AS stamp_id, s.* FROM stamp_submission ss JOIN view_submissions s ON s.submission_id = ss.submission_id";

$result = pg_query_params_or_die($DB, $query);

$response = [];

while ($row = pg_fetch_assoc($result)) {
  $challenge_id = $row['challenge_id'];

  if (!array_key_exists($challenge_id, $response)) {
    $row_data = [];

    $row_data['challenge'] = new Challenge();
    $row_data['challenge']->apply_db_data($row, "challenge_");
    $row_data['challenge']->expand_foreign_keys($row);

    $row_data['stamps'] = array_fill(0, 10, 0);
    $row_data['total'] = 0;

    $response[$challenge_id] = $row_data;
  }

  $response[$challenge_id]['stamps'][intval($row['stamp_id'])]++;
  $response[$challenge_id]['total']++;
}

$response = array_values($response);

api_write($response);