<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

// Who tagged a specific value on a challenge
if (isset($_REQUEST['tag_value_id'])) {
  $challenge_id = intval($_REQUEST['challenge_id'] ?? 0);
  $tag_value_id = intval($_REQUEST['tag_value_id']);
  if ($challenge_id <= 0) {
    die_json(400, "Invalid or missing challenge_id");
  }
  if ($tag_value_id <= 0) {
    die_json(400, "Invalid tag_value_id");
  }
  api_write(ChallengeTag::get_assignments($DB, $challenge_id, $tag_value_id));
  exit();
}

// Aggregated tag counts for one or more challenges, or all challenges of a map
$challenge_ids = [];
if (isset($_REQUEST['map_id'])) {
  $map_id = intval($_REQUEST['map_id']);
  if ($map_id <= 0) {
    die_json(400, "Invalid map_id");
  }
  $result = pg_query_params_or_die($DB, "SELECT id FROM challenge WHERE map_id = $1", [$map_id], "Failed to fetch challenges of map");
  while ($row = pg_fetch_assoc($result)) {
    $challenge_ids[] = intval($row['id']);
  }
} else if (isset($_REQUEST['challenge_id'])) {
  $raw = $_REQUEST['challenge_id'];
  if (!is_valid_id_query($raw)) {
    die_json(400, "Invalid challenge_id");
  }
  $challenge_ids = is_array($raw) ? array_map('intval', $raw) : [intval($raw)];
  if (count($challenge_ids) > 1000) {
    die_json(400, "Too many challenge ids");
  }
} else {
  die_json(400, "Missing challenge_id or map_id");
}

api_write(ChallengeTag::get_counts_for_challenges($DB, $challenge_ids));
#endregion
