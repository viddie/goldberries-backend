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
  $params = getGamebananaParameters($url);
  $api_url = gamebanana_api_url($params['itemtype'], $params['itemid'], '_sName,_aSubmitter');
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
  if (!isset($json['_sName']) || !isset($json['_aSubmitter'])) {
    die_json(500, "Unexpected response from GameBanana API");
  }

  return array(
    "name" => $json['_sName'],
    "author" => $json['_aSubmitter']['_sName'] ?? null,
    "authorId" => $json['_aSubmitter']['_idRow'] ?? null
  );
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