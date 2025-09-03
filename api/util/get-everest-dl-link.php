<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$url = $_REQUEST['url'];

if (!isset($url)) {
  die_json(400, "url is missing");
}

// Validate GameBanana URL
if (!preg_match('/^https:\/\/gamebanana.com\/mods\/[0-9]+/', $url)) {
  die_json(400, "Invalid Gamebanana URL");
}

$params = getGamebananaParameters($url);
$fileId = getFileId($params["itemtype"], $params["itemid"]);

// Everest one-click dl link format: everest:https://gamebanana.com/mmdl/<file_id>,Mod,<mod_id>
$downloadLink = "everest:https://gamebanana.com/mmdl/" . $fileId . "," . $params["itemtype"] . "," . $params["itemid"];
api_write(array("download_url" => $downloadLink));

function getFileId($item_type, $item_id)
{
  $apiUrl = "https://api.gamebanana.com/Core/Item/Data"
    . "?itemtype=" . $item_type
    . "&itemid=" . $item_id
    . "&fields=Files().aFiles()";

  $curl = curl_init();
  curl_setopt_array($curl, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => [
      "Authorization: Bearer " . $_ENV['GB_TOKEN'],
      "Content-Type: application/json"
    ],
  ]);

  $gbResponse = curl_exec($curl);
  curl_close($curl);

  $json = json_decode($gbResponse, true);

  if (!isset($json[0]) || !is_array($json[0])) {
    die_json(500, "Unexpected response from GameBanana API");
  }

  $filesAssoc = $json[0];
  if (count($filesAssoc) === 0) {
    die_json(404, "No files found for this mod");
  }

  // Find the most recent file by _tsDateAdded
  $latestFile = null;
  foreach ($filesAssoc as $fileId => $fileData) {
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
  // URLs look like: https://gamebanana.com/mods/424541
  $url = explode("/", $url);
  $itemtypeRaw = $url[3];
  $itemtype = "";

  if ($itemtypeRaw === "mods") {
    $itemtype = "Mod";
  }

  $itemid = $url[4];

  return array("itemtype" => $itemtype, "itemid" => $itemid);
}
