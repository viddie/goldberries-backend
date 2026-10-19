<?php

require_once('../api_bootstrap.inc.php');

const DEFAULT_COUNT = 100;
const MAX_ROOM_NUMBER = 100000;

#region GET Request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (isset($_REQUEST['id'])) {
    $status = IngameStatus::get_by_id($DB, intval($_REQUEST['id']), 1);
    if ($status === false || !is_account_visible($DB, $status->account_id)) {
      die_json(404, "Ingame status not found");
    }
    IngameStatus::expand_challenges($DB, [$status]);
    api_write($status);
    exit();
  }

  $count = parse_count_param($_REQUEST['count'] ?? null);
  if (isset($_REQUEST['player_id'])) {
    $accounts = Account::find_by_player_id($DB, intval($_REQUEST['player_id']));
    if ($accounts === false || count($accounts) === 0 || $accounts[0]->is_suspended) {
      api_write([]);
      exit();
    }
    $account_id = $accounts[0]->id;
  } else {
    $account = get_user_data();
    check_access($account, false);
    $account_id = $account->id;
  }

  $statuses = IngameStatus::find_latest_for_account($DB, $account_id, $count);
  IngameStatus::expand_challenges($DB, $statuses);
  api_write($statuses);
  exit();
}
#endregion

#region POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $account = get_user_data();
  check_access($account, true);
  check_api_key_scope($account, ApiKey::$SCOPE_INGAME_STATUS);
  check_restriction($account, Account::$RESTRICT_INGAME_STATUS, "You are restricted from posting your ingame status");

  $data = parse_post_body_as_json();
  if (!is_array($data) || array_is_list($data)) {
    die_json(400, "Invalid request body");
  }

  $status = new IngameStatus();
  $status->account_id = $account->id;
  $status->date = new JsonDateTime();
  $event = $data['event'] ?? 'status';
  if (!is_string($event) || !in_array($event, IngameStatus::$EVENTS, true)) {
    die_json(400, "event must be one of: " . implode(", ", IngameStatus::$EVENTS));
  }
  $status->event = $event;
  $status->challenge_id = parse_nullable_int($data, 'challenge_id', 1, PHP_INT_MAX);
  $status->map_name = parse_nullable_string($data, 'map_name');
  $status->room_number = parse_nullable_int($data, 'room_number', 0, MAX_ROOM_NUMBER);
  $status->max_room_number = parse_nullable_int($data, 'max_room_number', 0, MAX_ROOM_NUMBER);
  $status->room_name = parse_nullable_string($data, 'room_name');
  $status->custom_room_name = parse_nullable_string($data, 'custom_room_name');
  $status->debug_room_name = parse_nullable_string($data, 'debug_room_name');
  // A custom room name only exists for rooms on the path
  if ($status->room_name === null) {
    $status->custom_room_name = null;
  }
  $status->session_pb_room_number = parse_nullable_int($data, 'session_pb_room_number', 0, MAX_ROOM_NUMBER);
  $status->overall_pb_room_number = parse_nullable_int($data, 'overall_pb_room_number', 0, MAX_ROOM_NUMBER);

  $in_golden_run = $data['in_golden_run'] ?? false;
  if (!is_bool($in_golden_run)) {
    die_json(400, "in_golden_run must be a boolean");
  }
  $status->in_golden_run = $in_golden_run;

  if ($status->challenge_id !== null) {
    $challenge = Challenge::get_by_id($DB, $status->challenge_id, 1);
    if ($challenge === false) {
      die_json(400, "Challenge with id {$status->challenge_id} does not exist");
    }
    // map_name is only stored if the challenge couldn't be determined
    $status->map_name = null;
  }

  if (!$status->insert($DB)) {
    die_json(500, "Failed to create ingame status");
  }
  IngameStatus::prune_for_account($DB, $account->id);

  IngameStatus::expand_challenges($DB, [$status]);
  api_write($status);
  exit();
}
#endregion

#region DELETE Request
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
  $account = get_user_data();
  check_access($account, false);
  check_api_key_scope($account, ApiKey::$SCOPE_INGAME_STATUS);

  if (isset($_REQUEST['all']) && $_REQUEST['all'] === 'true') {
    IngameStatus::delete_all_for_account($DB, $account->id);
    http_response_code(200);
    exit();
  }

  if (!isset($_REQUEST['id'])) {
    die_json(400, "Missing id or all=true");
  }
  $status = IngameStatus::get_by_id($DB, intval($_REQUEST['id']), 1);
  if ($status === false) {
    die_json(404, "Ingame status not found");
  }
  if ($status->account_id !== $account->id) {
    check_role($account, $VERIFIER);
    reject_api_keys($account);
  }

  if (!$status->delete($DB)) {
    die_json(500, "Failed to delete ingame status");
  }
  if ($status->account_id !== $account->id) {
    log_info("'{$account->player->name}' deleted {$status}", "Ingame Status");
  }
  http_response_code(200);
  exit();
}
#endregion

die_json(405, 'Method Not Allowed');


#region Utility Functions
function is_account_visible($DB, int $account_id): bool
{
  $account = Account::get_by_id($DB, $account_id, 1);
  return $account !== false && !$account->is_suspended;
}

function parse_count_param($value): int
{
  if ($value === null) {
    return DEFAULT_COUNT;
  }
  $count = intval($value);
  if ($count < 1 || $count > IngameStatus::$MAX_ENTRIES_PER_ACCOUNT) {
    die_json(400, "count must be between 1 and " . IngameStatus::$MAX_ENTRIES_PER_ACCOUNT);
  }
  return $count;
}

function parse_nullable_int(array $data, string $field, int $min, int $max): ?int
{
  $value = $data[$field] ?? null;
  if ($value === null) {
    return null;
  }
  if (!is_int($value) || $value < $min || $value > $max) {
    die_json(400, "{$field} must be an integer between {$min} and {$max}");
  }
  return $value;
}

function parse_nullable_string(array $data, string $field): ?string
{
  $value = $data[$field] ?? null;
  if ($value === null) {
    return null;
  }
  if (!is_string($value)) {
    die_json(400, "{$field} must be a string");
  }
  $value = trim($value);
  if (strlen($value) > IngameStatus::$MAX_STRING_LENGTH) {
    die_json(400, "{$field} can't be longer than " . IngameStatus::$MAX_STRING_LENGTH . " characters");
  }
  return $value === "" ? null : $value;
}
#endregion
