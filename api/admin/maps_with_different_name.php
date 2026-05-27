<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

header('Content-Type: text/html');

$query = "SELECT
  *
FROM view_challenges
WHERE map_id IS NOT NULL AND challenge_is_rejected = FALSE AND map_bin IS NOT NULL";

$result = pg_query_params_or_die($DB, $query);

$campaigns = [];

while ($row = pg_fetch_assoc($result)) {
  $campaign = new Campaign();
  $campaign->apply_db_data($row, "campaign_");

  if (!isset($campaigns[$campaign->id])) {
    $campaigns[$campaign->id] = [
      'campaign' => $campaign,
      'maps' => []
    ];
  }

  $map = new Map();
  $map->apply_db_data($row, "map_");
  $map->campaign = $campaign;

  if (!isset($campaigns[$campaign->id]['maps'][$map->id])) {
    $campaigns[$campaign->id]['maps'][$map->id] = $map;
  }
}

#region Compare map names against English.txt
$mismatched_campaigns = [];
$total_mismatches = 0;

foreach ($campaigns as $campaign_id => $campaign_data) {
  $cache_dir = GB_ROOT_LOCAL . "/cache/campaign_data/{$campaign_id}";
  $index = CampaignDataIndex::load($cache_dir);

  if ($index === null || $index->status !== 'ok') {
    continue;
  }

  // Build lookup: bin path -> name from index.json
  $bin_name_lookup = [];
  foreach ($index->data as $entry) {
    if (!empty($entry['name']) && isset($entry['path'])) {
      $bin_name_lookup[$entry['path']] = $entry['name'];
    }
  }

  $mismatched_maps = [];
  foreach ($campaign_data['maps'] as $map) {
    if (!isset($bin_name_lookup[$map->bin])) {
      continue;
    }

    $index_name = $bin_name_lookup[$map->bin];
    if ($index_name !== $map->name) {
      $mismatched_maps[$map->id] = [
        'map' => $map,
        'index_name' => $index_name,
      ];
    }
  }

  if (count($mismatched_maps) > 0) {
    $mismatched_campaigns[$campaign_id] = [
      'campaign' => $campaign_data['campaign'],
      'maps' => $mismatched_maps,
    ];
    $total_mismatches += count($mismatched_maps);
  }
}
#endregion

#region Output
echo "<p><b>Found $total_mismatches map(s) with different names between database and English.txt.</b></p>";

foreach ($mismatched_campaigns as $campaign_data) {
  $campaign = $campaign_data['campaign'];
  $maps = array_values($campaign_data['maps']);
  $has_many_maps = count($maps) > 1;

  if ($has_many_maps) {
    echo "<h4>Campaign: " . $campaign->get_name() . "</h4>";
    echo "<ol>";
  }
  foreach ($maps as $entry) {
    $map = $entry['map'];
    $index_name = $entry['index_name'];
    $line = $map->get_name($has_many_maps) . " - <a href='" . $map->get_url() . "'>[Link]</a>"
      . " - English.txt name: <b>" . $index_name . "</b>";

    if ($has_many_maps) {
      echo "<li>" . $line . "</li>";
    } else {
      echo $line . "<br>";
    }
  }
  if ($has_many_maps) {
    echo "</ol><br>";
  }
}
#endregion
