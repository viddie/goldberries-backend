<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_access($account, false);
if (!is_admin($account)) {
  die_json(403, "Not authorized");
}

//Set content type to plain text
header('Content-Type: text/plain');

echo "Processing role assignment logs...\n\n";
$query = "SELECT * FROM logging WHERE message ILIKE '%assigned role%'";
$result = pg_query_params_or_die($DB, $query);
while ($row = pg_fetch_assoc($result)) {
  $log = new Logging();
  $log->apply_db_data($row);

  //Logs are in this format: 'viddie' assigned role 'Helper' to (Account, id: 94, name: 'Marflow')
  //We need to extract:
  //- admin name (viddie) -> fetch the player ID via this name
  //- role name (Helper)
  //- target account id (94) -> fetch the current player ID via account.player_id == 94 (since players can rename themselves)
  $message = $log->message;
  $date = $log->date;
  if (preg_match("/'(.+)' assigned role '(.+)' to \(Account, id: (\d+), name: '(.+)'\)/", $message, $matches)) {
    $admin_name = $matches[1];
    $role_name = $matches[2];
    $target_account_id = intval($matches[3]);
    //$target_player_name = $matches[4]; //Not used

    //Fetch admin player ID
    $query = "SELECT * FROM player WHERE name = $1";
    $temp_result = pg_query_params_or_die($DB, $query, array($admin_name));
    if (pg_num_rows($temp_result) === 0) {
      echo "Admin player name '$admin_name' not found. Skipping log ID {$log->id}\n";
      continue;
    }
    $admin_id = intval(pg_fetch_result($temp_result, 0, 'id'));

    //Fetch target player ID via account
    $target_account = Account::get_by_id($DB, $target_account_id);
    if (!$target_account) {
      echo "Target account ID '$target_account_id' not found. Skipping log ID {$log->id}\n";
      continue;
    }
    $target_player_id = $target_account->player_id;

    //Create Change entry
    Change::create_change($DB, "player", $target_player_id, "Assigned role '{$role_name}'", $admin_id, $date);
    echo "Created changelog for assigning role '{$role_name}' to player ID {$target_player_id} by admin '{$admin_name}'\n";
  } else {
    echo "Log message format unrecognized for log ID {$log->id}. Skipping.\n";
  }
}