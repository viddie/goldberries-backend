<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Invalid request method');
}

//Overall submission count
$query = "SELECT
  split_part(entry, '|', 1)::INTEGER AS collectible_id,
  SUM(
    CASE
      WHEN split_part(entry, '|', 5) <> '' THEN split_part(entry, '|', 5)::INTEGER
      WHEN split_part(entry, '|', 4) = '' THEN 1
      ELSE split_part(entry, '|', 4)::INTEGER
    END
  ) AS total_amount
FROM map
CROSS JOIN LATERAL unnest(string_to_array(collectibles, E'\t')) AS entry
WHERE collectibles IS NOT NULL
GROUP BY collectible_id
ORDER BY total_amount DESC;";
$result = pg_query_params_or_die($DB, $query);

$data = [];
while ($row = pg_fetch_assoc($result)) {
  $data[] = [
    "collectible_id" => intval($row["collectible_id"]),
    "total_amount" => intval($row["total_amount"])
  ];
}

api_write($data);
#endregion