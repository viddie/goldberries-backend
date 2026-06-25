<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$type = $_GET['type'] ?? null;
if ($type === null) {
  die_json(400, "Missing parameter 'type'");
}

if (!in_array($type, ['campaign', 'map', 'challenge', 'submission', 'player'])) {
  die_json(400, "Invalid parameter 'type'");
}

$config = get_data_dump_config($type);
$result = pg_query_params_or_die($DB, $config['query'], [], "Failed to fetch data dump for type '$type'");
$objects = parse_data_dump_rows($result, $config['type']);

api_write($objects);
#endregion

#region Functions
function get_data_dump_config(string $type): array
{
  if ($type === 'campaign') {
    return [
      'query' => "
        SELECT DISTINCT campaign.*
        FROM campaign
        JOIN map ON map.campaign_id = campaign.id
        JOIN challenge ON challenge.map_id = map.id
        WHERE challenge.is_rejected = FALSE
        ORDER BY campaign.id ASC
      ",
      'type' => 'campaign',
    ];
  }

  if ($type === 'map') {
    return [
      'query' => "
        SELECT DISTINCT map.*
        FROM map
        JOIN challenge ON challenge.map_id = map.id
        WHERE challenge.is_rejected = FALSE
        ORDER BY map.id ASC
      ",
      'type' => 'map',
    ];
  }

  if ($type === 'challenge') {
    return [
      'query' => "
        SELECT *
        FROM challenge
        WHERE is_rejected = FALSE
        ORDER BY id ASC
      ",
      'type' => 'challenge',
    ];
  }

  if ($type === 'submission') {
    return [
      'query' => "
        SELECT submission.*
        FROM submission
        LEFT JOIN account ON account.player_id = submission.player_id
        WHERE submission.is_verified = TRUE
          AND (account.is_suspended = FALSE OR account.is_suspended IS NULL)
        ORDER BY submission.id ASC
      ",
      'type' => 'submission',
    ];
  }

  return [
    'query' => "
      SELECT *
      FROM view_players
      WHERE player_account_is_suspended = FALSE OR player_account_is_suspended IS NULL
      ORDER BY player_id ASC
    ",
    'type' => 'player',
  ];
}

function parse_data_dump_rows($result, string $type): array
{
  $objects = [];

  while ($row = pg_fetch_assoc($result)) {
    if ($type === 'campaign') {
      $object = new Campaign();
      $object->apply_db_data($row);
      $objects[] = $object;
      continue;
    }

    if ($type === 'map') {
      $object = new Map();
      $object->apply_db_data($row);
      $objects[] = $object;
      continue;
    }

    if ($type === 'challenge') {
      $object = new Challenge();
      $object->apply_db_data($row);
      $objects[] = $object;
      continue;
    }

    if ($type === 'submission') {
      $object = new Submission();
      $object->apply_db_data($row);
      $objects[] = $object;
      continue;
    }

    $object = new Player();
    $object->apply_db_data($row, 'player_');
    $objects[] = $object;
  }

  return $objects;
}
#endregion
