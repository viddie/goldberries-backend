<?php

require_once('../api_bootstrap.inc.php');

if (isset($_REQUEST['code'])) {
  //Got code from discord oauth, now get access token

  $state = $_REQUEST['state'];
  if ($state != $_SESSION['state']) {
    die_json(401, "Invalid state: Got '" . $state . "', Expected '" . $_SESSION['state'] . "'"); //CSRF attack
  }

  $token = apiRequest(
    constant('DISCORD_TOKEN_URL'),
    array(
      "grant_type" => "authorization_code",
      'client_id' => constant('DISCORD_CLIENT_ID'),
      'client_secret' => getenv('DISCORD_CLIENT_SECRET'),
      'redirect_uri' => constant('DISCORD_REDIRECT_URI'),
      'code' => $_REQUEST['code']
    )
  );

  //Store access token in session
  $_SESSION['access_token'] = $token->access_token;

  //Identify the user
  $user = apiRequest(constant('DISCORD_API_URL') . '/users/@me');
  $_SESSION['discord_user'] = $user;
  $user_id = $user->id;

  if ($user_id == null) {
    die_json(500, "Failed to get discord user id");
  }

  //Check if user is in database
  $accounts = Account::find_by_discord_id($DB, $user_id);
  $account = null;

  //Check if user is trying to link discord to their account
  if ($_SESSION['link_account'] == true) {
    if ($accounts != false) {
      die_json(400, "Discord account is already linked to another account");
    }

    $account = get_user_data();
    $account->discord_id = $user_id;
    if ($account->update($DB) === false) {
      die_json(500, "Failed to link discord account");
    }
    log_info("User linked Account({$account->id}) to Discord (id: {$user_id})", "Account");
    header('Location: ' . constant('REDIRECT_POST_LINK_ACCOUNT'));
    exit();
  }

  //Non-linking stuff
  if ($accounts == false) {
    //User is not in database, create new account
    $settings = ServerSettings::get_settings($DB);
    if (!$settings->registrations_enabled) {
      die_json(400, "Registrations are currently disabled");
    }

    $account = new Account();
    $account->discord_id = $user_id;
    if ($account->insert($DB) === false) {
      header('Location: ' . constant('REGISTER_URL') . "/" . urlencode("Failed to create account"));
      exit();
    }
    log_info("User registered Account({$account->id}) via Discord (id: {$user_id})", "Login");
  } else {
    //User account was found, try to login
    $account = $accounts[0];
    if (is_suspended($account)) {
      header('Location: ' . constant('REGISTER_URL') . "/" . urlencode("Account is suspended: " . $account->suspension_reason));
      exit();
    }
  }

  //Login user
  if (successful_login($account, "discord")) {
    $redirect = $_SESSION['REDIRECT_AFTER_LOGIN'] ?? constant('REDIRECT_POST_LOGIN');
    header('Location: ' . $redirect);
  } else {
    header('Location: ' . constant('REGISTER_URL') . "/" . urlencode("Failed to login"));
  }

} else {
  //Redirect to discord oauth
  //Rememeber if user was trying to login or register
  $_SESSION['login'] = isset($_GET['login']);

  //Remember if user is trying to link their existing account to discord
  $_SESSION['link_account'] = isset($_GET['link_account']);

  if ($_SESSION['link_account'] == false && is_logged_in()) {
    //User is already logged in and not trying to link account
    header('Location: ' . constant('REDIRECT_POST_LOGIN'));
    exit();
  }

  if (isset($_REQUEST['redirect'])) {
    $_SESSION['REDIRECT_AFTER_LOGIN'] = $_REQUEST['redirect'];
  } else if (isset($_SERVER['HTTP_REFERER'])) {
    $_SESSION['REDIRECT_AFTER_LOGIN'] = $_SERVER['HTTP_REFERER'];
  }

  //Redirect to discord oauth
  header("Location: " . get_discord_url());
}

function apiRequest($url, $post = FALSE, $headers = array())
{
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

  $response = curl_exec($ch);


  if ($post) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
  }
  $headers[] = 'Accept: application/json';

  if (isset($_SESSION['access_token']))
    $headers[] = 'Authorization: Bearer ' . $_SESSION['access_token'];

  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

  $response = curl_exec($ch);
  return json_decode($response);
}