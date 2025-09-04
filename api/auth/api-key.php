<?php

require_once('../api_bootstrap.inc.php');

$account = get_user_data();
check_access($account, false);


if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  api_write([
    "api_key" => $account->api_key
  ]);
  exit();
}

// Check if 'id' is set, if yes it must be a verifier+ targeting some other account
$target_account = $account;
if (isset($_REQUEST['id'])) {
  check_role($account, $VERIFIER);
  $target_account = Account::get_by_id($DB, intval($_REQUEST['id']));
  if (!$target_account) {
    die_json(404, "Account not found");
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Generate new API key: 32 random characters from 0-9a-f
  $new_api_key = bin2hex(random_bytes(16));
  $target_account->api_key = $new_api_key;
  $target_account->update($DB);
  api_write([
    "api_key" => $new_api_key
  ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
  //Remove your API key
  $target_account->api_key = null;
  $target_account->update($DB);
  http_response_code(200);
}