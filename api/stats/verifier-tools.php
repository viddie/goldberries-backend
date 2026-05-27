<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_role($account, $HELPER);

$query = "
SELECT
  (SELECT COUNT(*) FROM submission WHERE is_verified IS NULL) AS submissions_in_queue,
  (SELECT COUNT(*) FROM account WHERE claimed_player_id IS NOT NULL) AS open_player_claims,
  (SELECT COUNT(*) FROM suggestion WHERE is_verified IS NULL) AS pending_suggestions,
  (SELECT COUNT(*) FROM suggestion WHERE is_verified = TRUE AND is_accepted IS NULL AND date_created < NOW() - INTERVAL '" . Suggestion::$expiration_days . " days') AS undecided_suggestions
";
$result = pg_query_params_or_die($DB, $query);
$row = pg_fetch_assoc($result);
api_write($row, true);
#endregion