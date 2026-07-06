<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Invalid request method');
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

$sort_by_total_tier = isset($_GET['sort_by_total_tier']) ? $_GET['sort_by_total_tier'] === "true" : false;

if ($sort_by_total_tier) {
    $query .= " ORDER BY total_tier DESC";
} else {
    $query .= " ORDER BY stamp_count DESC";
}

$result = pg_query($DB, $query);

if (!$result) {
  die_json(500, "Failed to query database");
}

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