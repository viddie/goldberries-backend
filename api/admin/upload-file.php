<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_access($account, true);
if (!is_news_writer($account)) {
  die_json(403, "Not authorized");
}

$destination_base = "../../";
$destinations = [
  "icon" => "icons",
  "campaign_icon" => "icons/campaigns",
  "post" => "img/post",
  "badge" => "badges",
  "map_image" => "img/map", // new destination
];
$min_role = [
  "icon" => $HELPER,
  "campaign_icon" => $HELPER,
  "post" => $NEWS_WRITER,
  "badge" => $HELPER,
  "map_image" => $HELPER, // new minimum role
];
$allowed_extensions = ["png", "jpg", "gif", "webp"];

//The request has 2 parameters: file and destination
//The destination can be one of the values from above
//The file is the file to be uploaded
if (!isset($_FILES['file']) || !isset($_POST['destination'])) {
  die_json(400, "Missing one or more required parameters: file, destination");
}

$destination = $_POST['destination'];
if (!array_key_exists($destination, $destinations)) {
  die_json(400, "Destination must match one of the following: " . implode(", ", array_keys($destinations)));
}
if ($account->role < $min_role[$destination]) {
  die_json(403, "Not authorized to upload to this destination");
}

$target_destination = $destinations[$destination];
$path = $destination_base . $target_destination . "/";

//Path definitely exists
if (!file_exists($path)) {
  die_json(500, "Destination path does not exist");
}

$file_name = $_POST['file_name'] ?? pathinfo($_FILES['file']['name'], PATHINFO_FILENAME);
//Clean the file name to be only alphanumeric, periods, and hyphens
$file_name = preg_replace('/[^a-zA-Z0-9\.\-]/', '_', $file_name);

$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_extensions)) {
  die_json(400, "File extension not allowed");
}

$full_name = "$file_name.$ext";
$full_path = $path . $full_name;

//Does file already exist?
if (file_exists($full_path)) {
  die_json(400, "File already exists");
}

// Different handling depending on destination
switch ($destination) {
  case "map_image":
    handle_map_image_upload($_FILES['file'], $path, $target_destination, $ext);
    break;

  default:
    handle_default_upload($_FILES['file'], $full_path, $target_destination, $full_name);
    break;
}


/**
 * Default upload handler
 */
function handle_default_upload($file, $full_path, $target_destination, $full_name)
{
  if (move_uploaded_file($file['tmp_name'], $full_path)) {
    api_write([
      "file_name" => $full_name,
      "path" => "/$target_destination/$full_name"
    ]);
  } else {
    die_json(500, "Failed to upload file '" . $file['tmp_name'] . "' to path: $full_path");
  }
}

/**
 * Custom upload handler for map images
 */
function handle_map_image_upload($file, $path, $target_destination, $ext)
{
  global $account;

  // Require map_id parameter
  if (!isset($_POST['map_id']) || !is_numeric($_POST['map_id'])) {
    die_json(400, "Missing or invalid parameter: map_id");
  }
  $map_id = intval($_POST['map_id']);

  // File name is forced to map_id and extension to webp
  $full_name = $map_id . ".webp";
  $full_path = $path . $full_name;

  // Check image dimensions
  $image_info = getimagesize($file['tmp_name']);
  if ($image_info === false) {
    die_json(400, "Invalid image file");
  }

  list($width, $height) = $image_info;

  //Enforce the image to be 16:9 aspect ratio
  if ($width * 9 !== $height * 16) {
    die_json(400, "Invalid image dimensions: expected 16:9 aspect ratio");
  }
  if ($width < 320 || $height < 180) {
    die_json(400, "Invalid image dimensions: minimum size is 320x180");
  }
  // Enforce width and height needing to be an integer multiple of 320x180
  if ($width % 320 !== 0 || $height % 180 !== 0) {
    die_json(400, "Invalid image dimensions: width and height must be integer multiples of 320x180");
  }

  // Load image based on type
  $mime = $image_info['mime'];
  switch ($mime) {
    case 'image/png':
      $src_image = imagecreatefrompng($file['tmp_name']);
      break;
    case 'image/jpeg':
      $src_image = imagecreatefromjpeg($file['tmp_name']);
      break;
    case 'image/gif':
      $src_image = imagecreatefromgif($file['tmp_name']);
      break;
    case 'image/webp':
      $src_image = imagecreatefromwebp($file['tmp_name']);
      break;
    default:
      die_json(400, "Unsupported image type for map_image");
  }

  // Scale using nearest neighbor
  $dst_width = 1920;
  $dst_height = 1080;
  $dst_image = imagecreatetruecolor($dst_width, $dst_height);
  imagecopyresized($dst_image, $src_image, 0, 0, 0, 0, $dst_width, $dst_height, $width, $height);

  // Save image in webp
  $saved = imagewebp($dst_image, $full_path, 90);

  imagedestroy($src_image);
  imagedestroy($dst_image);

  if (!$saved) {
    die_json(500, "Failed to save resized map image");
  }

  // Delete all cached embeds that reference this map
  submission_embed_change($map_id, "map");
  log_info("'" . $account->player->name . "' updated map image for map_id $map_id", "Map");

  api_write([
    "file_name" => $full_name,
    "path" => "/$target_destination/$full_name",
  ]);
}

