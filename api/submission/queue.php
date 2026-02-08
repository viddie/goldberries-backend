<?php

require_once ('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$submissions = Submission::get_submission_queue($DB);
$notices = VerificationNotice::get_all($DB);

api_write(
  array(
    'queue' => $submissions,
    'notices' => $notices,
  )
);
#endregion