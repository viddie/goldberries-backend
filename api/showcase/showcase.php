<?php

require_once('../api_bootstrap.inc.php');

$account = get_user_data();

#region GET Request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $id = isset($_REQUEST['player_id']) ? intval($_REQUEST['player_id']) : null;
  if ($id === null) {
    die_json(400, "Missing player_id");
  }

  $player = Player::get_by_id($DB, $id);
  if ($player === false) {
    die_json(404, "Player with id {$id} does not exist");
  }

  $accounts = Account::find_by_player_id($DB, $player->id);
  $account = count($accounts) > 0 ? $accounts[0] : false;
  $submissions = array();
  $type = "custom";

  if ($account !== false) {
    $obj_array = Showcase::find_all_for_account_id($DB, $account->id);
    //Loop over array
    foreach ($obj_array as $showcase) {
      $submissions[] = $showcase->submission;
    }
  }

  //If none were found, take the X hardest submissions the player has done instead
  if (count($submissions) === 0) {
    $submissions = Submission::get_hardest_for_player($DB, $player->id, 9);
    $type = "hardest";
  }

  api_write(
    array(
      "submissions" => $submissions,
      "type" => $type,
    )
  );
  exit();
}
#endregion

#region POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_access($account, true);

  $submission_ids = parse_post_body_as_json();
  $checked_submission_ids = array();
  //Check if all submission_ids exist and belong to the player (or the account is verifier)
  $submissions = array();
  foreach ($submission_ids as $submission_id) {
    if ($submission_id === null) {
      continue;
    }
    $submission = Submission::get_by_id($DB, $submission_id);
    if ($submission === false) {
      die_json(404, "Submission with id {$submission_id} does not exist");
    }

    if ($submission->is_verified !== true) {
      die_json(403, "Submission with id {$submission_id} isn't verified");
    }

    if ($submission->player_id !== $account->player_id && !is_verifier($account)) {
      die_json(403, "Submission with id {$submission_id} does not belong to you");
    }

    if (in_array($submission_id, $checked_submission_ids)) {
      //No error, but drop the submission
      continue;
    }
    $checked_submission_ids[] = $submission_id;
    $submissions[] = $submission;
  }


  //Then delete all existing showcase entries for the player
  $query = "DELETE FROM showcase WHERE account_id = $1";
  $result = pg_query_params($DB, $query, array($account->id));
  if ($result === false) {
    die_json(500, "Failed to delete existing showcase entries");
  }

  //Then insert the new showcase entries
  $index = 0;
  foreach ($submissions as $submission) {
    //Ignore more than 10 submissions
    if ($index >= 10) {
      break;
    }

    $showcase = new Showcase();
    $showcase->account_id = $account->id;
    $showcase->index = $index;
    $showcase->submission_id = $submission->id;
    if (!$showcase->insert($DB)) {
      die_json(500, "Failed to insert showcase entry for submission with id {$submission->id}");
    }
    $index++;
  }

  api_write($submissions);
}
#endregion

// No delete interface