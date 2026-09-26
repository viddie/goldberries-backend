<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_access($account, false);
if (!is_admin($account)) {
  die_json(403, 'Not authorized');
}

header('Content-Type: text/plain; charset=UTF-8');

$ssr_badge_ids = [40, 41, 42];

$query = "SELECT
  stamp_submission.player_id AS player_id,
  SUM(greatest(difficulty.sort, 0)) AS total_tier,
  COUNT(stamp_submission.id) AS count_stamps
FROM stamp_submission
JOIN submission ON submission_id = submission.id
JOIN challenge ON challenge_id = challenge.id
JOIN difficulty ON difficulty_id = difficulty.id
GROUP BY stamp_submission.player_id";

$result = pg_query_params_or_die($DB, $query);

$player_stats = [];
while ($row = pg_fetch_assoc($result)) {
  $player_id = intval($row['player_id']);
  $player_stats[$player_id] = [
    'total_tier' => intval($row['total_tier']),
    'count_stamps' => intval($row['count_stamps']),
  ];
}

$query = "SELECT id, player_id, badge_id
FROM badge_player
WHERE badge_id IN (" . implode(', ', $ssr_badge_ids) . ")";
$result = pg_query_params_or_die($DB, $query);

$current_badges = [];
while ($row = pg_fetch_assoc($result)) {
  $player_id = intval($row['player_id']);
  $current_badges[$player_id][] = [
    'id' => intval($row['id']),
    'badge_id' => intval($row['badge_id']),
  ];
}

// Include existing SSR badge holders so players with fewer than 8 stamps are cleaned up.
$player_ids = array_unique(array_merge(array_keys($player_stats), array_keys($current_badges)));
sort($player_ids, SORT_NUMERIC);

$badges_awarded = 0;
$badges_removed = 0;
$errors = 0;

foreach ($player_ids as $player_id) {
  $stats = $player_stats[$player_id] ?? [
    'total_tier' => 0,
    'count_stamps' => 0,
  ];
  $total_tier = $stats['total_tier'];
  $count_stamps = $stats['count_stamps'];
  $expected_badge_id = null;

  if ($count_stamps >= 8) {
    if ($total_tier < 20) {
      $expected_badge_id = 42;
    } elseif ($total_tier < 40) {
      $expected_badge_id = 41;
    } else {
      $expected_badge_id = 40;
    }
  }

  $existing_badges = $current_badges[$player_id] ?? [];
  $has_expected_badge = false;
  foreach ($existing_badges as $badge) {
    if ($badge['badge_id'] === $expected_badge_id) {
      $has_expected_badge = true;
      break;
    }
  }

  echo "Player $player_id: $count_stamps stamps, total tier $total_tier";
  if ($expected_badge_id === null) {
    echo " -> no SSR badge\n";
  } else {
    echo " -> badge $expected_badge_id\n";
  }

  if ($expected_badge_id !== null && !$has_expected_badge) {
    $badge_player = new BadgePlayer();
    $badge_player->player_id = $player_id;
    $badge_player->badge_id = $expected_badge_id;
    $badge_player->date_awarded = new JsonDateTime();

    if ($badge_player->insert($DB)) {
      $badges_awarded++;
      echo "  Awarded badge $expected_badge_id\n";
    } else {
      $errors++;
      echo "  ERROR: failed to award badge $expected_badge_id; existing badges were not removed\n";
      continue;
    }
  }

  foreach ($existing_badges as $badge) {
    if ($badge['badge_id'] === $expected_badge_id) {
      continue;
    }

    $badge_player = new BadgePlayer();
    $badge_player->id = $badge['id'];
    if ($badge_player->delete($DB)) {
      $badges_removed++;
      echo "  Removed badge {$badge['badge_id']}\n";
    } else {
      $errors++;
      echo "  ERROR: failed to remove badge {$badge['badge_id']}\n";
    }
  }
}

echo "\nBadges awarded: $badges_awarded\n";
echo "Badges removed: $badges_removed\n";
echo "Errors: $errors\n";
