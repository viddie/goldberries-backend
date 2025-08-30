<?php

require_once(dirname(__FILE__) . '/../../bootstrap.inc.php');
$map_folder   = dirname(__FILE__); // same folder as script
$unknownFile  = $map_folder . '/unknown.png';
$allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

// Input parameters
$id    = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
$scale = isset($_REQUEST['scale']) ? intval($_REQUEST['scale']) : 6; // 320x180 * 6 = 1920x1080
$throw = isset($_REQUEST['throw']) ? $_REQUEST['throw'] : null;

if ($id <= 0) {
  http_response_code(400);
  die();
}

if ($scale < 1 || $scale > 12) {
  $scale = 6;
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

if (!$foundFile) {
  if ($throw === "true" || $throw === "1") {
    http_response_code(404);
    die();
  }
  $foundFile = $unknownFile;
}

outputImage($foundFile, $scale);


// ------------------------------------------------------------
// Functions
// ------------------------------------------------------------

function outputImage($path, $scale = 1) {
  $info = getimagesize($path);
  if ($info === false) {
    http_response_code(500);
    die();
  }

  $mime = $info['mime'];
  header("Content-Type: $mime");

  // No scaling → just stream file contents
  if ($scale === 1) {
    echo file_get_contents($path);
    die();
  }

  // Load source image
  switch ($mime) {
    case 'image/png':  $src = imagecreatefrompng($path); break;
    case 'image/jpeg': $src = imagecreatefromjpeg($path); break;
    case 'image/gif':  $src = imagecreatefromgif($path); break;
    case 'image/webp': $src = imagecreatefromwebp($path); break;
    default:
      http_response_code(415);
      die();
  }

  $width  = imagesx($src);
  $height = imagesy($src);
  $newWidth  = $width * $scale;
  $newHeight = $height * $scale;

  $dst = imagecreatetruecolor($newWidth, $newHeight);

  // Preserve transparency for PNG/GIF/WebP
  if (in_array($mime, ['image/png', 'image/gif', 'image/webp'])) {
    imagecolortransparent($dst, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
  }

  // Nearest neighbor scaling
  imagecopyresized($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

  // Output scaled image
  switch ($mime) {
    case 'image/png':  imagepng($dst); break;
    case 'image/jpeg': imagejpeg($dst, null, 90); break;
    case 'image/gif':  imagegif($dst); break;
    case 'image/webp': imagewebp($dst); break;
  }
}
