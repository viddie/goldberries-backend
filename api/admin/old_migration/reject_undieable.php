<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_access($account, false);
if (!is_admin($account)) {
  die_json(403, "Not authorized");
}

header('Content-Type: text/plain');

$challenge_ids = [
  4966
];
$challenge_ids_str = implode(',', $challenge_ids);
$challenge_reject_reason = "no gameplay";
$submission_reject_reason = "map is rejected";

$dry_run = !isset($_REQUEST['dry_run']) || $_REQUEST['dry_run'] == 'true'; // Have to call it with dry_run=false to make changes

$query = "SELECT * FROM challenge WHERE id IN ($challenge_ids_str)";
$result = pg_query_params_or_die($DB, $query);

$count_challenges = 0;
$count_submissions = 0;
while ($row = pg_fetch_assoc($result)) {
  $challenge = new Challenge();
  $challenge->apply_db_data($row);
  $challenge->expand_foreign_keys($DB, 4);
  $challenge->fetch_all_submissions($DB);
  $count_submissions += count($challenge->submissions);
  $count_challenges += 1;

  if ($dry_run) {
    echo "Would fix challenge: {$challenge->get_name()} | difficulty_id = {$challenge->difficulty_id}\n";
    foreach ($challenge->submissions as $submission) {
      echo "  - Submission (#{$submission->id})\n";
    }
    continue;
  }

  if ($challenge->is_rejected !== true) {
    $challenge->is_rejected = true;
    $challenge->reject_note = $challenge_reject_reason;
    if ($challenge->update($DB)) {
      echo "#{$count_challenges}: Rejected challenge {$challenge->get_name()}\n";
    } else {
      echo "#{$count_challenges}: Failed to reject challenge {$challenge->get_name()}\n";
    }
  }

  foreach ($challenge->submissions as $submission) {
    if ($submission->is_verified === false) {
      continue;
    }
    $submission->is_verified = false;
    $submission->verifier_notes = $submission_reject_reason;
    $submission->date_verified = new JsonDateTime();
    $submission->expand_foreign_keys($DB, 4);
    if ($submission->update($DB)) {
      submission_embed_change($submission->id, "submission");
      send_webhook_submission_verified($submission);
      echo "  - Rejected submission (#{$submission->id})\n";
      sleep(2); // max 30/min webhook calls
    } else {
      echo "  - Failed to reject submission (#{$submission->id})\n";
    }
  }
}

echo "Total challenges: $count_challenges\n";
echo "Total submissions: $count_submissions\n";