<?php

require_once('../api_bootstrap.inc.php');

$account = get_user_data();
if ($account == null) {
  die_json(401, "Not logged in");
}
reject_api_keys($account);

if (logout()) {
  http_response_code(200);
} else {
  die_json(500, "Failed to logout");
}