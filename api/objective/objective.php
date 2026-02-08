<?php

require_once ('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $id = $_REQUEST['id'];
  $objectives = Objective::get_request($DB, $id);
  api_write($objectives);
}
#endregion

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(400, 'Method not implemented');
}
