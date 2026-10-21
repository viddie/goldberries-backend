<?php

require_once('../api_bootstrap.inc.php');
require_once('db_migrate_functions.inc.php');

// Migration, safe to re-run:
//  1. Add challenge_tag.is_negative (negative tag votes)
//
// Query params:
//  dry_run=true  -> run everything inside the transaction, then roll back

$dry_run = migration_begin($DB);

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

migration_end($DB, $dry_run);
