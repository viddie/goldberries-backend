<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_access($account, false);
if (!is_admin($account)) {
  die_json(403, 'Not authorized');
}

header('Content-Type: text/plain');

$query = "UPDATE account
  SET notifications = notifications | $1
  WHERE (notifications & $1) = 0";
$result = pg_query_params_or_die($DB, $query, [Account::$NOTIF_NEW_HARDEST]);

echo "Enabled new hardest notifications for " . pg_affected_rows($result) . " accounts.\n";
