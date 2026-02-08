<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Invalid request method');
}

// Attempt #1
// $player1_fields = implode(", ", Player::format_fields_for_select("player1_", "p1"));
// $account1_fields = implode(", ", Account::format_fields_for_select("player1_account_", "a1"));
// $player2_fields = implode(", ", Player::format_fields_for_select("player2_", "p2"));
// $account2_fields = implode(", ", Account::format_fields_for_select("player2_account_", "a2"));

// $query = "SELECT
//   view_challenges.*,
//   $player1_fields,
//   $account1_fields,
//   $player2_fields,
//   $account2_fields,
//   MIN(t.date_achieved) AS date_achieved_first,
//   MAX(t.date_achieved) AS date_achieved_second,
//   EXTRACT(DAY FROM MAX(t.date_achieved) - MIN(t.date_achieved)) AS days_difference
// FROM (
//   SELECT
//     submission.*,
//     row_number() over (partition BY submission.challenge_id ORDER BY submission.date_achieved) AS row_number
//    FROM submission
//    WHERE submission.is_verified = TRUE
// ) t
// JOIN view_challenges ON t.challenge_id = view_challenges.challenge_id
// JOIN player p1 ON t.row_number = 1 AND t.player_id = p1.id
// JOIN account a1 ON p1.id = a1.player_id
// JOIN player p2 ON t.row_number = 2 AND t.player_id = p2.id
// JOIN account a2 ON p2.id = a2.player_id
// WHERE t.row_number = 1 OR t.row_number = 2
// GROUP BY t.challenge_id, view_challenges.challenge_id
// ORDER BY days_difference DESC LIMIT 100";
// $result = pg_query_params_or_die($DB, $query);

// Attempt #2
$query = "SELECT
  t.challenge_id,

  -- Date fields
  MAX(t.date_achieved) FILTER (WHERE t.row_number = 1) AS date_achieved_first,
  MAX(t.date_achieved) FILTER (WHERE t.row_number = 2) AS date_achieved_second,
  EXTRACT(
    DAY FROM
      MAX(t.date_achieved) FILTER (WHERE t.row_number = 2)
      - MAX(t.date_achieved) FILTER (WHERE t.row_number = 1)
  ) AS days_difference,

  -- Player IDs from the underlying submissions
  MAX(t.player_id) FILTER (WHERE t.row_number = 1) AS player_id_first,
  MAX(t.player_id) FILTER (WHERE t.row_number = 2) AS player_id_second

FROM (
  SELECT
    s.*,
    ROW_NUMBER() OVER (PARTITION BY s.challenge_id ORDER BY s.date_achieved) AS row_number
  FROM submission s
  WHERE s.is_verified = TRUE
) t
JOIN challenge c ON t.challenge_id = c.id
WHERE t.row_number <= 2
GROUP BY t.challenge_id
HAVING COUNT(*) = 2  -- only keep challenges with two verified submissions
ORDER BY days_difference DESC
LIMIT 100;

";
$result = pg_query_params_or_die($DB, $query);

$data = [];
while ($row = pg_fetch_assoc($result)) {
  $challenge = Challenge::get_by_id($DB, intval($row["challenge_id"]));
  $challenge->expand_foreign_keys($DB, 5);

  $player1 = Player::get_by_id($DB, intval($row["player_id_first"]), 2, false);
  $player1->expand_foreign_keys($DB);

  $player2 = Player::get_by_id($DB, intval($row["player_id_second"]), 2, false);
  $player2->expand_foreign_keys($DB);

  $data[] = [
    "challenge" => $challenge,
    "player1" => $player1,
    "player2" => $player2,
    "date_achieved_first" => $row["date_achieved_first"],
    "date_achieved_second" => $row["date_achieved_second"],
    "days_difference" => intval($row["days_difference"])
  ];
}

api_write($data);
#endregion