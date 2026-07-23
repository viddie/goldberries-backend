<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Invalid request method');
}

$select = [];

foreach (range(0, 9) as $stamp_id) {
  $select[] = "COUNT(*) FILTER (WHERE stamp_submission.stamp_id = $stamp_id) AS stamp$stamp_id";
}

$select_str = implode(', ', $select);

$query = "SELECT
    challenge.id AS challenge_id,
    challenge.requires_fc AS challenge_requires_fc,
    challenge.label AS challenge_label,

    objective.name AS objective_name,
    objective.display_name_suffix AS objective_display_name_suffix,
    objective.is_arbitrary AS objective_is_arbitrary,

    map.name AS map_name,
    map.is_archived AS map_is_archived,

    campaign.name AS campaign_name,

    $select_str,
    COUNT(*) AS total
  FROM stamp_submission
  JOIN submission ON submission.id = stamp_submission.submission_id
  JOIN challenge ON challenge.id = submission.challenge_id
  LEFT JOIN objective ON objective.id = challenge.objective_id
  LEFT JOIN map ON map.id = challenge.map_id
  LEFT JOIN campaign ON campaign.id = challenge.campaign_id
  GROUP BY challenge.id, objective.id, map.id, campaign.id
  ORDER BY total DESC";

$result = pg_query_params_or_die($DB, $query);

$response = [];

while ($row = pg_fetch_assoc($result)) {
  $row_data = [];

  $row_data['id'] = intval($row['challenge_id']);
  $row_data['requires_fc'] = $row['challenge_requires_fc'] === 't';
  $row_data['label'] = $row['challenge_label'];

  $row_data['objective_name'] = $row['objective_name'];
  $row_data['objective_display_name_suffix'] = $row['objective_display_name_suffix'];
  $row_data['objective_is_arbitrary'] = $row['objective_is_arbitrary'] === 't';

  $row_data['map_name'] = $row['map_name'];
  $row_data['map_is_archived'] = $row['map_is_archived'] === 't';

  $row_data['campaign_name'] = $row['campaign_name'];

  $row_data['stamps'] = [];

  foreach (range(0, 9) as $stamp_id) {
    $column_name = "stamp$stamp_id";
    $row_data['stamps'][] = intval($row[$column_name]);
  }

  $row_data['total'] = intval(($row['total']));

  $response[] = $row_data;
}

api_write($response);