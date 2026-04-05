<?php

require_once('../api_bootstrap.inc.php');
require_once(GB_ROOT_LOCAL . '/api/admin/process_functions.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$gamebanana_url = $_REQUEST['gamebanana_url'] ?? null;
$gb_info = parse_gamebanana_url($gamebanana_url);
if ($gb_info === null) {
  die_json(400, "Missing or invalid 'gamebanana_url' parameter. Expected format: https://gamebanana.com/mods/<id> or https://gamebanana.com/wips/<id>");
}

$url = "https://gamebanana.com/{$gb_info['category']}/{$gb_info['id']}";

$result = pg_query_params_or_die(
  $DB,
  "SELECT * FROM campaign WHERE url = $1 ORDER BY name",
  [$url],
  "Failed to search campaigns by GameBanana URL"
);

$campaigns = [];
while ($row = pg_fetch_assoc($result)) {
  $campaign = new Campaign();
  $campaign->apply_db_data($row);
  $campaign->expand_foreign_keys($DB, 4);
  $campaigns[] = $campaign;
}

api_write($campaigns);
