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

$filter = ChallengeTag::parse_filter($DB, $_REQUEST['filter'] ?? null);

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
