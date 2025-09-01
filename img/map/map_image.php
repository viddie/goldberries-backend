<?php

require_once(dirname(__FILE__) . '/../../bootstrap.inc.php');
$map_folder = dirname(__FILE__); // same folder as script
$unknownFile = $map_folder . '/unknown.webp';
$allowedExtensions = ['png', 'jpg', 'gif', 'webp']; //Allowed for manual upload, but need to be reformatted to webp and resized to 320x180
$targetWidth = 320;
$targetHeight = 180;
$savedWidth = 1920;
$savedHeight = 1080;
$savedScale = 6;

// Input parameters
$id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
$ext = isset($_REQUEST['ext']) ? $_REQUEST['ext'] : null;
$scale = isset($_REQUEST['scale']) ? intval($_REQUEST['scale']) : $savedScale;
$throw = isset($_REQUEST['throw']) ? $_REQUEST['throw'] : null;

//For debugging, attach parameters to the header

if ($id <= 0) {
  http_response_code(400);
  die();
}

if ($scale < 1 || $scale > 12) {
  $scale = $savedScale;
}

// Check if extension is valid
if ($ext !== null && !in_array(strtolower($ext), $allowedExtensions)) {
  http_response_code(400);
  die();
}

// Find file by trying known extensions
$foundFile = null;
foreach ($allowedExtensions as $ext) {
  $path = $map_folder . "/" . $id . "." . $ext;
  if (file_exists($path)) {
    $foundFile = $path;
    break;
  }
}

$is_unknown = false;
if (!$foundFile) {
  if ($throw === "true" || $throw === "1") {
    http_response_code(404);
    die();
  }
  $foundFile = $unknownFile;
  $is_unknown = true;
}

outputImage($foundFile, $ext, $scale, $is_unknown);


// ------------------------------------------------------------
// Functions
// ------------------------------------------------------------

function outputImage($path, $ext, $scale = 1, $is_unknown = false)
{
  global $targetWidth, $targetHeight, $savedScale;

  //Check file if its not the unknown image
  if (!$is_unknown) {
    $path = check_source_image($path);
    if ($path === false) {
      http_response_code(500);
      die();
    }
  }

  //The image is guaranteed to be webp at this point
  $info = getimagesize($path);
  if ($info === false) {
    http_response_code(500);
    die();
  }

  // No scaling & target extension is webp → just stream file contents
  if ($scale === $savedScale && ($ext === null || $ext === 'webp')) {
    header('Content-Type: image/webp');
    echo file_get_contents($path);
    die();
  }

  // Load source image
  $src = imagecreatefromwebp($path);
  $width = imagesx($src);
  $height = imagesy($src);
  $newWidth = $targetWidth * $scale;
  $newHeight = $targetHeight * $scale;

  $dst = imagecreatetruecolor($newWidth, $newHeight);

  // Nearest neighbor scaling
  imagecopyresized($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

  // Output scaled image and set content type header
  switch ($ext) {
    case 'png':
      header('Content-Type: image/png');
      imagepng($dst);
      break;
    case 'jpg':
      header('Content-Type: image/jpeg');
      imagejpeg($dst, null, 90);
      break;
    case 'gif':
      header('Content-Type: image/gif');
      imagegif($dst);
      break;
    case 'webp':
    default:
      header('Content-Type: image/webp');
      imagewebp($dst);
      break;
  }
}

/*
  This function should check if the source image is a valid map image, meaning:
  - It is a webp file (save as webp if its a different format)
  - It has the correct dimensions 320x180 (scale down if its a different size)
  If not, correct the image and return the corrected image path.
*/
function check_source_image($path)
{
  global $savedWidth, $savedHeight;

  // Check image dimensions
  $image_info = getimagesize($path);
  if ($image_info === false) {
    return false;
  }

  list($width, $height) = $image_info;

  //Enforce the image to be 16:9 aspect ratio
  if ($width * 9 !== $height * 16) {
    return false;
  }

  //If ending is already webp and size is 320x180, return original path
  if (preg_match('/\.webp$/i', $path) && $width === $savedWidth && $height === $savedHeight) {
    return $path;
  }

  // Load image based on type
  $mime = $image_info['mime'];
  switch ($mime) {
    case 'image/png':
      $src_image = imagecreatefrompng($path);
      break;
    case 'image/jpeg':
      $src_image = imagecreatefromjpeg($path);
      break;
    case 'image/gif':
      $src_image = imagecreatefromgif($path);
      break;
    case 'image/webp':
      $src_image = imagecreatefromwebp($path);
      break;
    default:
      return false;
  }

  // Scale down to 320x180 using nearest neighbor
  $dst_width = $savedWidth;
  $dst_height = $savedHeight;
  $dst_image = imagecreatetruecolor($dst_width, $dst_height);

  // Preserve transparency for PNG/GIF/WebP
  if (in_array($mime, ['image/png', 'image/gif', 'image/webp'])) {
    imagecolortransparent($dst_image, imagecolorallocatealpha($dst_image, 0, 0, 0, 127));
    imagealphablending($dst_image, false);
    imagesavealpha($dst_image, true);
  }

  // nearest neighbor scaling
  imagecopyresized($dst_image, $src_image, 0, 0, 0, 0, $dst_width, $dst_height, $width, $height);

  // Save image in webp
  $new_path = preg_replace('/\.(png|jpg|jpeg|gif|webp)$/i', '.webp', $path);
  $saved = imagewebp($dst_image, $new_path, 90); // quality 90

  imagedestroy($src_image);
  imagedestroy($dst_image);

  if (!$saved) {
    return false;
  }

  return $new_path;
}