<?php

function submission_embed_get_name($submission)
{
  //image name needs to have format:
  //{campaign_id}-{map_id}-{challenge_id}-{submission_id}-{player_id}
  //If one of the objects doesnt exist in the hierarchy, it will be replaced with 0
  $challenge = $submission->challenge;
  $map = $challenge->map;
  $campaign = $challenge->get_campaign();
  $player = $submission->player;
  return $campaign->id . "-" . ($map !== null ? $map->id : 0) . "-" . $challenge->id . "-" . $submission->id . "-" . $player->id;
}
function submission_embed_change($object_id, $object_type, $campaign_id = null)
{
  //When one object in the hierarchy gets changed, all cached embeds referencing this object need to be deleted
  //To do this, build a regex pattern that matches all embeds that reference this object

  //The pattern will be:
  //campaign_id-map_id-challenge_id-submission_id-player_id.jpg

  //$object_type will be either of ("campaign", "map", "challenge", "submission", "player")
  //Based on the object type, fill in the object_id into the pattern

  $object_indices = ["campaign" => 0, "map" => 1, "challenge" => 2, "submission" => 3, "player" => 4];
  $index = $object_indices[$object_type];

  $pattern = "";
  for ($i = 0; $i < 5; $i++) {
    if ($i == $index) {
      $pattern .= $object_id;
    } else {
      $pattern .= "[0-9]+";
    }
    if ($i < 4) {
      $pattern .= "-";
    }
  }

  $pattern .= "\.jpg";

  //List all files in the submission folder
  $base_path = __DIR__ . "/../embed/img/submission";
  $arr = scandir($base_path);
  if ($arr === false) {
    log_error("Failed to list files in $base_path", "Embed");
    return;
  }
  $files = array_diff($arr, array('.', '..'));
  foreach ($files as $file) {
    if (preg_match("/$pattern/", $file)) {
      unlink($base_path . "/" . $file);
    }
  }

  // If the object type is campaign, also delete the collage image
  if ($object_type == "map" && $campaign_id !== null) {
    campaign_collage_embed_change($campaign_id);
  }

  //Log what has happened
  log_debug("Deleted all embeds referencing '$object_type' with id $object_id", "Embed");
}

function campaign_collage_embed_change($campaign_id)
{
  log_debug("Deleted collage image for campaign id $campaign_id", "Campaign");
  $folder = dirname(__FILE__) . '/../embed/img/campaign-collage';
  $file = $folder . "/" . $campaign_id . ".webp";
  if (file_exists($file)) {
    unlink($file);
    return true;
  }
  return false;
}

