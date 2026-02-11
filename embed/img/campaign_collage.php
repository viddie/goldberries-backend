<?php

require_once(dirname(__FILE__) . '/../../bootstrap.inc.php');
require_once(dirname(__FILE__) . '/../embed_include.php');

// Constants
$DB = db_connect();
$map_images_folder = dirname(__FILE__) . '/../../img/map';
$campaign_collage_folder = dirname(__FILE__) . '/campaign-collage';
$collage_images = [
  ['maps' => 8, 'images' => 4],
  ['maps' => 15, 'images' => 9],
  ['maps' => 99999, 'images' => 16],
];
$allowed_extensions = ['webp', 'png', 'jpg'];

// Extract parameters
$id = intval($_REQUEST['id']);
if ($id <= 0) {
  http_response_code(400);
  die();
}
$scale = isset($_REQUEST['scale']) ? max(1, min(12, intval($_REQUEST['scale']))) : 6;
$ext = isset($_REQUEST['ext']) ? strtolower($_REQUEST['ext']) : 'webp';
if (!in_array($ext, $allowed_extensions)) {
  die_json(400, "Invalid image extension");
}

// Logic
$campaign = Campaign::get_by_id($DB, $id);
if (!$campaign) {
  http_response_code(404);
  die();
}

// Check for cached image
$image = get_campaign_collage_image($campaign, $scale);
if ($image !== null) {
  output_image($image, $ext);
  exit();
}

// Generate new image
$campaign->expand_foreign_keys($DB, 5);
$campaign->fetch_maps($DB, true, false, true);
$maps_not_archived = [];
foreach ($campaign->maps as $map) {
  if (!$map->is_archived) {
    $maps_not_archived[] = $map;
  }
}
$count_maps_not_archived = count($maps_not_archived);

if ($count_maps_not_archived === 0) {
  // No maps to show
  http_response_code(204);
  die();
}


//Determine collage size
$max_images = 0;
foreach ($collage_images as $entry) {
  if ($count_maps_not_archived <= $entry['maps']) {
    $max_images = $entry['images'];
    break;
  }
}

$image = generate_collage_image($maps_not_archived, $max_images, $scale);
if ($image) {
  save_campaign_collage_image($campaign, $image, $scale);
  output_image($image, $ext);
  exit();
}

http_response_code(500);

function output_image($image, $ext)
{
  switch ($ext) {
    case 'png':
      header('Content-Type: image/png');
      imagepng($image);
      break;
    case 'jpg':
      header('Content-Type: image/jpeg');
      imagejpeg($image, null, 80);
      break;
    case 'webp':
    default:
      header('Content-Type: image/webp');
      imagewebp($image, null, 80);
      break;
  }
  imagedestroy($image);
}
