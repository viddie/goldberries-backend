<?php

require_once('../api_bootstrap.inc.php');
require_once(__DIR__ . '/process_functions.inc.php');

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
  'merge_players' => ['fn' => 'action_merge_players', 'min_role' => $VERIFIER],
  'clean_campaign_data_errors' => ['fn' => 'action_clean_campaign_data_errors', 'min_role' => $ADMIN],
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

function action_merge_players($DB)
{
  if (!isset($_REQUEST['base_player_id'])) {
    die_json(400, "Missing 'base_player_id' parameter");
  }
  if (!isset($_REQUEST['merge_player_id'])) {
    die_json(400, "Missing 'merge_player_id' parameter");
  }

  $base_player_id = intval($_REQUEST['base_player_id']);
  $merge_player_id = intval($_REQUEST['merge_player_id']);

  if ($base_player_id <= 0) {
    die_json(400, "Invalid 'base_player_id' parameter");
  }
  if ($merge_player_id <= 0) {
    die_json(400, "Invalid 'merge_player_id' parameter");
  }
  if ($base_player_id === $merge_player_id) {
    die_json(400, "base_player_id and merge_player_id must be different");
  }

  $base_player = Player::get_by_id($DB, $base_player_id, 1);
  if ($base_player === false) {
    die_json(404, "Base player with id {$base_player_id} not found");
  }

  $merge_player = Player::get_by_id($DB, $merge_player_id, 1);
  if ($merge_player === false) {
    die_json(404, "Merge player with id {$merge_player_id} not found");
  }

  // Move all submissions from merge player to base player
  $result = pg_query_params_or_die(
    $DB,
    "UPDATE submission SET player_id = \$1 WHERE player_id = \$2",
    [$base_player_id, $merge_player_id],
    "Failed to move submissions from merge player to base player"
  );
  $moved_count = pg_affected_rows($result);

  // Verify the merge player has no remaining submissions
  $check = pg_query_params_or_die(
    $DB,
    "SELECT COUNT(*) AS cnt FROM submission WHERE player_id = \$1",
    [$merge_player_id],
    "Failed to verify merge player has no remaining submissions"
  );
  $remaining = intval(pg_fetch_assoc($check)['cnt']);
  if ($remaining !== 0) {
    die_json(500, "Merge failed: merge player still has {$remaining} submission(s) after transfer");
  }

  // Delete the merge player
  if (!$merge_player->delete($DB)) {
    die_json(500, "Failed to delete merge player (id: {$merge_player_id})");
  }

  log_info("Merged player '{$merge_player->name}' (id:{$merge_player_id}) into '{$base_player->name}' (id:{$base_player_id}), moved {$moved_count} submission(s)", "Player");

  return [
    'message' => "Merged player '{$merge_player->name}' into '{$base_player->name}', moved {$moved_count} submission(s)",
    'data' => [
      'deleted_merge_player_id' => $merge_player_id,
      'deleted_merge_player_name' => $merge_player->name,
      'submissions_moved' => $moved_count,
      'base_player' => $base_player,
    ],
  ];
}

function action_clean_campaign_data_errors($DB)
{
  $cache_base = GB_ROOT_LOCAL . '/cache/campaign_data';
  $deleted_ids = [];

  if (is_dir($cache_base)) {
    $dirs = scandir($cache_base);
    foreach ($dirs as $entry) {
      if ($entry === '.' || $entry === '..')
        continue;
      $dir_path = "{$cache_base}/{$entry}";
      if (!is_dir($dir_path))
        continue;

      $index_path = "{$dir_path}/index.json";
      $should_delete = false;

      if (!file_exists($index_path)) {
        $should_delete = true;
      } else {
        $content = file_get_contents($index_path);
        if ($content === false) {
          $should_delete = true;
        } else {
          $index = json_decode($content, true);
          if ($index === null || ($index['status'] ?? null) === 'error') {
            $should_delete = true;
          }
        }
      }

      if ($should_delete) {
        delete_directory_recursive($dir_path);
        $deleted_ids[] = $entry;
      }
    }
  }

  // Delete the entire campaign_data_temp folder
  $temp_dir = GB_ROOT_LOCAL . '/cache/campaign_data_temp';
  $temp_deleted = false;
  if (is_dir($temp_dir)) {
    delete_directory_recursive($temp_dir);
    $temp_deleted = true;
  }

  $count = count($deleted_ids);
  return [
    'message' => "Deleted {$count} error campaign data folder(s), temp folder " . ($temp_deleted ? 'deleted' : 'not present'),
    'data' => [
      'deleted_count' => $count,
      'deleted_ids' => $deleted_ids,
      'temp_deleted' => $temp_deleted,
    ],
  ];
}

#endregion
