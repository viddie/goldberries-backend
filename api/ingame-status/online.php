<?php

require_once('../api_bootstrap.inc.php');

const DEFAULT_RECENCY_MINUTES = 5;
const MAX_RECENCY_MINUTES = 1440;
const DEFAULT_COUNT = 100;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$recency = parse_int_param('recency', DEFAULT_RECENCY_MINUTES, 1, MAX_RECENCY_MINUTES);
$count = parse_int_param('count', DEFAULT_COUNT, 1, IngameStatus::$MAX_ENTRIES_PER_ACCOUNT);

$account_filter = null;
if (isset($_REQUEST['player_id'])) {
  $accounts = Account::find_by_player_id($DB, intval($_REQUEST['player_id']));
  if ($accounts === false || count($accounts) === 0) {
    api_write([]);
    exit();
  }
  $account_filter = $accounts[0]->id;
}

$grouped = IngameStatus::find_online($DB, $recency, $count, $account_filter);
if (count($grouped) === 0) {
  api_write([]);
  exit();
}

$accounts = [];
foreach (Account::get_many_by_id($DB, array_keys($grouped), 1) as $account) {
  if ($account->is_suspended || $account->player_id === null) {
    continue;
  }
  $accounts[$account->id] = $account;
}

$players = [];
if (count($accounts) > 0) {
  $player_ids = array_map(fn($account) => $account->player_id, array_values($accounts));
  foreach (Player::get_many_by_id($DB, $player_ids, 1) as $player) {
    $players[$player->id] = $player;
  }
}

$result = [];
$all_statuses = [];
foreach ($grouped as $account_id => $statuses) {
  $account = $accounts[$account_id] ?? null;
  if ($account === null || !isset($players[$account->player_id])) {
    continue;
  }
  $player = $players[$account->player_id];
  $player->apply_account_data($account);

  $result[] = [
    'player' => $player,
    'stream_url' => $account->stream_url,
    'last_activity' => $statuses[0]->date,
    'statuses' => $statuses,
  ];
  array_push($all_statuses, ...$statuses);
}

IngameStatus::expand_challenges($DB, $all_statuses);
api_write($result);


#region Utility Functions
function parse_int_param(string $name, int $default, int $min, int $max): int
{
  if (!isset($_REQUEST[$name])) {
    return $default;
  }
  $value = intval($_REQUEST[$name]);
  if ($value < $min || $value > $max) {
    die_json(400, "{$name} must be between {$min} and {$max}");
  }
  return $value;
}
#endregion
