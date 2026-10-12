<?php

require_once('../api_bootstrap.inc.php');

$GROUPS = ['popular', 'liked', 'new', 'trending', 'random'];
$ALL_PER_GROUP_DEFAULT = 12;
$ALL_PER_GROUP_MAX = 50;
$PER_PAGE_DEFAULT = 50;
$PER_PAGE_MAX = 1000;
$TRENDING_DAYS = 90;
$TRENDING_MIN_LIKES = 3;
$TRENDING_MIN_SUBMISSIONS = 3;
$SEED_MAX = 2147483647;
$DEPTH = 3;
$CLEAR_STATES = [
  1 => "challenge.has_fc = false AND challenge.requires_fc = false",
  2 => "challenge.requires_fc = true AND challenge.has_fc = false",
  3 => "(challenge.requires_fc = true OR challenge.has_fc = true)",
  4 => "challenge.requires_fc = false",
];

#region GET Request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$group = $_REQUEST['group'] ?? 'all';
if ($group !== 'all' && !in_array($group, $GROUPS, true)) {
  die_json(400, "group must be 'all' or one of: " . implode(', ', $GROUPS));
}

$uses_random = $group === 'all' || $group === 'random';
$seed_provided = isset($_REQUEST['seed']);
if ($seed_provided) {
  if (!$uses_random) {
    die_json(400, "seed can only be set when group is 'all' or 'random'");
  }
  $seed_raw = $_REQUEST['seed'];
  if (!is_string($seed_raw) || !is_id_string($seed_raw) || intval($seed_raw) < 1 || intval($seed_raw) > $SEED_MAX) {
    die_json(400, "seed must be an integer between 1 and {$SEED_MAX}");
  }
  $seed = intval($seed_raw);
} else {
  $seed = random_int(1, $SEED_MAX);
}

$raw_filter = $_REQUEST['filter'] ?? null;
if (is_string($raw_filter) && $raw_filter !== '') {
  $raw_filter = json_decode($raw_filter, true);
  if (!is_array($raw_filter)) {
    die_json(400, "filter is not valid JSON");
  }
}
$filter = ChallengeTag::parse_filter($DB, $raw_filter);
$advanced_filter = parse_discovery_filter($DB, is_array($raw_filter) ? $raw_filter : []);
$filter = array_merge($filter, $advanced_filter);
$player_id = null;
if ($filter['exclude_cleared']) {
  $account = get_user_data();
  check_access($account);
  $player_id = $account->player_id;
}

$group_definitions = [
  'popular' => ['where' => null, 'order' => "count_submissions DESC, likes DESC, id DESC"],
  'liked' => ['where' => null, 'order' => "likes DESC, count_submissions DESC, id DESC"],
  'new' => ['where' => null, 'order' => "date_created DESC NULLS LAST, id DESC"],
  'trending' => [
    'where' => "date_created >= NOW() - INTERVAL '{$TRENDING_DAYS} days' AND (likes >= {$TRENDING_MIN_LIKES} OR count_submissions >= {$TRENDING_MIN_SUBMISSIONS})",
    'order' => "(likes + count_submissions) DESC, date_created DESC, id DESC",
  ],
  // Each row's position only depends on its own id + the seed, so adding/removing challenges doesn't reshuffle the others
  'random' => ['where' => null, 'order' => "md5(id::text || ':' || '{$seed}'), id"],
];

// Step 1: Rank matching challenge ids (lightweight, no hydration)
$params = [];
$filter_sql = ChallengeTag::build_filter_sql($filter, $params, 'challenge.id');
$where = array_merge(["challenge.is_rejected = false"], $filter_sql['where']);
foreach (['min_diff_id' => '>=', 'max_diff_id' => '<='] as $key => $operator) {
  if (isset($filter[$key])) {
    $params[] = $filter[$key];
    $where[] = "difficulty.sort {$operator} (SELECT sort FROM difficulty WHERE id = $" . count($params) . ")";
  }
}
if (count($filter['hide_objectives']) > 0) {
  $params[] = "{" . implode(",", $filter['hide_objectives']) . "}";
  $where[] = "NOT (challenge.objective_id = ANY($" . count($params) . "::int[]))";
}
if ($filter['clear_state'] !== 0) {
  $where[] = $CLEAR_STATES[$filter['clear_state']];
}
if ($filter['exclude_cleared']) {
  $params[] = $player_id;
  $where[] = "NOT EXISTS (
    SELECT 1 FROM submission cleared
    WHERE cleared.challenge_id = challenge.id
      AND cleared.player_id = $" . count($params) . "
      AND cleared.is_verified IS DISTINCT FROM false
  )";
}

$ctes = $filter_sql['ctes'];
$ctes[] = "submission_counts AS (
  SELECT challenge_id, COUNT(*) AS count_submissions
  FROM submission
  WHERE is_verified = true AND challenge_id IS NOT NULL
  GROUP BY challenge_id
)";
$ctes[] = "matched AS (
  SELECT challenge.id, challenge.likes, challenge.date_created, COALESCE(submission_counts.count_submissions, 0) AS count_submissions
  FROM challenge
  JOIN difficulty ON difficulty.id = challenge.difficulty_id
  LEFT JOIN submission_counts ON submission_counts.challenge_id = challenge.id
  WHERE " . implode(" AND ", $where) . "
)";

if ($group === 'all') {
  $per_group = isset($_REQUEST['per_page']) ? intval($_REQUEST['per_page']) : $ALL_PER_GROUP_DEFAULT;
  $per_group = max(1, min($ALL_PER_GROUP_MAX, $per_group));
  $selects = [];
  foreach ($GROUPS as $name) {
    $selects[] = "(" . build_group_select($name, $group_definitions[$name], $per_group, 0) . ")";
  }
  $query = "WITH " . implode(",\n", $ctes) . "\n" . implode("\nUNION ALL\n", $selects);
} else {
  $page = max(1, intval($_REQUEST['page'] ?? 1));
  $per_page = isset($_REQUEST['per_page']) ? intval($_REQUEST['per_page']) : $PER_PAGE_DEFAULT;
  $per_page = max(1, min($PER_PAGE_MAX, $per_page));
  if ($group === 'random' && !$seed_provided) {
    $page = 1; // Paging through a freshly generated random order is meaningless
  }
  $query = "WITH " . implode(",\n", $ctes) . "\n" . build_group_select($group, $group_definitions[$group], $per_page, ($page - 1) * $per_page);
}

$result = pg_query_params_or_die($DB, $query, $params, "Failed to query discovery challenges");

$ranked = []; // group => rn => challenge id
$total_counts = [];
$count_submissions = [];
while ($row = pg_fetch_assoc($result)) {
  $id = intval($row['id']);
  $ranked[$row['grp']][intval($row['rn'])] = $id;
  $total_counts[$row['grp']] = intval($row['total_count']);
  $count_submissions[$id] = intval($row['count_submissions']);
}

// Step 2: Hydrate only the selected challenges
$challenges_by_id = [];
if (count($count_submissions) > 0) {
  $ids_str = "{" . implode(",", array_keys($count_submissions)) . "}";
  $result = pg_query_params_or_die($DB, "SELECT * FROM view_challenges WHERE challenge_id = ANY($1::int[])", [$ids_str], "Failed to fetch challenges");
  while ($row = pg_fetch_assoc($result)) {
    $challenge = new Challenge();
    $challenge->apply_db_data($row, "challenge_");
    $challenge->expand_foreign_keys($row, $DEPTH);
    $challenge->data = [
      'count_submissions' => $count_submissions[$challenge->id],
    ];
    $challenges_by_id[$challenge->id] = $challenge;
  }
  ChallengeTag::attach_counts_to_challenges($DB, array_values($challenges_by_id));
}

if ($group === 'all') {
  $groups = [];
  foreach ($GROUPS as $name) {
    $groups[$name] = [
      'challenges' => get_ranked_challenges($ranked[$name] ?? [], $challenges_by_id),
      'total_count' => $total_counts[$name] ?? 0,
    ];
  }
  api_write([
    'groups' => $groups,
    'per_group' => $per_group,
    'seed' => $seed,
    'filter' => $filter,
  ]);
} else {
  $max_count = $total_counts[$group] ?? 0;
  api_write([
    'group' => $group,
    'challenges' => get_ranked_challenges($ranked[$group] ?? [], $challenges_by_id),
    'max_count' => $max_count,
    'max_page' => ceil($max_count / $per_page),
    'page' => $page,
    'per_page' => $per_page,
    'seed' => $group === 'random' ? $seed : null,
    'filter' => $filter,
  ]);
}
#endregion

#region Utility Functions
function parse_discovery_filter($DB, array $raw): array
{
  $filter = ['hide_objectives' => [], 'clear_state' => 0, 'exclude_cleared' => false];
  $bounds = [];
  foreach (['min_diff_id', 'max_diff_id'] as $key) {
    if (!array_key_exists($key, $raw)) continue;
    $id = discovery_filter_integer($raw[$key], $key, 1);
    $difficulty = Difficulty::get_by_id($DB, $id);
    if (!$difficulty) {
      die_json(400, "filter.{$key} must reference an existing difficulty");
    }
    $filter[$key] = $id;
    $bounds[$key] = $difficulty->sort;
  }
  if (isset($bounds['min_diff_id'], $bounds['max_diff_id']) && $bounds['min_diff_id'] > $bounds['max_diff_id']) {
    die_json(400, "filter.min_diff_id must not be above filter.max_diff_id");
  }
  if (array_key_exists('hide_objectives', $raw)) {
    if (!is_array($raw['hide_objectives']) || !array_is_list($raw['hide_objectives'])) {
      die_json(400, "filter.hide_objectives must be a list of objective ids");
    }
    $objectives = Objective::get_request($DB, 'all');
    $objective_ids = array_map(fn($objective) => $objective->id, $objectives);
    foreach ($raw['hide_objectives'] as $value) {
      $id = discovery_filter_integer($value, 'hide_objectives', 1);
      if (!in_array($id, $objective_ids, true)) {
        die_json(400, "filter.hide_objectives must reference existing objectives");
      }
      $filter['hide_objectives'][] = $id;
    }
    $filter['hide_objectives'] = array_values(array_unique($filter['hide_objectives']));
  }
  if (array_key_exists('clear_state', $raw)) {
    $filter['clear_state'] = discovery_filter_integer($raw['clear_state'], 'clear_state', 0);
    if ($filter['clear_state'] > 4) {
      die_json(400, "filter.clear_state must be between 0 and 4");
    }
  }
  if (array_key_exists('exclude_cleared', $raw)) {
    if (!is_bool($raw['exclude_cleared'])) {
      die_json(400, "filter.exclude_cleared must be a boolean");
    }
    $filter['exclude_cleared'] = $raw['exclude_cleared'];
  }
  return $filter;
}

function discovery_filter_integer($value, string $key, int $minimum): int
{
  if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]) === false) {
    die_json(400, "filter.{$key} must be an integer of at least {$minimum}");
  }
  return intval($value);
}

function build_group_select(string $name, array $definition, int $limit, int $offset): string
{
  $where = $definition['where'] !== null ? "WHERE {$definition['where']}" : "";
  return "SELECT '{$name}' AS grp, id, count_submissions,
      COUNT(*) OVER () AS total_count,
      ROW_NUMBER() OVER (ORDER BY {$definition['order']}) AS rn
    FROM matched
    {$where}
    ORDER BY rn
    LIMIT {$limit} OFFSET {$offset}";
}

function get_ranked_challenges(array $ranked_ids, array $challenges_by_id): array
{
  ksort($ranked_ids);
  $challenges = [];
  foreach ($ranked_ids as $id) {
    if (isset($challenges_by_id[$id]))
      $challenges[] = $challenges_by_id[$id];
  }
  return $challenges;
}
#endregion
