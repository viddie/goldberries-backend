<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_role($account, $HELPER);

if (!isset($_REQUEST['id'])) {
  die_json(400, "id is missing");
}
$id = intval($_REQUEST['id']);

$submission = Submission::get_by_id($DB, $id, 5);
if ($submission === false) {
  die_json(404, "Submission with id {$id} does not exist");
}

$notes = get_player_verifier_notes($DB, $submission->player_id);

$related = [];
if ($submission->challenge !== null && $submission->challenge->map_id !== null) {
  $related = get_related_submissions($DB, $submission->player_id, $submission->challenge->map_id, $submission->id);
}

api_write([
  'notes' => $notes,
  'related' => $related,
]);
#endregion


#region Functions
function get_player_verifier_notes($DB, int $player_id)
{
  // Notes containing any of these phrases (case-insensitive) are filtered out
  $excluded_phrases = [
    "map is rejected",
    "challenge is rejected",
    "campaign is rejected",
  ];

  $query = "SELECT date_verified::date AS date_verified, verifier_notes, is_verified, COUNT(*) AS count
            FROM submission
            WHERE player_id = $1 AND verifier_notes IS NOT NULL
            GROUP BY date_verified::date, verifier_notes, is_verified
            ORDER BY date_verified DESC NULLS LAST";
  $result = pg_query_params_or_die($DB, $query, [$player_id]);

  $notes = [];
  while ($row = pg_fetch_assoc($result)) {
    $note_text = $row['verifier_notes'];
    $is_excluded = false;
    foreach ($excluded_phrases as $phrase) {
      if (stripos($note_text, $phrase) !== false) {
        $is_excluded = true;
        break;
      }
    }
    if ($is_excluded) {
      continue;
    }

    $is_verified = $row['is_verified'] === null ? null : $row['is_verified'] === 't';
    $notes[] = [
      'date_verified' => $row['date_verified'],
      'verifier_notes' => $note_text,
      'is_verified' => $is_verified,
      'count' => intval($row['count']),
    ];
  }
  return $notes;
}

function get_related_submissions($DB, int $player_id, int $map_id, int $exclude_id)
{
  $query = "SELECT s.id
            FROM submission s
            JOIN challenge c ON s.challenge_id = c.id
            WHERE s.player_id = $1 AND c.map_id = $2 AND s.is_verified IS DISTINCT FROM false AND s.id != $3
            ORDER BY s.id ASC";
  $result = pg_query_params_or_die($DB, $query, [$player_id, $map_id, $exclude_id]);

  $submissions = [];
  while ($row = pg_fetch_assoc($result)) {
    $related = Submission::get_by_id($DB, intval($row['id']), 5, true);
    if ($related !== false) {
      $submissions[] = $related;
    }
  }
  return $submissions;
}
#endregion
