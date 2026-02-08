<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Invalid request method');
}

//Overall submission count
$query = "SELECT
  TO_CHAR(date_created, 'YYYY-MM') AS month,
  ROUND(AVG(ABS(EXTRACT(EPOCH FROM (date_verified - date_created)) / 86400.0)), 2) AS avg_days_to_verify
FROM
  submission
WHERE
  date_created >= '2024-08-01'
  AND date_verified IS NOT NULL
  AND date_created IS NOT NULL
GROUP BY
  TO_CHAR(date_created, 'YYYY-MM')
ORDER BY month";
$result = pg_query_params_or_die($DB, $query);

$data = [];
while ($row = pg_fetch_assoc($result)) {
  $data[] = [
    "month" => $row["month"],
    "avg_days_to_verify" => floatval($row["avg_days_to_verify"])
  ];
}

api_write($data);
#endregion