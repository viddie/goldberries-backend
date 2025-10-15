<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Invalid request method');
}

$player_fields = implode(", ", Player::format_fields_for_select("player_"));
$account_fields = implode(", ", Account::format_fields_for_select("player_account_"));
$submission_fields = implode(", ", Submission::format_fields_for_select("submission_"));
$query = "SELECT
  view_challenges.*,
  $player_fields,
  $account_fields,
  $submission_fields,
  EXTRACT(DAY FROM NOW() - submission.date_achieved) AS days_difference
FROM view_challenges
JOIN submission ON view_challenges.challenge_id = submission.challenge_id
JOIN player ON submission.player_id = player.id
JOIN account ON player.id = account.player_id
WHERE view_challenges.count_submissions = 1
ORDER BY days_difference DESC LIMIT 100";
$result = pg_query_params_or_die($DB, $query);

$data = [];
while ($row = pg_fetch_assoc($result)) {
  $challenge = new Challenge();
  $challenge->apply_db_data($row, 'challenge_');
  $challenge->expand_foreign_keys($DB, 5, true);

  $player = new Player();
  $player->apply_db_data($row, 'player_');

  $submission = new Submission();
  $submission->apply_db_data($row, 'submission_');

  $data[] = [
    "challenge" => $challenge,
    "player" => $player,
    "submission" => $submission,
    "days_difference" => intval($row["days_difference"])
  ];
}

api_write($data);