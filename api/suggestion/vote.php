<?php

require_once('../api_bootstrap.inc.php');

$account = get_user_data();

#region GET Request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $depth = isset($_REQUEST['depth']) ? intval($_REQUEST['depth']) : 2;
  $id = $_REQUEST['id'] ?? null;
  if ($id === null) {
    die_json(400, "Missing id");
  }

  $votes = SuggestionVote::get_request($DB, $id, $depth);
  api_write($votes);
  exit();
}
#endregion

#region POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_access($account, true);
  check_restriction($account, Account::$RESTRICT_VOTE, "You are restricted from voting on suggestions");

  $data = parse_post_body_as_json();
  $is_update = isset($data['id']);
  if ($is_update) {
    $vote = SuggestionVote::get_by_id($DB, $data['id']);
    if ($vote === false) {
      die_json(404, "Vote not found");
    }
    if ($vote->player_id !== $account->player_id) {
      die_json(403, "You can only edit your own votes");
    }
    if (!array_key_exists('comment', $data) && !array_key_exists('vote', $data)) {
      die_json(400, "Missing comment or vote");
    }
  } else {
    if (!isset($data['suggestion_id'], $data['vote'])) {
      die_json(400, "Missing suggestion_id or vote");
    }
    $vote = new SuggestionVote();
    $vote->suggestion_id = intval($data['suggestion_id']);
    $vote->player_id = $account->player_id;
  }

  $new_vote = array_key_exists('vote', $data) ? $data['vote'] : ($is_update ? $vote->vote : null);
  if (!in_array($new_vote, ['+', '-', 'i'], true)) {
    die_json(400, "Invalid vote");
  }
  if (array_key_exists('comment', $data)) {
    if ($data['comment'] !== null && !is_string($data['comment'])) {
      die_json(400, "Comment must be a string or null");
    }
    $vote->comment = $data['comment'] === null ? null : trim($data['comment']);
    if ($vote->comment === '') {
      $vote->comment = null;
    }
  }

  if ($vote->comment !== null && strlen($vote->comment) > 1500) {
    die_json(400, "Comment can't be longer than 1500 characters");
  }

  $suggestion = Suggestion::get_by_id($DB, $vote->suggestion_id);
  if ($suggestion === false) {
    die_json(404, "Suggestion not found");
  }
  if ($suggestion->is_verified !== true && !is_helper($account)) {
    if ($suggestion->is_verified === null && $suggestion->author_id === $account->player_id) {
      // Author can vote on their own unverified suggestion
    } else {
      die_json(400, "Suggestion is not verified yet");
    }
  }
  if ($suggestion->is_closed() && ($is_update || !is_verifier($account))) {
    die_json(400, "Suggestion is closed");
  }
  if ($is_update && $new_vote !== $vote->vote && $suggestion->is_vote_locked()) {
    die_json(403, "You cannot change your vote until 24 hours after the suggestion is verified");
  }
  if (!$is_update && SuggestionVote::has_voted_on_suggestion($DB, $account->player_id, $vote->suggestion_id)) {
    die_json(400, "You already voted on this suggestion");
  }
  if ($suggestion->challenge_id !== null && $suggestion->suggested_difficulty_id !== null) {
    //Placement suggestion
    if (Challenge::get_player_submission($DB, $suggestion->challenge_id, $account->player_id) === null) {
      //Player has not submitted to this challenge
      if ($vote->comment === null || $vote->comment === "" || strlen($vote->comment) < 10) {
        die_json(400, "Please elaborate on your position with a comment");
      }
    }
  }
  $vote->vote = $new_vote;

  if ($is_update ? $vote->update($DB) : $vote->insert($DB)) {
    $vote->expand_foreign_keys($DB, 5);
    api_write($vote);
  } else {
    die_json(500, $is_update ? "Failed to update vote" : "Failed to create vote");
  }
}
#endregion

#region DELETE Request
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
  check_access($account, true);

  $id = $_REQUEST['id'] ?? null;
  if ($id === null) {
    die_json(400, "Missing id");
  }

  $vote = SuggestionVote::get_by_id($DB, $id);
  if ($vote === false) {
    die_json(404, "Vote not found");
  }
  if ($vote->player_id !== $account->player_id && !is_verifier($account)) {
    die_json(403, "You can only delete your own votes");
  }

  $suggestion = Suggestion::get_by_id($DB, $vote->suggestion_id);
  if ($suggestion === false) {
    die_json(404, "Suggestion not found");
  }

  if ($suggestion->is_closed() && ($vote->player_id === $account->player_id || !is_verifier($account))) {
    die_json(400, "Suggestion is closed");
  }
  if ($vote->player_id === $account->player_id && $suggestion->is_vote_locked()) {
    die_json(403, "You cannot delete your vote until 24 hours after the suggestion is verified");
  }

  if (!$vote->delete($DB)) {
    die_json(500, "Failed to delete vote");
  }

  http_response_code(200);
}
#endregion

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST', 'DELETE'], true)) {
  die_json(405, "Method Not Allowed");
}