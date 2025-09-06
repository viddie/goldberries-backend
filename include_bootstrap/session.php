<?php

session_start();

function get_discord_url()
{
  $state = generate_random_token(32);
  $_SESSION['state'] = $state;
  return constant('DISCORD_OAUTH_URL') . '&state=' . $state;
}

function get_discord_token_url()
{
  return constant('DISCORD_TOKEN_URL');
}

//method = mail, discord
function successful_login($account, $method)
{
  global $DB;

  $token = create_session_token();

  $session = new Session();
  $session->token = $token;
  $session->account_id = $account->id;
  $session->created = new JsonDateTime();
  if (!$session->insert($DB)) {
    return false;
  }

  $account->expand_foreign_keys($DB);
  $methodStr = $method === "mail" ? "email" : "Discord";
  log_debug("User logged in to {$account} via {$methodStr}", "Login");

  return true;
}

function logout()
{
  global $DB;

  $account = get_user_data();
  if ($account == null) {
    return false;
  }

  $token = get_token();
  //Currently, this check is unnecessary, as the token is always set if the account is set
  //In the future, get_user_data() for an API token might return an account, while not owning a session token
  if ($token === null) {
    return false;
  }

  $sessions = Session::find_by_token($DB, $token);
  if ($sessions === false) {
    return false;
  }
  if (count($sessions) === 0) {
    return false;
  }

  $session = $sessions[0];
  if ($session->account_id !== $account->id) {
    return false;
  }

  if ($session->delete($DB)) {
    //Unset cookie
    setcookie('token', '', time() - 3600, '/');
    unset($_COOKIE['token']);
    session_destroy();
    return true;
  } else {
    return false;
  }
}

function create_session_token($length = 32)
{
  $token = generate_random_token($length);
  //Set cookie for 365 days
  setcookie('token', $token, time() + 60 * 60 * 24 * 365, '/');
  return $token;
}
function generate_random_token($length)
{
  return bin2hex(random_bytes($length));
}


function get_token()
{
  if (!isset($_COOKIE['token']))
    return null;
  return $_COOKIE['token'];
}

function get_user_data()
{
  global $DB;

  $account = null;

  // Check based on session token in cookie
  $token = get_token();
  if ($token !== null) {
    $account = Account::find_by_session_token($DB, $token);
    if ($account == false) {
      return null;
    }
  }

  // If not available, check for header: X-API-Key
  if ($account === null && isset($_SERVER['HTTP_X_API_KEY'])) {
    $api_key = $_SERVER['HTTP_X_API_KEY'];
    $account = Account::find_by_api_key($DB, $api_key);
    if ($account == false) {
      return null;
    }
    $account->using_api_key = true;
  }

  if ($account === null) {
    return null;
  }

  // Ignore banned users
  if (is_suspended($account)) {
    return null;
  }

  $account->expand_foreign_keys($DB);
  return $account;
}

// === Utility Functions ===

function is_logged_in()
{
  return get_user_data() != null;
}

function valid_password($password)
{
  return strlen($password) >= 8;
}

function valid_email($email)
{
  return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function is_news_writer($account = null)
{
  global $NEWS_WRITER;
  $account = $account ?? get_user_data();
  if ($account == null) {
    return false;
  }
  return $account->role >= $NEWS_WRITER;
}

function is_helper($account = null)
{
  global $HELPER;
  $account = $account ?? get_user_data();
  if ($account == null) {
    return false;
  }
  return $account->role >= $HELPER;
}

function is_verifier($account = null)
{
  global $VERIFIER;
  $account = $account ?? get_user_data();
  if ($account == null) {
    return false;
  }
  return $account->role >= $VERIFIER;
}

function is_admin($account = null)
{
  global $ADMIN;
  $account = $account ?? get_user_data();
  if ($account == null) {
    return false;
  }
  return $account->role === $ADMIN;
}

function can_modify_account($account, $target)
{
  global $VERIFIER, $ADMIN;

  if ($account === null || $target === null) {
    return false;
  }

  //Anything below VERIFIER can't modify anything
  if ($account->role < $VERIFIER) {
    return false;
  }

  //VERIFIERs can only modify lower roles
  if ($account->role === $VERIFIER && $account->role > $target->role) {
    return true;
  }

  //Admins can modify anyone
  if ($account->role === $ADMIN) {
    return true;
  }

  //Just in case something goes wrong and someone somehow gets a higher role number than $ADMIN
  return false;
}

function can_assign_role($account, $role)
{
  global $USER, $EX_HELPER, $NEWS_WRITER, $HELPER, $VERIFIER, $ADMIN;

  if ($account === null) {
    return false;
  }

  //Anything below VERIFIER can't modify anything
  if ($account->role < $VERIFIER) {
    return false;
  }

  //VERIFIERs can only assign user, ex-helper and helper
  if ($account->role === $VERIFIER && array_search($role, [$USER, $NEWS_WRITER, $EX_HELPER, $HELPER]) !== false) {
    return true;
  }

  //Admins can assign any role
  if ($account->role === $ADMIN) {
    return true;
  }

  return false;
}

function get_role_name($role)
{
  global $USER, $EX_HELPER, $EX_VERIFIER, $EX_ADMIN, $NEWS_WRITER, $HELPER, $VERIFIER, $ADMIN;

  switch ($role) {
    case $USER:
      return "User";
    case $EX_HELPER:
      return "Ex-Helper";
    case $EX_VERIFIER:
      return "Ex-Verifier";
    case $EX_ADMIN:
      return "Ex-Admin";
    case $NEWS_WRITER:
      return "News Writer";
    case $HELPER:
      return "Helper";
    case $VERIFIER:
      return "Verifier";
    case $ADMIN:
      return "Admin";
    default:
      return "Unknown";
  }
}

function helper_can_delete($date_time)
{
  //Can only delete objects that are less than 24 hours old
  return $date_time->getTimestamp() > time() - 86400;
}

function is_suspended($account = null)
{
  $account = $account ?? get_user_data();
  if ($account == null) {
    return false;
  }
  return $account->is_suspended === true;
}

function check_access($account, $needs_player = true, $reject_suspended = true)
{
  if ($account === null) {
    die_json(401, "Not logged in");
  }
  if ($reject_suspended && is_suspended($account)) {
    die_json(403, "Account is suspended");
  }
  if ($needs_player && $account->player === null) {
    die_json(403, "Account does not have a player claimed yet");
  }
}
function check_role($account, $min_role)
{
  check_access($account);
  if ($account->role < $min_role) {
    die_json(403, "Not authorized");
  }
}
function reject_api_keys($account)
{
  if ($account === null) {
    die_json(401, "Not logged in");
  }
  if ($account->using_api_key) {
    die_json(403, "This action is not allowed when using an API key");
  }
}