<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (!isset($_REQUEST['challenge_id'])) {
    die_json(400, "Missing challenge_id");
  }

  $challenge_id = intval($_REQUEST['challenge_id']);
  if ($challenge_id <= 0) {
    die_json(400, "Invalid challenge_id");
  }

  // Verify challenge exists
  $challenge = Challenge::get_by_id($DB, $challenge_id, 1, false);
  if ($challenge === false) {
    die_json(404, "Challenge not found");
  }

  $likes = Like::getLikes($DB, $challenge_id);
  api_write($likes);
}
#endregion


#region POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $account = get_user_data();
  check_access($account, true);

  $data = parse_post_body_as_json();
  $like = new Like();
  $like->apply_db_data(format_assoc_array_bools($data));

  // Validate wishlist-only fields
  if (!$like->is_wishlist) {
    if ($like->state !== null) {
      die_json(400, "state can only be set when is_wishlist is true");
    }
    if ($like->progress !== null) {
      die_json(400, "progress can only be set when is_wishlist is true");
    }
    if ($like->comment !== null) {
      die_json(400, "comment can only be set when is_wishlist is true");
    }
    if ($like->time_taken !== null) {
      die_json(400, "time_taken can only be set when is_wishlist is true");
    }
    if ($like->low_death !== null) {
      die_json(400, "low_death can only be set when is_wishlist is true");
    }
  }

  // Validate state enum
  if ($like->state !== null && !in_array($like->state, Like::$valid_states, true)) {
    die_json(400, "Invalid state value. Must be one of: " . implode(', ', Like::$valid_states));
  }

  // Validate progress bounds
  if ($like->progress !== null && ($like->progress < 0 || $like->progress > 100)) {
    die_json(400, "progress must be between 0 and 100");
  }

  // Validate comment length
  if ($like->comment !== null && mb_strlen($like->comment) > 1000) {
    die_json(400, "comment must be at most 1000 characters");
  }

  // Validate low_death bounds
  if ($like->low_death !== null && $like->low_death < 0) {
    die_json(400, "low_death must be >= 0");
  }

  // Creating a new like
  if (!isset($data['id'])) {
    // Validate challenge_id exists
    if (!isset($data['challenge_id'])) {
      die_json(400, "Missing challenge_id");
    }

    // Set date_created to now
    $like->player_id = $account->player->id;
    $like->date_created = new JsonDateTime();

    // Set date_updated if any wishlist fields are provided
    if ($like->is_wishlist && ($like->state !== null || $like->progress !== null || $like->comment !== null)) {
      $like->date_updated = new JsonDateTime();
    }

    $challenge = Challenge::get_by_id($DB, $like->challenge_id, 1, false);
    if ($challenge === false) {
      die_json(400, "Challenge with id {$like->challenge_id} does not exist");
    }

    // Check if like already exists
    $existing = Like::findByChallengeAndPlayer($DB, $like->challenge_id, $like->player_id);
    if ($existing !== false) {
      die_json(400, "You have already liked this challenge");
    }

    if (!$like->insert($DB)) {
      die_json(500, "Failed to create like");
    }

    // Recalculate challenge likes count
    Like::recalculateChallengeLikes($DB, $like->challenge_id);

    log_info("'{$account->player->name}' liked challenge {$like->challenge_id}", "Like");

    $like->expand_foreign_keys($DB, 1, false);
    api_write($like);
  }
  // Updating an existing like
  else {
    $old_like = Like::get_by_id($DB, $like->id, 1, false);
    if ($old_like === false) {
      die_json(404, "Like with id {$like->id} does not exist");
    }

    // Ensure player_id matches own player
    if ($old_like->player_id !== $account->player->id && !is_verifier($account)) {
      die_json(403, "You can only update your own likes");
    }

    // challenge_id and date_created cannot be modified
    // Update allowed fields
    $old_like->is_wishlist = $like->is_wishlist;
    $old_like->state = $like->state;
    $old_like->progress = $like->progress;
    $old_like->comment = $like->comment;
    $old_like->time_taken = $like->time_taken;
    $old_like->low_death = $like->low_death;

    // If wishlist is turned off, clear all wishlist fields
    if (!$old_like->is_wishlist) {
      $old_like->state = null;
      $old_like->progress = null;
      $old_like->comment = null;
      $old_like->date_updated = null;
      $old_like->time_taken = null;
      $old_like->low_death = null;
    } else {
      // Set date_updated to now on any update
      $old_like->date_updated = new JsonDateTime();
    }

    if (!$old_like->update($DB)) {
      die_json(500, "Failed to update like");
    }

    // Recalculate challenge likes count (in case is_wishlist changed)
    Like::recalculateChallengeLikes($DB, $old_like->challenge_id);

    $old_like->expand_foreign_keys($DB, 2, false);
    api_write($old_like);
  }
}
#endregion


#region DELETE Request
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
  $account = get_user_data();
  check_access($account, true);

  if (!isset($_REQUEST['id'])) {
    die_json(400, "Missing id");
  }

  $id = intval($_REQUEST['id']);
  if ($id <= 0) {
    die_json(400, "Invalid id");
  }

  $like = Like::get_by_id($DB, $id, 1, false);
  if ($like === false) {
    die_json(404, "Like with id {$id} does not exist");
  }

  // Check if player_id matches own player OR user is verifier+
  if ($like->player_id !== $account->player->id && !is_verifier($account)) {
    die_json(403, "You can only delete your own likes");
  }

  $challenge_id = $like->challenge_id;

  if (!$like->delete($DB)) {
    die_json(500, "Failed to delete like");
  }

  // Recalculate challenge likes count
  Like::recalculateChallengeLikes($DB, $challenge_id);

  log_info("'{$account->player->name}' removed like {$id} from challenge {$challenge_id}", "Like");

  http_response_code(200);
  api_write($like);
}
#endregion
