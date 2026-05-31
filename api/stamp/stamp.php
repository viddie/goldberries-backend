<?php

require_once('../api_bootstrap.inc.php');

$account = get_user_data();

#region GET Request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $id = $_REQUEST['id'] ?? null;
  if ($id === null) {
    die_json(400, "Missing id");
  }
  $stamp_submissions = StampSubmission::get_request($DB, $id);
  if (is_array($stamp_submissions)) {
    foreach ($stamp_submissions as $stamp_submission) {
      $stamp_submission->expand_foreign_keys($DB, 6, false);
    }
  } else {
    $stamp_submissions->expand_foreign_keys($DB, 6, false);
  }
  api_write($stamp_submissions);
}
#endregion

#region POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_access($account, true);
  if (!is_helper($account)) {
    die_json(403, "You cannot modify stamp submissions");
  }

  $data = format_assoc_array_bools(parse_post_body_as_json());
  $stamp_submission = new StampSubmission();
  $stamp_submission->apply_db_data($data);

  $submission = Submission::get_by_id($DB, $stamp_submission->submission_id);
  if ($submission === false) {
    die_json(400, "Submission not found");
  }

  $stamp_submission->player_id = $submission->player_id;
  $stamp_submission->date_assigned = new JsonDateTime();

  if ($stamp_submission->insert($DB)) {
    log_info("'{$account->player->name}' created {$stamp_submission}", "Event");
    api_write($stamp_submission);
  } else {
    die_json(500, "Failed to create stamp submission");
  }
}
#endregion

#region DELETE Request
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
  check_access($account, true);
  if (!is_helper($account)) {
    die_json(403, "You cannot modify stamp submissions");
  }

  $id = $_REQUEST['id'] ?? null;
  if ($id === null) {
    die_json(400, "Missing id");
  }

  $stamp_submission = StampSubmission::get_by_id($DB, $id);
  if ($stamp_submission === false) {
    die_json(404, "StampSubmission not found");
  }
  if (!$stamp_submission->delete($DB)) {
    die_json(500, "Failed to delete stamp submission");
  }

  log_info("'{$account->player->name}' deleted {$stamp_submission}", "Event");
  http_response_code(200);
}
#endregion
