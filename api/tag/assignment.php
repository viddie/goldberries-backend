<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $challenge_id = intval($_REQUEST['challenge_id'] ?? 0);
  $player_id = intval($_REQUEST['player_id'] ?? 0);
  if ($challenge_id <= 0) {
    die_json(400, "Invalid or missing challenge_id");
  }
  if ($player_id <= 0) {
    die_json(400, "Invalid or missing player_id");
  }

  api_write([
    'challenge_id' => $challenge_id,
    'player_id' => $player_id,
    'tag_value_ids' => ChallengeTag::get_player_value_ids($DB, $challenge_id, $player_id),
  ]);
}
#endregion

#region POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $account = get_user_data();
  check_access($account);

  // Replaces the full set of tag values a player has assigned to a challenge
  $data = parse_post_body_as_json();
  $challenge_id = intval($data['challenge_id'] ?? 0);
  $player_id = isset($data['player_id']) ? intval($data['player_id']) : $account->player->id;
  if ($challenge_id <= 0) {
    die_json(400, "Invalid or missing challenge_id");
  }
  if (!isset($data['tag_value_ids'])) {
    die_json(400, "Missing tag_value_ids");
  }

  $is_own = $player_id === $account->player->id;
  if (!$is_own) {
    check_role($account, $HELPER);
  }

  $challenge = Challenge::get_by_id($DB, $challenge_id, 1, false);
  if ($challenge === false) {
    die_json(404, "Challenge not found");
  }
  if ($challenge->is_rejected) {
    die_json(400, "Rejected challenges can't be tagged");
  }
  if (!$is_own) {
    $player = Player::get_by_id($DB, $player_id, 1, false);
    if ($player === false) {
      die_json(404, "Player not found");
    }
  }

  $existing_ids = ChallengeTag::get_player_value_ids($DB, $challenge_id, $player_id);
  $final_ids = ChallengeTag::resolve_player_tags($DB, $account, $data['tag_value_ids'], $existing_ids);
  $changes = ChallengeTag::set_player_tags($DB, $challenge_id, $player_id, $final_ids);

  if (!$is_own && ($changes['added'] > 0 || $changes['removed'] > 0)) {
    log_info("'{$account->player->name}' changed the tags of player '{$player->name}' on {$challenge} (added: {$changes['added']}, removed: {$changes['removed']})", "Tag");
  }

  api_write([
    'challenge_id' => $challenge_id,
    'player_id' => $player_id,
    'tag_value_ids' => $final_ids,
  ]);
}
#endregion

#region DELETE Request
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
  // Team moderation: remove a single assignment
  $account = get_user_data();
  check_role($account, $HELPER);

  if (!isset($_REQUEST['id'])) {
    die_json(400, "Missing id");
  }

  $assignment = ChallengeTag::get_by_id($DB, check_id($_REQUEST['id']), 2, false);
  if ($assignment === false) {
    die_json(404, "Tag assignment not found");
  }

  if ($assignment->delete($DB)) {
    $defs = Tag::get_definitions($DB);
    $value = $defs['values'][$assignment->tag_value_id];
    $tag = $defs['tags'][$value->tag_id];
    $value_str = $value->name === null ? $tag->name : "{$tag->name} ({$value->name})";
    log_info("'{$account->player->name}' removed tag '{$value_str}' of player '{$assignment->player->name}' from challenge {$assignment->challenge_id}", "Tag");
    api_write($assignment);
  } else {
    die_json(500, "Failed to delete tag assignment");
  }
}
#endregion
