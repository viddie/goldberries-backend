<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Invalid request method');
}

$select = [];

foreach (range(0, 9) as $stamp_id) {
  $select[] = "COUNT(*) FILTER (WHERE ss.stamp_id = $stamp_id) AS stamp$stamp_id";
}

$select_str = implode(', ', $select);

$query = "SELECT
    m.id AS id,
    m.name AS name,
    $select_str
  FROM stamp_submission ss
  JOIN submission s ON s.id = ss.submission_id
  JOIN challenge c ON c.id = s.challenge_id
  JOIN map m ON m.id = c.map_id
  GROUP BY m.id, m.name";

$result = pg_query_params_or_die($DB, $query);

$response = [];

while ($row = pg_fetch_assoc($result)) {
  $row_data = [];

  $row_data['id'] = intval($row["id"]);
  $row_data['name'] = $row["name"];
  $row_data['stamps'] = [];

  foreach (range(0, 9) as $stamp_id) {
    $column_name = "stamp$stamp_id";
    $row_data['stamps'][] = intval($row[$column_name]);
  }

  $response[] = $row_data;
}

api_write($response);