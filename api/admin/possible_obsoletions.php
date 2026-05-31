<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

header('Content-Type: text/html');

// For every player, find all maps where the player has more than 1 non-obsolete (verified)
// submission across all challenges of the map. Those are candidates for obsoletion.
//
// Memory notes:
//  - pg_query buffers the whole result set in PHP memory, so we select only the few columns
//    needed for rendering (instead of the wide view_challenges) and join base tables directly.
//  - The result is ordered by player and processed in a streaming fashion: only a single
//    player's data is held in memory at a time. When the player_id changes, the previous
//    player is filtered, rendered, and freed.
$query = "SELECT
  p.id AS player_id,
  p.name AS player_name,

  cam.id AS campaign_id,
  cam.name AS campaign_name,
  cam.author_gb_name AS campaign_author_gb_name,

  m.id AS map_id,
  m.name AS map_name,
  m.is_archived AS map_is_archived,

  c.id AS challenge_id,
  c.label AS challenge_label,
  c.requires_fc AS challenge_requires_fc,
  c.has_fc AS challenge_has_fc,

  o.name AS objective_name,
  o.display_name_suffix AS objective_display_name_suffix,

  s.id AS submission_id
FROM submission s
JOIN challenge c ON c.id = s.challenge_id
JOIN map m ON c.map_id = m.id
JOIN campaign cam ON m.campaign_id = cam.id
JOIN objective o ON c.objective_id = o.id
JOIN player p ON p.id = s.player_id
WHERE s.is_obsolete = FALSE
  AND s.is_verified = TRUE
  AND c.is_rejected = FALSE
  AND c.map_id IS NOT NULL
ORDER BY p.id, cam.name, m.sort_major, m.sort_minor, m.sort_order, m.name, c.sort";

$result = pg_query_params_or_die($DB, $query);

#region Streaming output (one player at a time)
$totals = [
  'players' => 0,
  'maps' => 0,
  'submissions' => 0,
];

// Buffer the rendered body so the summary line can be printed before it.
ob_start();

$current_player_id = null;
$current_player = null;
$current_maps = [];

while ($row = pg_fetch_assoc($result)) {
  $player_id = intval($row['player_id']);

  if ($player_id !== $current_player_id) {
    // Flush the previous player before starting a new one.
    if ($current_player_id !== null) {
      flush_player($current_player, $current_maps, $totals);
    }

    $current_player_id = $player_id;
    $current_player = new Player();
    $current_player->id = $player_id;
    $current_player->name = $row['player_name'];
    $current_maps = [];
  }

  $map_id = intval($row['map_id']);
  $challenge_id = intval($row['challenge_id']);

  if (!isset($current_maps[$map_id])) {
    $campaign = new Campaign();
    $campaign->id = intval($row['campaign_id']);
    $campaign->name = $row['campaign_name'];
    $campaign->author_gb_name = $row['campaign_author_gb_name'];

    $map = new Map();
    $map->id = $map_id;
    $map->name = $row['map_name'];
    $map->is_archived = $row['map_is_archived'] === 't';
    $map->campaign = $campaign;

    $current_maps[$map_id] = [
      'map' => $map,
      'challenges' => [],
      'submission_count' => 0,
    ];
  }

  if (!isset($current_maps[$map_id]['challenges'][$challenge_id])) {
    $objective = new Objective();
    $objective->name = $row['objective_name'];
    $objective->display_name_suffix = $row['objective_display_name_suffix'];

    $challenge = new Challenge();
    $challenge->id = $challenge_id;
    $challenge->label = $row['challenge_label'];
    $challenge->requires_fc = $row['challenge_requires_fc'] === 't';
    $challenge->has_fc = $row['challenge_has_fc'] === 't';
    $challenge->map = $current_maps[$map_id]['map'];
    $challenge->objective = $objective;

    $current_maps[$map_id]['challenges'][$challenge_id] = [
      'challenge' => $challenge,
      'submission_ids' => [],
    ];
  }

  $current_maps[$map_id]['challenges'][$challenge_id]['submission_ids'][] = intval($row['submission_id']);
  $current_maps[$map_id]['submission_count']++;
}

// Flush the final player.
if ($current_player_id !== null) {
  flush_player($current_player, $current_maps, $totals);
}

$body = ob_get_clean();
#endregion

#region Output
echo "<p><b>Found {$totals['maps']} possible obsoletion(s) across {$totals['players']} player(s) ({$totals['submissions']} submissions).</b><br>";
echo $body;
#endregion

#region Functions
/**
 * Filters a player's maps (keeping only those with more than 1 submission), renders the
 * qualifying ones, and updates the running totals. Only this player's data is in memory.
 *
 * @param Player $player
 * @param array $maps map_id => ['map' => Map, 'challenges' => [...], 'submission_count' => int]
 * @param array $totals
 */
function flush_player($player, $maps, &$totals)
{
  // Keep only maps with more than 1 non-obsolete submission.
  $qualifying = [];
  foreach ($maps as $map_id => $map_data) {
    if ($map_data['submission_count'] > 1) {
      $qualifying[$map_id] = $map_data;
    }
  }

  if (count($qualifying) === 0) {
    return;
  }

  $totals['players']++;

  echo "<h2><a href='" . $player->get_url() . "'>" . htmlspecialchars($player->name) . "</a></h2>";

  foreach ($qualifying as $map_data) {
    $totals['maps']++;
    $totals['submissions'] += $map_data['submission_count'];

    $map = $map_data['map'];
    echo "<h4>" . htmlspecialchars($map->get_name()) . " - <a href='" . $map->get_url() . "'>[Map]</a></h4>";
    echo "<table border='1' cellpadding='4' cellspacing='0'>";
    foreach ($map_data['challenges'] as $challenge_entry) {
      $challenge = $challenge_entry['challenge'];
      $challenge_link = "<a href='" . $challenge->get_url() . "'>" . htmlspecialchars($challenge->get_name(true, true)) . "</a>";
      foreach ($challenge_entry['submission_ids'] as $submission_id) {
        $url = constant('BASE_URL') . "/submission/" . $submission_id;
        $submission_link = "<a href='" . $url . "'>Submission #" . $submission_id . "</a>";
        echo "<tr><td>" . $challenge_link . "</td><td>" . $submission_link . "</td></tr>";
      }
    }
    echo "</table>";
  }
}
#endregion
