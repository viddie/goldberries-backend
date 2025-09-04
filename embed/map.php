<?php

require_once(dirname(__FILE__) . '/../bootstrap.inc.php');
require_once(dirname(__FILE__) . '/embed_include.php');

$map_images_folder = dirname(__FILE__) . '/../img/map';
$DB = db_connect();

$id = intval($_REQUEST['id']);
if ($id <= 0) {
  http_response_code(400);
  die();
}

$map = Map::get_by_id($DB, $id);
if (!$map) {
  http_response_code(404);
  die();
}

$map->expand_foreign_keys($DB, 5);
$map->fetch_challenges($DB, true);
$title_str = $map->get_name(true);
$description_str = "";
$campaign_str = $map->campaign->get_name();

$map_image = null;
if (file_exists($map_images_folder . "/" . $map->id . ".webp")) {
  $map_image = "https://" . $_SERVER['HTTP_HOST'] . "/img/map/" . $map->id . "&ext=jpg&scale=6";
}

if (count($map->challenges) > 0) {
  $description_str .= "Challenges:\n";
  foreach ($map->challenges as $challenge) {
    $challenge->expand_foreign_keys($DB, 5);
    $challenge_str = $challenge->get_name(true, true);
    $count_submissions = 0;
    foreach ($challenge->submissions as $submission) {
      if ($submission->is_verified) {
        $count_submissions++;
      }
    }
    $tier_name = $challenge->difficulty->to_tier_name();
    $description_str .= "  - {$challenge_str} ({$tier_name}): {$count_submissions} submissions\n";
  }
  //Remove last newline
  $description_str = substr($description_str, 0, -1);
} else {
  $description_str .= "This map doesn't have any challenges yet.";
}

$real_url = $map->get_url();

if ($map_image) {
  output_image_with_site_embed($real_url, $title_str, $description_str, $map_image, $campaign_str);
} else {
  output_text_embed($real_url, $title_str, $description_str, $campaign_str);
}