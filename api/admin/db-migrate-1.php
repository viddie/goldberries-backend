<?php

require_once('../api_bootstrap.inc.php');

// Migration, safe to re-run:
//  1. Add challenge_tag.is_negative (negative tag votes)
//
// Query params:
//  dry_run=true  -> run everything inside the transaction, then roll back

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_access($account, false);
if (!is_admin($account)) {
  die_json(403, 'Not authorized');
}

header('Content-Type: text/plain');

$dry_run = isset($_REQUEST['dry_run']) && $_REQUEST['dry_run'] === 'true';

echo $dry_run ? "=== DRY RUN (all changes will be rolled back) ===\n\n" : "=== LIVE RUN ===\n\n";

migration_query($DB, "BEGIN");

//#region Step 1: Negative tag votes
echo "Step 1: Negative tag votes\n";

if (column_exists($DB, "challenge_tag", "is_negative")) {
  echo "  challenge_tag.is_negative already exists, skipped\n";
} else {
  migration_query($DB, "ALTER TABLE challenge_tag ADD COLUMN is_negative boolean NOT NULL DEFAULT false");
  echo "  Added challenge_tag.is_negative\n";
}
echo "\n";
//#endregion

if ($dry_run) {
  migration_query($DB, "ROLLBACK");
  echo "Dry run finished, all changes rolled back.\n";
} else {
  migration_query($DB, "COMMIT");
  echo "All changes committed.\n";
}


//#region Utility functions
function migration_fail($DB, string $message)
{
  pg_query($DB, "ROLLBACK");
  echo "\nERROR: $message\nAll changes rolled back.\n";
  exit();
}

function migration_query($DB, string $query, array $params = [])
{
  $result = pg_query_params($DB, $query, $params);
  if ($result === false) {
    migration_fail($DB, pg_last_error($DB));
  }
  return $result;
}

function column_exists($DB, string $table, string $column): bool
{
  $query = "SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = $1 AND column_name = $2";
  $result = migration_query($DB, $query, [$table, $column]);
  return pg_num_rows($result) > 0;
}
//#endregion
