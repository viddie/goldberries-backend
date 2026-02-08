<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $settings = ServerSettings::get_settings($DB);
  api_write($settings);
}
#endregion

$account = get_user_data();
#region POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_role($account, $ADMIN);

  $data = format_assoc_array_bools(parse_post_body_as_json());
  $settings = new ServerSettings();
  $settings->apply_db_data($data);
  $settings->id = 1; //Just in case that the frontend ever not sends an id

  if ($settings->update($DB)) {
    api_write($settings);
  } else {
    die_json(500, "Failed to update ServerSettings");
  }
}
#endregion

#region DELETE Request
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
  die_json(405, 'Method Not Allowed');
}
#endregion