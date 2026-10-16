<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$player_id = filter_var($_REQUEST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($player_id === false) {
  die_json(400, "Invalid or missing id");
}
$player = Player::get_by_id($DB, $player_id, 1, false);
if ($player === false) {
  die_json(404, "Player not found");
}

$consensus_ctes = ChallengeTag::get_consensus_ctes();
$response = [
  "total_clears" => 0,
  "challenges" => [],
  "community" => [
    "total" => 0,
    "tags" => new stdClass(),
    "values" => new stdClass(),
  ],
];

#region Player clears
// One entry per cleared challenge: [difficulty_id, [consensus tag value ids]]. Untagged clears are only counted.
$result = pg_query_params_or_die($DB, "
  WITH {$consensus_ctes},
  player_clears AS (
    SELECT DISTINCT challenge.id AS challenge_id, challenge.difficulty_id
    FROM submission
    JOIN challenge ON challenge.id = submission.challenge_id
    WHERE submission.player_id = $1
      AND submission.is_verified = true
      AND submission.is_obsolete = false
  )
  SELECT pc.challenge_id, pc.difficulty_id, ARRAY_AGG(tc.tag_value_id ORDER BY tc.tag_value_id) FILTER (WHERE tc.tag_value_id IS NOT NULL) AS value_ids
  FROM player_clears pc
  LEFT JOIN tag_consensus tc ON tc.challenge_id = pc.challenge_id
  GROUP BY pc.challenge_id, pc.difficulty_id
", [$player_id], "Failed to query player tag stats");

while ($row = pg_fetch_assoc($result)) {
  $response["total_clears"]++;
  if ($row["value_ids"] === null) {
    continue;
  }
  $value_ids = array_map('intval', explode(",", trim($row["value_ids"], "{}")));
  $response["challenges"][] = [intval($row["difficulty_id"]), $value_ids];
}
#endregion

#region Community baseline
// The same consensus over all verified clears, so that every challenge is weighted by how often it was cleared
$result = pg_query_params_or_die($DB, "
  WITH {$consensus_ctes},
  clear_counts AS (
    SELECT challenge_id, COUNT(DISTINCT player_id) AS clears
    FROM submission
    WHERE is_verified = true
      AND is_obsolete = false
      AND challenge_id IN (SELECT challenge_id FROM tag_consensus)
    GROUP BY challenge_id
  )
  SELECT 'value' AS type, tc.tag_value_id AS id, SUM(tc.weight * cc.clears) AS weight
  FROM tag_consensus tc JOIN clear_counts cc ON cc.challenge_id = tc.challenge_id
  GROUP BY tc.tag_value_id
  UNION ALL
  SELECT 'tag' AS type, t.tag_id AS id, SUM(cc.clears) AS weight
  FROM (SELECT DISTINCT challenge_id, tag_id FROM tag_consensus) t JOIN clear_counts cc ON cc.challenge_id = t.challenge_id
  GROUP BY t.tag_id
  UNION ALL
  SELECT 'total' AS type, 0 AS id, SUM(clears) AS weight
  FROM clear_counts
", [], "Failed to query community tag stats");

while ($row = pg_fetch_assoc($result)) {
  $weight = round(floatval($row["weight"] ?? 0), 2);
  if ($row["type"] === "total") {
    $response["community"]["total"] = $weight;
  } else {
    $key = $row["type"] === "tag" ? "tags" : "values";
    $response["community"][$key]->{intval($row["id"])} = $weight;
  }
}
#endregion

api_write($response);
#endregion
