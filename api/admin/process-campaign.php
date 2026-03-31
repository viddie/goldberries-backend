<?php

require_once('../api_bootstrap.inc.php');
require_once(__DIR__ . '/process_functions.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_role($account, $HELPER);

$id = $_REQUEST['id'] ?? null;
if ($id === null) {
  die_json(400, "Missing 'id' parameter");
}

$regenerate = ($_REQUEST['regenerate'] ?? 'false') === 'true';

$id_list = array_map('trim', explode(',', $id));
$results = [];

foreach ($id_list as $id) {
  if (!is_numeric($id)) {
    $results[] = ['id' => $id, 'success' => false, 'error' => "Invalid campaign ID"];
    continue;
  }
  $results[] = process_campaign($DB, intval($id), $regenerate);
}

api_write($results);