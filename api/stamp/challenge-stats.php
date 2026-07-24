<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Invalid request method');
}

$stamp_column = [];
$select = [];

foreach (range(0, 9) as $stamp_id) {
  $stamp_column[] = "stamp$stamp_id";
  $select[] = "COUNT(*) FILTER (WHERE ss.stamp_id = $stamp_id) AS stamp$stamp_id";
}

$select_str = implode(', ', $select);
$stamp_column_str = implode(', ', $stamp_column);

$query = "SELECT 
    c.*,
    $stamp_column_str,
    total
  FROM (
    SELECT 
      c.id as challenge_id,
      $select_str,
      COUNT(*) as total
    FROM stamp_submission ss
    JOIN submission s ON s.id = ss.submission_id
    JOIN challenge c ON c.id = s.challenge_id
    GROUP BY c.id
  ) AS t
  JOIN view_challenges c ON c.challenge_id = t.challenge_id
  ORDER BY total DESC";

$result = pg_query_params_or_die($DB, $query);

$response = [];

while ($row = pg_fetch_assoc($result)) {
  $row_data = [];

  $row_data['challenge'] = new Challenge();
  $row_data['challenge']->apply_db_data($row, 'challenge_');
  $row_data['challenge']->expand_foreign_keys($row);

  $row_data['stamps'] = [];

  foreach ($stamp_column as $column_name) {
    $row_data['stamps'][] = intval($row[$column_name]);
  }

  $row_data['total'] = intval(($row['total']));

  $response[] = $row_data;
}

api_write($response);