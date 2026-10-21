<?php

// Shared helpers for the db-migrate-x.php scripts. Usage:
//   require_once('../api_bootstrap.inc.php');
//   require_once('db_migrate_functions.inc.php');
//   $dry_run = migration_begin($DB);
//   ... steps using migration_query(), column_exists(), table_exists() ...
//   migration_end($DB, $dry_run);
//
// Query params:
//  dry_run=true  -> run everything inside the transaction, then roll back

// Checks method + admin access, prints the run header and starts the transaction. Returns whether this is a dry run
function migration_begin($DB): bool
{
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
  return $dry_run;
}

// Commits the transaction, or rolls it back for a dry run
function migration_end($DB, bool $dry_run)
{
  if ($dry_run) {
    migration_query($DB, "ROLLBACK");
    echo "Dry run finished, all changes rolled back.\n";
  } else {
    migration_query($DB, "COMMIT");
    echo "All changes committed.\n";
  }
}

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

function table_exists($DB, string $table): bool
{
  $query = "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = $1";
  $result = migration_query($DB, $query, [$table]);
  return pg_num_rows($result) > 0;
}
