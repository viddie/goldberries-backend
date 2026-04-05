<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_access($account, false);
if (!is_admin($account)) {
  die_json(403, 'Not authorized');
}

//Set content type to plain text
header('Content-Type: text/plain');

$query = "SELECT DISTINCT ON (player_id)
    player_id,
    player.name AS player_name,
    submission.id AS submission_id,
    challenge.difficulty_id,
    difficulty.sort AS difficulty_sort
FROM submission
JOIN challenge ON submission.challenge_id = challenge.id
JOIN difficulty ON challenge.difficulty_id = difficulty.id
JOIN player ON submission.player_id = player.id
WHERE submission.is_verified = TRUE
ORDER BY player_id, difficulty.sort DESC, submission.date_created ASC";
$result = pg_query_params_or_die($DB, $query);

// Loop through all players and assign high tier badges, unless they already have one.
// Call: Badge::add_players_tier_badge($DB, $player_id, $sort) -> returns bool ()

$count = 0;
$badges_awarded = 0;
while ($row = pg_fetch_assoc($result)) {
  $player_id = intval($row['player_id']);
  $player_name = $row['player_name'];
  $difficulty_sort = intval($row['difficulty_sort']);
  $count++;

  // if ($count > 10) {
  //   break; // Limit to first 10 players for testing
  // }

  if ($difficulty_sort < 1) {
    echo "Did not award badge to player: $player_name ($player_id) - invalid difficulty sort: $difficulty_sort\n";
    continue; // No tier badge for this difficulty
  }
  if (Badge::add_players_tier_badge($DB, $player_id, $difficulty_sort)) {
    $badges_awarded++;
    echo "Awarded badge 'Tier $difficulty_sort' to player: $player_name ($player_id)\n";
  } else {
    echo "Did not award badge to player: $player_name ($player_id) - already has equal or higher tier badge, or other error\n";
  }
}
echo "\nTotal badges awarded: $badges_awarded\n";