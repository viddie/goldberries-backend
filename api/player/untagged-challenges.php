<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_access($account);

$player_id = filter_var($_REQUEST['player_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($player_id === false) {
  die_json(400, "Invalid or missing player_id");
}
$player = Player::get_by_id($DB, $player_id, 1, false);
if ($player === false) {
  die_json(404, "Player not found");
}

// Return an ordered ID snapshot so tagging does not shift the client's pagination.
$result = pg_query_params_or_die($DB, "
  SELECT challenge_id
  FROM view_challenges
  WHERE challenge_is_rejected = false
    AND EXISTS (
      SELECT 1 FROM submission
      WHERE submission.challenge_id = view_challenges.challenge_id
        AND submission.player_id = $1
    )
    AND NOT EXISTS (
      SELECT 1 FROM challenge_tag
      WHERE challenge_tag.challenge_id = view_challenges.challenge_id
        AND challenge_tag.player_id = $2
    )
  ORDER BY difficulty_sort DESC, campaign_name, campaign_id,
    map_sort_major, map_sort_minor, map_sort_order, map_name, map_id,
    challenge_sort, challenge_id
", [$player_id, $account->player_id], "Failed to query untagged challenges");

$challenge_ids = [];
while ($row = pg_fetch_assoc($result)) {
  $challenge_ids[] = intval($row['challenge_id']);
}

api_write($challenge_ids);
#endregion
