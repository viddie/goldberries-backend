<?php

require_once(dirname(__FILE__) . '/../bootstrap.inc.php');
require_once(dirname(__FILE__) . '/embed_include.php');

$map_images_folder = dirname(__FILE__) . '/../img/map';
$campaign_collage_folder = dirname(__FILE__) . '/img/campaign-collage';
$collage_images = [
  ['maps' => 8, 'images' => 4],
  ['maps' => 15, 'images' => 9],
  ['maps' => 99999, 'images' => 16],
];

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
  //Determine collage size
  $max_images = 0;
  foreach ($collage_images as $entry) {
    if ($count_maps_not_archived <= $entry['maps']) {
      $max_images = $entry['images'];
      break;
    }
  }

  $image = generate_collage_image($maps_not_archived, $max_images, 6);
  //Store collage with campaign id as filename
  if ($image) {
    $collage_path = $campaign_collage_folder . "/" . $campaign->id . ".jpg";
    imagejpeg($image, $collage_path, 80);
    imagedestroy($image);
    $collage_image = "https://" . $_SERVER['HTTP_HOST'] . "/embed/img/campaign-collage/" . $campaign->id . ".jpg";
  }
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