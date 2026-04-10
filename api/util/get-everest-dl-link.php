<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$url = $_REQUEST['url'];

if (!isset($url)) {
  die_json(400, "url is missing");
}

// Validate GameBanana URL
if (!preg_match('/^https:\/\/gamebanana.com\/(mods|wips)\/[0-9]+/', $url)) {
  die_json(400, "Invalid Gamebanana URL. Expected format: https://gamebanana.com/mods/<id> or https://gamebanana.com/wips/<id>");
}

$params = getGamebananaParameters($url);
$fileId = getFileId($params["itemtype"], $params["itemid"]);

// Everest one-click dl link format: everest:https://gamebanana.com/mmdl/<file_id>,Mod,<mod_id>
$downloadLink = "everest:https://gamebanana.com/mmdl/" . $fileId . "," . $params["itemtype"] . "," . $params["itemid"];
api_write(array("download_url" => $downloadLink));
#endregion

#region Utility Functions

function getFileId($item_type, $item_id)
{
  $api_url = gamebanana_api_url($item_type, $item_id, '_aFiles');
  $response = fetch_data_response($api_url, 5, 15);

  if ($response['body'] === false) {
    die_json(502, "Failed to reach GameBanana API");
  }
  if ($response['http_code'] === 404) {
    die_json(404, "Mod not found on GameBanana");
  }
  if ($response['http_code'] >= 400) {
    die_json(502, "GameBanana returned HTTP {$response['http_code']}");
  }

  $json = json_decode($response['body'], true);
  if (!isset($json['_aFiles']) || !is_array($json['_aFiles']) || count($json['_aFiles']) === 0) {
    die_json(404, "No files found for this mod");
  }

  // Find the most recent file by _tsDateAdded
  $latestFile = null;
  foreach ($json['_aFiles'] as $fileData) {
    if ($latestFile === null || $fileData['_tsDateAdded'] > $latestFile['_tsDateAdded']) {
      $latestFile = $fileData;
    }
  }

  if (!$latestFile || !isset($latestFile['_idRow'])) {
    die_json(500, "Could not determine latest file");
  }

  return $latestFile['_idRow'];
}

function getGamebananaParameters($url)
{
  // URLs look like: https://gamebanana.com/mods/424541 or https://gamebanana.com/wips/83276
  $url = explode("/", $url);
  $itemtypeRaw = $url[3];
  $itemtype = "";

  if ($itemtypeRaw === "mods") {
    $itemtype = "Mod";
  } else if ($itemtypeRaw === "wips") {
    $itemtype = "Wip";
  }

  $itemid = $url[4];

  return array("itemtype" => $itemtype, "itemid" => $itemid);
}
#endregion
