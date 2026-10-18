<?php

require_once ('../api_bootstrap.inc.php');

$account = get_user_data();

if ($account == null) {
  die_json(401, "Not logged in");
}

$api_key_scopes = $account->using_api_key ? $account->active_api_key->scopes : null;

$account->expand_foreign_keys($DB, 3);
$account->remove_sensitive_info(false);
$account->api_key_scopes = $api_key_scopes;
api_write($account);
