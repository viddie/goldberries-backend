<?php

require_once('../api_bootstrap.inc.php');
$map_images_folder = dirname(__FILE__) . '/../../img/map';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

// $account = get_user_data();
// check_access($account, false);
// if (!is_helper($account)) {
//   die_json(403, "Not authorized");
// }

// Set content type to plain text
header('Content-Type: text/html');

$query = "SELECT
  *
FROM view_challenges
WHERE map_id IS NOT NULL AND challenge_is_rejected = FALSE";

$result = pg_query_params_or_die($DB, $query);

$campaigns = [];
$all_maps = [];

while ($row = pg_fetch_assoc($result)) {
  if ($row['map_id'] === null) {
    // Skip fgrs
    continue;
  }

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

  // Check if map image exists
  $has_map_image = file_exists($map_images_folder . "/" . $map->id . ".webp");
  if (!isset($campaigns[$campaign->id]['maps'][$map->id])) {
    if (!$has_map_image) {
      $campaigns[$campaign->id]['maps'][$map->id] = $map;
    }
  }
  // Collect just for map count
  if (!isset($all_maps[$map->id])) {
    $all_maps[$map->id] = $map;
  }
}

// Count all maps without images
$max_maps = count($all_maps);
$total_maps_without_images = 0;
foreach ($campaigns as $campaign_data) {
  $total_maps_without_images += count($campaign_data['maps']);
}

$maps_done = $max_maps - $total_maps_without_images;
$percentage = ($maps_done / $max_maps) * 100;
$percent_str = number_format($percentage, 2);
echo "<p><b>Found ($total_maps_without_images / $max_maps) maps without images.</b><br>";
echo "<b>Maps done: $maps_done ($percent_str%)</b></p>";

// Output campaign-wise all the maps that dont have images

foreach ($campaigns as $campaign_data) {
  $campaign = $campaign_data['campaign'];

  //Flatten maps and sort by ID
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
