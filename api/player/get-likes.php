<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

if (!isset($_REQUEST['player_id'])) {
  die_json(400, "Missing player_id");
}

$player_id = intval($_REQUEST['player_id']);
if ($player_id <= 0) {
  die_json(400, "Invalid player_id");
}

// Verify player exists
$player = Player::get_by_id($DB, $player_id, 1, false);
if ($player === false) {
  die_json(404, "Player not found");
}

$result = Like::getPlayerLikes($DB, $player_id);
api_write($result);
#endregion
