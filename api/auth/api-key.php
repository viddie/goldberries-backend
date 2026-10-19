<?php

require_once('../api_bootstrap.inc.php');

$account = get_user_data();
check_access($account, false);
// Prevents API keys from creating keys with more scopes than they have themselves
reject_api_keys($account);


#region GET Request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $target_account = get_target_account($DB, $account, $_REQUEST['account_id'] ?? null);
  api_write(ApiKey::find_all_for_account($DB, $target_account->id));
  exit();
}
#endregion

#region POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $data = parse_post_body_as_json();
  if (!is_array($data)) {
    die_json(400, "Invalid request body");
  }

  if (isset($data['id'])) {
    // Update an existing key
    $api_key = ApiKey::get_by_id($DB, intval($data['id']));
    if ($api_key === false) {
      die_json(404, "API key not found");
    }
    $target_account = get_target_account($DB, $account, $api_key->account_id);

    $changes = [];
    if (array_key_exists('name', $data)) {
      $api_key->name = validate_api_key_name($data['name']);
      check_api_key_name_unique($DB, $target_account->id, $api_key->name, $api_key->id);
      $changes[] = "name";
    }
    if (array_key_exists('scopes', $data)) {
      $api_key->scopes = validate_api_key_scopes($data['scopes'], $target_account);
      $changes[] = "scopes";
    }
    if (isset($data['reroll']) && $data['reroll'] === true) {
      $api_key->key = ApiKey::generate_key();
      $changes[] = "key";
    }

    if (!$api_key->update($DB)) {
      die_json(500, "Failed to update API key");
    }
    $changes_str = implode(", ", $changes);
    $player_name = $account->player?->name ?? "<No Player>";
    log_info("'{$player_name}' updated {$api_key} of {$target_account} (changed: {$changes_str})", "Account");
    api_write($api_key);
    exit();
  }

  // Create a new key
  $target_account = get_target_account($DB, $account, $data['account_id'] ?? null);
  if (count(ApiKey::find_all_for_account($DB, $target_account->id)) >= ApiKey::$MAX_KEYS_PER_ACCOUNT) {
    die_json(400, "An account can't have more than " . ApiKey::$MAX_KEYS_PER_ACCOUNT . " API keys");
  }

  $api_key = new ApiKey();
  $api_key->account_id = $target_account->id;
  $api_key->name = validate_api_key_name($data['name'] ?? null);
  check_api_key_name_unique($DB, $target_account->id, $api_key->name);
  $api_key->scopes = validate_api_key_scopes($data['scopes'] ?? null, $target_account);
  $api_key->key = ApiKey::generate_key();
  $api_key->date_created = new JsonDateTime();

  if (!$api_key->insert($DB)) {
    die_json(500, "Failed to create API key");
  }
  $player_name = $account->player?->name ?? "<No Player>";
  log_info("'{$player_name}' created {$api_key} for {$target_account}", "Account");
  api_write($api_key);
  exit();
}
#endregion

#region DELETE Request
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
  if (!isset($_REQUEST['id'])) {
    die_json(400, "Missing id");
  }
  $api_key = ApiKey::get_by_id($DB, intval($_REQUEST['id']));
  if ($api_key === false) {
    die_json(404, "API key not found");
  }
  $target_account = get_target_account($DB, $account, $api_key->account_id);

  if (!$api_key->delete($DB)) {
    die_json(500, "Failed to revoke API key");
  }
  $player_name = $account->player?->name ?? "<No Player>";
  log_info("'{$player_name}' revoked {$api_key} of {$target_account}", "Account");
  http_response_code(200);
  exit();
}
#endregion

die_json(405, 'Method Not Allowed');


#region Utility Functions
// Resolves the account whose keys are being managed. Managing keys of other accounts requires verifier+
function get_target_account($DB, $account, $account_id)
{
  global $VERIFIER;

  if ($account_id === null || intval($account_id) === $account->id) {
    return $account;
  }

  check_role($account, $VERIFIER);
  $target = Account::get_by_id($DB, intval($account_id));
  if ($target === false) {
    die_json(404, "Account not found");
  }
  if (!can_modify_account($account, $target)) {
    die_json(403, "You cannot manage the API keys of this account");
  }
  return $target;
}

function validate_api_key_name($name): string
{
  if (!is_string($name)) {
    die_json(400, "Missing name");
  }
  $name = trim($name);
  if ($name === "" || strlen($name) > ApiKey::$MAX_NAME_LENGTH) {
    die_json(400, "Name needs to be between 1 and " . ApiKey::$MAX_NAME_LENGTH . " characters long");
  }
  return $name;
}

// Names are compared case-insensitively
function check_api_key_name_unique($DB, int $account_id, string $name, ?int $exclude_id = null)
{
  foreach (ApiKey::find_all_for_account($DB, $account_id) as $existing) {
    if ($existing->id === $exclude_id) {
      continue;
    }
    if (mb_strtolower($existing->name) === mb_strtolower($name)) {
      die_json(400, "An API key with the name '{$name}' already exists");
    }
  }
}

function validate_api_key_scopes($scopes, $target_account): int
{
  if (!is_int($scopes) || $scopes < 0) {
    die_json(400, "Invalid scopes");
  }
  if (($scopes & ~ApiKey::$SCOPE_ALL) !== 0) {
    die_json(400, "Unknown scopes");
  }
  if (($scopes & ~ApiKey::allowed_scopes_for_role($target_account->role)) !== 0) {
    die_json(403, "This account is not allowed to use some of the requested scopes");
  }
  return $scopes;
}
#endregion
