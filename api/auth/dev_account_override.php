<?php

require_once('../api_bootstrap.inc.php');

// Dev-only: lists accounts for the dev account override (see get_dev_account_override)

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$real_account = get_user_data(false);
if ($real_account === null) {
  die_json(401, "Not logged in");
}
if (!is_dev_account_override_allowed($real_account)) {
  die_json(403, "Not authorized");
}

$query = "SELECT account.id, account.role, account.is_suspended,
    player.id AS player_id, player.name AS player_name,
    claimed_player.id AS claimed_player_id, claimed_player.name AS claimed_player_name
  FROM account
  LEFT JOIN player ON account.player_id = player.id
  LEFT JOIN player claimed_player ON account.claimed_player_id = claimed_player.id
  ORDER BY account.id";
$result = pg_query_params_or_die($DB, $query, [], "Failed to fetch accounts");

// Minimal account objects, shaped like regular accounts so the frontend can use the same helpers
$accounts = [];
while ($row = pg_fetch_assoc($result)) {
  $accounts[] = [
    'id' => intval($row['id']),
    'role' => intval($row['role']),
    'is_suspended' => $row['is_suspended'] === 't',
    'player' => $row['player_id'] === null ? null : ['id' => intval($row['player_id']), 'name' => $row['player_name']],
    'claimed_player' => $row['claimed_player_id'] === null ? null : ['id' => intval($row['claimed_player_id']), 'name' => $row['claimed_player_name']],
  ];
}

$override = get_dev_account_override($real_account);
api_write([
  'real_account_id' => $real_account->id,
  'override_account_id' => $override?->id,
  'accounts' => $accounts,
]);
