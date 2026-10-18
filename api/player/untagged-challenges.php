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

// Optional comma-separated tag ids: only return challenges that are missing at least one of these tags
$tag_ids = [];
$tag_ids_raw = trim($_REQUEST['tag_ids'] ?? '');
if ($tag_ids_raw !== '') {
  foreach (explode(',', $tag_ids_raw) as $raw_id) {
    $tag_id = filter_var(trim($raw_id), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($tag_id === false) {
      die_json(400, "Invalid tag_ids");
    }
    $tag_ids[$tag_id] = $tag_id;
  }
}

$params = [$player_id, $account->player_id];
if (count($tag_ids) === 0) {
  $tag_condition = "NOT EXISTS (
      SELECT 1 FROM challenge_tag
      WHERE challenge_tag.challenge_id = view_challenges.challenge_id
        AND challenge_tag.player_id = $2
    )";
} else {
  $params[] = "{" . implode(",", array_values($tag_ids)) . "}";
  $tag_condition = "EXISTS (
      SELECT 1 FROM unnest($3::int[]) AS selected(tag_id)
      WHERE NOT EXISTS (
        SELECT 1 FROM challenge_tag
        JOIN tag_value ON tag_value.id = challenge_tag.tag_value_id
        WHERE challenge_tag.challenge_id = view_challenges.challenge_id
          AND challenge_tag.player_id = $2
          AND tag_value.tag_id = selected.tag_id
      )
    )";
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
    AND {$tag_condition}
  ORDER BY difficulty_sort DESC, campaign_name, campaign_id,
    map_sort_major, map_sort_minor, map_sort_order, map_name, map_id,
    challenge_sort, challenge_id
", $params, "Failed to query untagged challenges");

$challenge_ids = [];
while ($row = pg_fetch_assoc($result)) {
  $challenge_ids[] = intval($row['challenge_id']);
}

api_write($challenge_ids);
#endregion
