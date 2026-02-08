<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Invalid request method');
}

$query = "SELECT * FROM view_submissions WHERE submission_time_taken IS NOT NULL ORDER BY submission_time_taken DESC LIMIT 300";
$result = pg_query_params_or_die($DB, $query);

$submissions = [];
while ($row = pg_fetch_assoc($result)) {
  $submission = new Submission();
  $submission->apply_db_data($row, "submission_");
  $submission->expand_foreign_keys($row, 5);
  $submissions[] = $submission;
}

api_write($submissions);
#endregion