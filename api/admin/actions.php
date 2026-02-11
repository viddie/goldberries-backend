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
  'swap_lobbies' => ['fn' => 'action_swap_lobbies', 'min_role' => $HELPER],
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

function action_swap_lobbies($DB)
{
  // Validate parameters
  if (!isset($_REQUEST['campaign_id'])) {
    die_json(400, "Missing 'campaign_id' parameter");
  }
  if (!isset($_REQUEST['sort_a'])) {
    die_json(400, "Missing 'sort_a' parameter");
  }
  if (!isset($_REQUEST['sort_b'])) {
    die_json(400, "Missing 'sort_b' parameter");
  }

  $campaign_id = intval($_REQUEST['campaign_id']);
  $sort_a = intval($_REQUEST['sort_a']);
  $sort_b = intval($_REQUEST['sort_b']);

  if ($campaign_id <= 0) {
    die_json(400, "Invalid 'campaign_id' parameter");
  }
  if ($sort_a === $sort_b) {
    die_json(400, "sort_a and sort_b must be different");
  }

  // Fetch campaign
  $campaign = Campaign::get_by_id($DB, $campaign_id, 1, false);
  if ($campaign === false) {
    die_json(404, "Campaign with id {$campaign_id} not found");
  }

  // Validate campaign has sort_major category set
  if ($campaign->sort_major_labels === null || count($campaign->sort_major_labels) === 0) {
    die_json(400, "Campaign does not have sort_major_labels set");
  }
  if ($campaign->sort_major_colors === null || count($campaign->sort_major_colors) === 0) {
    die_json(400, "Campaign does not have sort_major_colors set");
  }

  $label_count = count($campaign->sort_major_labels);

  // Validate indices
  if ($sort_a < 0 || $sort_a >= $label_count) {
    die_json(400, "sort_a ({$sort_a}) is out of bounds (0-" . ($label_count - 1) . ")");
  }
  if ($sort_b < 0 || $sort_b >= $label_count) {
    die_json(400, "sort_b ({$sort_b}) is out of bounds (0-" . ($label_count - 1) . ")");
  }

  // Swap labels and colors in campaign
  $temp_label = $campaign->sort_major_labels[$sort_a];
  $campaign->sort_major_labels[$sort_a] = $campaign->sort_major_labels[$sort_b];
  $campaign->sort_major_labels[$sort_b] = $temp_label;

  $temp_color = $campaign->sort_major_colors[$sort_a];
  $campaign->sort_major_colors[$sort_a] = $campaign->sort_major_colors[$sort_b];
  $campaign->sort_major_colors[$sort_b] = $temp_color;

  if (!$campaign->update($DB)) {
    die_json(500, "Failed to update campaign");
  }

  // Fetch all maps of the campaign
  $query = "SELECT * FROM map WHERE campaign_id = $1";
  $result = pg_query_params_or_die($DB, $query, [$campaign_id], "Failed to fetch maps");

  $count_a_to_b = 0;
  $count_b_to_a = 0;

  while ($row = pg_fetch_assoc($result)) {
    $map = new Map();
    $map->apply_db_data($row);

    if ($map->sort_major === $sort_a) {
      $map->sort_major = $sort_b;
      if (!$map->update($DB)) {
        die_json(500, "Failed to update map #{$map->id}");
      }
      $count_a_to_b++;
    } else if ($map->sort_major === $sort_b) {
      $map->sort_major = $sort_a;
      if (!$map->update($DB)) {
        die_json(500, "Failed to update map #{$map->id}");
      }
      $count_b_to_a++;
    }
  }

  // Unset maps before returning
  $campaign->maps = null;

  $label_a = $campaign->sort_major_labels[$sort_a];
  $label_b = $campaign->sort_major_labels[$sort_b];

  return [
    'message' => "Swapped lobbies {$sort_a} ({$label_b}) and {$sort_b} ({$label_a}) in campaign '{$campaign->name}'",
    'data' => [
      'count_a_to_b' => $count_a_to_b,
      'count_b_to_a' => $count_b_to_a,
      'campaign' => $campaign,
    ],
  ];
}
#endregion
