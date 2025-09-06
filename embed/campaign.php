<?php

require_once(dirname(__FILE__) . '/../bootstrap.inc.php');
require_once(dirname(__FILE__) . '/embed_include.php');

$DB = db_connect();

$id = intval($_REQUEST['id']);
if ($id <= 0) {
  http_response_code(400);
  die();
}

$campaign = Campaign::get_by_id($DB, $id);
if (!$campaign) {
  http_response_code(404);
  die();
}

$campaign->expand_foreign_keys($DB, 5);
$campaign->fetch_maps($DB, true, false, true);
$maps_not_archived = [];
foreach ($campaign->maps as $map) {
  if (!$map->is_archived) {
    $maps_not_archived[] = $map;
  }
}

$title_str = $campaign->get_name();
$description_str = "";

$count_maps = count($campaign->maps);
$count_maps_not_archived = count($maps_not_archived);

$collage_image = null;
if ($count_maps_not_archived > 0) {
  $collage_image = "https://" . $_SERVER['HTTP_HOST'] . "/embed/img/campaign_collage.php?id=" . $campaign->id . "&ext=jpg";
}

if ($count_maps > 1 && $count_maps <= 5) {
  $description_str .= "Maps:\n";
  foreach ($campaign->maps as $map) {
    $map->expand_foreign_keys($DB, 5);
    $map_str = $map->get_name(true);
    $hardest_challenge = null;
    foreach ($map->challenges as $challenge) {
      if ($hardest_challenge === null || $challenge->difficulty->sort > $hardest_challenge->difficulty->sort) {
        $hardest_challenge = $challenge;
      }
    }
    $tier_name = $hardest_challenge->difficulty->to_tier_name();
    $description_str .= "  - {$map_str} ({$tier_name})\n";
  }
  //Remove last newline
  $description_str = substr($description_str, 0, -1);
} else if ($count_maps === 0) {
  $description_str .= "This campaign doesn't have any maps yet.";
} else if ($count_maps === 1) {
  $description_str .= "Stand-alone Map";
} else {
  $description_str .= "Map Count: {$count_maps}";
}

$real_url = $campaign->get_url();

if ($collage_image) {
  output_image_with_site_embed($real_url, $title_str, $description_str, $collage_image, "");
} else {
  output_text_embed($real_url, $title_str, $description_str, "");
}