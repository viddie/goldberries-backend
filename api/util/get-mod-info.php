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

//Check if the url is a valid gamebanana url
if (!preg_match('/^https:\/\/gamebanana.com\/(mods|wips)\/[0-9]+/', $url)) {
  die_json(400, "Invalid Gamebanana URL. Expected format: https://gamebanana.com/mods/<id> or https://gamebanana.com/wips/<id>");
}

$modData = getModData($url);
api_write($modData);
#endregion

#region Utility Functions

function getModData($url)
{
  $apiBaseUrl = "https://api.gamebanana.com/Core/Item/Data?fields=name,Owner().name,userid";
  $params = getGamebananaParameters($url);
  $apiUrl = $apiBaseUrl . "&itemtype=" . $params["itemtype"] . "&itemid=" . $params["itemid"];

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

  //parse as json
  $json = json_decode($gbResponse, true);
  $response = array(
    "name" => $json[0],
    "author" => $json[1],
    "authorId" => $json[2]
  );

  return $response;
}

function getGamebananaParameters($url)
{
  //URLs look like: https://gamebanana.com/mods/424541 or https://gamebanana.com/wips/83276
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