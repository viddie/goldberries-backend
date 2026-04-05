<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

header('Content-Type: text/html');

// Maps manually verified to correctly have NULL as map.bin
$excluded_map_ids = [51174627];

$excluded_placeholders = implode(', ', array_map(function ($i) {
  return '$' . ($i + 1);
}, array_keys($excluded_map_ids)));

$query = "SELECT
  *
FROM view_challenges
WHERE map_id IS NOT NULL AND challenge_is_rejected = FALSE AND map_bin IS NULL
  AND campaign_url IS NOT NULL
  AND map_id NOT IN ($excluded_placeholders)";

$result = pg_query_params_or_die($DB, $query, $excluded_map_ids);

$gb_campaigns = [];
$non_gb_campaigns = [];
$all_maps = [];

while ($row = pg_fetch_assoc($result)) {
  if ($row['map_id'] === null) {
    continue;
  }

  $campaign = new Campaign();
  $campaign->apply_db_data($row, "campaign_");

  $is_gb = str_contains($campaign->url ?? '', 'gamebanana.com');
  if ($is_gb) {
    $group = &$gb_campaigns;
  } else {
    $group = &$non_gb_campaigns;
  }

  if (!isset($group[$campaign->id])) {
    $group[$campaign->id] = [
      'campaign' => $campaign,
      'maps' => []
    ];
  }

  $map = new Map();
  $map->apply_db_data($row, "map_");
  $map->campaign = $campaign;

  if (!isset($group[$campaign->id]['maps'][$map->id])) {
    $group[$campaign->id]['maps'][$map->id] = $map;
  }

  if (!isset($all_maps[$map->id])) {
    $all_maps[$map->id] = $map;
  }

  unset($group);
}

$total_maps_without_bins = count($all_maps);

// Count all maps for percentage
$all_maps_query = "SELECT COUNT(DISTINCT map_id) AS total FROM view_challenges WHERE map_id IS NOT NULL AND challenge_is_rejected = FALSE";
$all_maps_result = pg_query_params_or_die($DB, $all_maps_query);
$max_maps = (int) pg_fetch_assoc($all_maps_result)['total'];

$maps_done = $max_maps - $total_maps_without_bins;
$percentage = ($max_maps > 0) ? ($maps_done / $max_maps) * 100 : 0;
$percent_str = number_format($percentage, 2);
echo "<p><b>Found ($total_maps_without_bins / $max_maps) maps without bins.</b><br>";
echo "<b>Maps done: $maps_done ($percent_str%)</b></p>";

$groups = [
  'Valid GameBanana links (processing failed)' => $gb_campaigns,
  'Invalid GameBanana links (external mod sources)' => $non_gb_campaigns,
];

foreach ($groups as $group_label => $campaigns) {
  $group_map_count = 0;
  foreach ($campaigns as $campaign_data) {
    $group_map_count += count($campaign_data['maps']);
  }
  echo "<h2>$group_label ($group_map_count)</h2>";

  foreach ($campaigns as $campaign_data) {
    $campaign = $campaign_data['campaign'];

    $maps = array_values($campaign_data['maps']);
    $has_many_maps = count($maps) > 1;

    if (count($maps) > 0) {
      if ($has_many_maps) {
        echo "<h4>Campaign: " . htmlspecialchars($campaign->get_name()) . "</h4>";
        echo "<ol>";
      }
      foreach ($maps as $map) {
        if ($has_many_maps) {
          echo "<li>" . $map->get_name(true) . " - <a href='" . $map->get_url() . "'>[Link]</a></li>";
        } else {
          echo $map->get_name() . " - <a href='" . $map->get_url() . "'>[Link]</a><br>";
        }
      }
      if ($has_many_maps) {
        echo "</ol><br>";
      }
    }
  }
}
