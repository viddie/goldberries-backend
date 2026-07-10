<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Invalid request method');
}

$sort = isset($_REQUEST['sort']) ? $_REQUEST['sort'] : 'name';
$valid_sort = ['name', 'total_tier', 'stamp_count'];

if (!in_array($sort, $valid_sort)) {
  die_json(400, "Invalid sort_by parameter. Valid values: " . implode(', ', $valid_sort));
}

$query = "SELECT
	COUNT(*) AS stamp_count,
	SUM(d.sort) AS total_tier,
	SUM(d.sort) / 10.0 AS avg_tier,
	p.name AS player_name,
	p.id AS player_id
FROM stamp_submission ss
JOIN player p ON p.id = ss.player_id
JOIN submission s ON s.id = ss.submission_id
JOIN challenge c ON c.id = s.challenge_id
JOIN difficulty d ON d.id = c.difficulty_id
GROUP BY ss.player_id, p.name, p.id";

if ($sort === 'name') {
  $query .= " ORDER BY player_name";
} else {
  $order_by_column = $sort === 'stamp_count' ? 'stamp_count' : 'total_tier';
  $query .= " ORDER BY $order_by_column DESC";
}

$result = pg_query_params_or_die($DB, $query);

$data = [];

while ($row = pg_fetch_assoc($result)) {
  $stamp_count = intval($row['stamp_count']);
  $total_tier = intval($row['total_tier']);
  $avg_tier = floatval($row['avg_tier']);
  $player_name = $row['player_name'];
  $player_id = intval($row['player_id']);

  $data[] = [
    'stamp_count' => $stamp_count,
    'total_tier' => $total_tier,
    'avg_tier' => $avg_tier,
    'player_name' => $player_name,
    'player_id' => $player_id,
  ];
}

api_write($data);