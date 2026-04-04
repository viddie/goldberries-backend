<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$bin = $_REQUEST['bin'] ?? null;
if ($bin === null || $bin === '') {
  die_json(400, "Missing or invalid 'bin' parameter");
}
if (!str_ends_with($bin, '.bin')) {
  $bin .= '.bin';
}

$result = pg_query_params_or_die(
  $DB,
  "SELECT * FROM map WHERE bin = $1",
  [$bin],
  "Failed to search maps by bin path"
);

$maps = [];
while ($row = pg_fetch_assoc($result)) {
  $map = new Map();
  $map->apply_db_data($row);
  $map->expand_foreign_keys($DB, 4);
  $maps[] = $map;
}

api_write($maps);
