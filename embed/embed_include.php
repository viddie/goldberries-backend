<?php

function output_text_embed($url, $title, $description, $site_name)
{
  $template = file_get_contents("text-embed-template.html");
  $template = str_replace("{{url}}", htmlspecialchars($url), $template);
  $template = str_replace("{{title}}", htmlspecialchars($title), $template);
  $template = str_replace("{{description}}", htmlspecialchars($description), $template);
  $template = str_replace("{{site_name}}", htmlspecialchars($site_name), $template);
  echo $template;
}

function output_image_embed($url, $title, $description, $image_url)
{
  $template = file_get_contents("image-embed-template.html");
  $template = str_replace("{{url}}", htmlspecialchars($url), $template);
  $template = str_replace("{{title}}", htmlspecialchars($title), $template);
  $template = str_replace("{{description}}", htmlspecialchars($description), $template);
  $template = str_replace("{{image_url}}", htmlspecialchars($image_url), $template);
  echo $template;
}

function output_image_with_site_embed($url, $title, $description, $image_url, $site_name)
{
  $template = file_get_contents("image-with-site-embed-template.html");
  $template = str_replace("{{url}}", htmlspecialchars($url), $template);
  $template = str_replace("{{title}}", htmlspecialchars($title), $template);
  $template = str_replace("{{description}}", htmlspecialchars($description), $template);
  $template = str_replace("{{image_url}}", htmlspecialchars($image_url), $template);
  $template = str_replace("{{site_name}}", htmlspecialchars($site_name), $template);
  echo $template;
}

/*
 * Generates a collage image from an array of map images.
 * If the maps list contains more than $max_images, selects the images evenly spread.
 * Example: 100 images @ max_images=4 -> selects 0, 33, 66, 99
 * Tries to fit the max_images into a grid as square as possible.
 * Resulting image is 320x180 multiplied by the $scale factor.
 * 
 * Example with $scale=2 and $max_images=4:
 * - image size: 640x360
 * - grid arrangement: 2x2
 * - each sub image size: 320x180
 */
function generate_collage_image($maps, $max_images = 4, $scale = 1)
{
  $num_maps = count($maps);
  if ($num_maps == 0) {
    return null;
  }

  // Case: only 1 map
  if ($num_maps == 1) {
    return get_map_image($maps[0]);
  }
  // Case: 2 maps
  if ($num_maps == 2) {
    $max_images = 2;
  }
  // Case: 3 maps
  if ($num_maps == 3) {
    $max_images = 3;
  }

  // Determine how many images to use
  $num_images = min($num_maps, $max_images);

  // Select evenly spread images
  if ($num_maps > $max_images) {
    $indices = [];
    for ($i = 0; $i < $num_images; $i++) {
      $indices[] = intval(round($i * ($num_maps - 1) / ($num_images - 1)));
    }
    $selected_maps = array_map(fn($idx) => $maps[$idx], $indices);
  } else {
    $selected_maps = $maps;
  }

  // Determine grid layout (rows x cols) as square as possible
  $cols = ceil(sqrt($num_images));
  $rows = ceil($num_images / $cols);

  // Sub-image size (base 320x180)
  $sub_width = 320 * $scale;
  $sub_height = 180 * $scale;

  // Create final canvas
  $canvas_width = $cols * $sub_width;
  $canvas_height = $rows * $sub_height;
  $canvas = imagecreatetruecolor($canvas_width, $canvas_height);

  // Fill background with black
  $black = imagecolorallocate($canvas, 0, 0, 0);
  imagefill($canvas, 0, 0, $black);

  // Copy each sub-image using nearest neighbor scaling
  foreach ($selected_maps as $i => $map) {
    $img = get_map_image($map);
    if (!$img)
      continue;

    $col = $i % $cols;
    $row = intdiv($i, $cols);

    // Copy using nearest neighbor
    imagecopyresized(
      $canvas,
      $img,
      $col * $sub_width,
      $row * $sub_height,
      0,
      0,
      $sub_width,
      $sub_height,
      imagesx($img),
      imagesy($img)
    );

    imagedestroy($img);
  }

  return $canvas;
}


function get_map_image($map)
{
  $map_images_folder = dirname(__FILE__) . '/../img/map';
  if (file_exists($map_images_folder . "/" . $map->id . ".webp")) {
    //Read webp image and return it to the calling function. this function doesnt produce an output.
    return imagecreatefromwebp($map_images_folder . "/" . $map->id . ".webp");
  }
  //Return unknown.webp
  return imagecreatefromwebp($map_images_folder . "/unknown.webp");
}