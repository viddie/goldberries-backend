<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_role($account, $HELPER);

if (!isset($_REQUEST['action'])) {
  die_json(400, "Missing 'action' parameter");
}

$action = $_REQUEST['action'];

#region Action Registry
$actions = [
  'clean_traffic' => ['fn' => 'action_clean_traffic', 'min_role' => $ADMIN],
  'reject_challenge' => ['fn' => 'action_reject_challenge', 'min_role' => $HELPER],
];
#endregion

if (!isset($actions[$action])) {
  die_json(400, "Unknown action: {$action}");
}

$action_entry = $actions[$action];
check_role($account, $action_entry['min_role']);

$result = $action_entry['fn']($DB);
api_write($result);

#region Action Functions
function action_clean_traffic($DB)
{
  $result = pg_query_params_or_die($DB, "DELETE FROM traffic WHERE user_agent IS NULL", [], "Failed to delete traffic entries");
  $count = pg_affected_rows($result);
  return [
    'message' => "Deleted {$count} traffic entries with NULL user_agent",
    'data' => [
      'deleted' => $count
    ],
  ];
}

function action_reject_challenge($DB)
{
  if (!isset($_REQUEST['id'])) {
    die_json(400, "Missing 'id' parameter");
  }
  if (!isset($_REQUEST['reason'])) {
    die_json(400, "Missing 'reason' parameter");
  }

  $id = intval($_REQUEST['id']);
  if ($id <= 0) {
    die_json(400, "Invalid 'id' parameter");
  }
  $reason = $_REQUEST['reason'];

  $challenge = Challenge::get_by_id($DB, $id, 4);
  if ($challenge === false) {
    die_json(404, "Challenge with id {$id} not found");
  }

  // Reject all non-rejected submissions
  $challenge->fetch_all_submissions($DB);
  $affected_submissions = [];

  foreach ($challenge->submissions as $submission) {
    if ($submission->is_verified === false) {
      continue;
    }

    $submission->is_verified = false;
    $submission->verifier_notes = "challenge is rejected";
    $submission->date_verified = new JsonDateTime();
    $submission->expand_foreign_keys($DB, 4);

    if (!$submission->update($DB)) {
      die_json(500, "Failed to reject submission #{$submission->id}");
    }

    submission_embed_change($submission->id, "submission");
    send_webhook_submission_verified($submission);
    $affected_submissions[] = $submission;

    sleep(2);
  }

  // Reject the challenge itself
  $challenge->is_rejected = true;
  $challenge->reject_note = $reason;
  if (!$challenge->update($DB)) {
    die_json(500, "Failed to reject challenge #{$id}");
  }

  $count = count($affected_submissions);
  return [
    'message' => "Rejected challenge #{$id} and {$count} submission(s)",
    'data' => [
      'submissions' => $affected_submissions
    ],
  ];
}
#endregion
