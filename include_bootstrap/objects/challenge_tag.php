<?php

class ChallengeTag extends DbObject
{
  public static string $table_name = 'challenge_tag';

  public static int $MAX_FILTER_CONDITIONS = 30;
  public static int $MAX_CONFIDENCE = 100;

  public int $challenge_id;
  public int $player_id;
  public int $tag_value_id;
  public ?JsonDateTime $date_created = null;

  // Linked Objects
  public ?Challenge $challenge = null;
  public ?Player $player = null;
  public ?TagValue $tag_value = null;


  #region Abstract Functions
  function get_field_set()
  {
    return array(
      'challenge_id' => $this->challenge_id,
      'player_id' => $this->player_id,
      'tag_value_id' => $this->tag_value_id,
      'date_created' => $this->date_created,
    );
  }

  static function static_field_set()
  {
    return [
      'challenge_id',
      'player_id',
      'tag_value_id',
      'date_created',
    ];
  }

  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->challenge_id = intval($arr[$prefix . 'challenge_id']);
    $this->player_id = intval($arr[$prefix . 'player_id']);
    $this->tag_value_id = intval($arr[$prefix . 'tag_value_id']);
    $this->date_created = new JsonDateTime($arr[$prefix . 'date_created']);
  }

  protected function do_expand_foreign_keys($DB, $depth, $expand_structure)
  {
    if ($expand_structure && isset($this->challenge_id)) {
      $this->challenge = Challenge::get_by_id($DB, $this->challenge_id, $depth - 1);
    }
    if (isset($this->player_id)) {
      $this->player = Player::get_by_id($DB, $this->player_id, $depth, false);
    }
    if (isset($this->tag_value_id)) {
      $this->tag_value = TagValue::get_by_id($DB, $this->tag_value_id, 1);
    }
  }

  protected function get_expand_list($level, $expand_structure)
  {
    return [];
  }

  protected function apply_expand_data($data, $level, $expand_structure)
  {
  }
  #endregion

  #region Find Functions
  static function get_player_value_ids($DB, int $challenge_id, int $player_id): array
  {
    $result = pg_query_params_or_die(
      $DB,
      "SELECT tag_value_id FROM challenge_tag WHERE challenge_id = $1 AND player_id = $2 ORDER BY tag_value_id",
      [$challenge_id, $player_id],
      "Failed to fetch tag assignments"
    );
    $ids = [];
    while ($row = pg_fetch_assoc($result)) {
      $ids[] = intval($row['tag_value_id']);
    }
    return $ids;
  }

  /**
   * Aggregated tag counts ("chips") for a list of challenges.
   * @return array list of ['challenge_id' => int, 'tag_value_id' => int, 'count' => int]
   */
  static function get_counts_for_challenges($DB, array $challenge_ids): array
  {
    if (count($challenge_ids) === 0)
      return [];

    $ids_str = "{" . implode(",", array_map('intval', $challenge_ids)) . "}";
    $result = pg_query_params_or_die(
      $DB,
      "SELECT challenge_id, tag_value_id, COUNT(*) AS count
       FROM challenge_tag
       WHERE challenge_id = ANY($1::int[])
       GROUP BY challenge_id, tag_value_id
       ORDER BY challenge_id, count DESC, tag_value_id",
      [$ids_str],
      "Failed to fetch tag counts"
    );

    $counts = [];
    while ($row = pg_fetch_assoc($result)) {
      $counts[] = [
        'challenge_id' => intval($row['challenge_id']),
        'tag_value_id' => intval($row['tag_value_id']),
        'count' => intval($row['count']),
      ];
    }
    return $counts;
  }

  /**
   * Attaches the tag counts to $challenge->data['tags'] for each challenge, using a single query.
   */
  static function attach_counts_to_challenges($DB, array $challenges)
  {
    $ids = array_map(fn($c) => $c->id, $challenges);
    $grouped = [];
    foreach (self::get_counts_for_challenges($DB, $ids) as $row) {
      $grouped[$row['challenge_id']][] = [
        'tag_value_id' => $row['tag_value_id'],
        'count' => $row['count'],
      ];
    }
    foreach ($challenges as $challenge) {
      if (!is_array($challenge->data))
        $challenge->data = [];
      $challenge->data['tags'] = $grouped[$challenge->id] ?? [];
    }
  }

  /**
   * All assignments of one tag value on one challenge, with the players expanded (for the "who tagged this" list).
   * @return ChallengeTag[]
   */
  static function get_assignments($DB, int $challenge_id, int $tag_value_id): array
  {
    $result = pg_query_params_or_die(
      $DB,
      "SELECT challenge_tag.*, view_players.*
       FROM challenge_tag
       JOIN view_players ON view_players.player_id = challenge_tag.player_id
       WHERE challenge_tag.challenge_id = $1 AND challenge_tag.tag_value_id = $2
       ORDER BY challenge_tag.date_created, challenge_tag.id",
      [$challenge_id, $tag_value_id],
      "Failed to fetch tag assignments"
    );

    $assignments = [];
    while ($row = pg_fetch_assoc($result)) {
      $assignment = new ChallengeTag();
      $assignment->apply_db_data($row);
      $assignment->player = new Player();
      $assignment->player->apply_db_data($row, 'player_', false);
      $assignments[] = $assignment;
    }
    return $assignments;
  }
  #endregion

  #region Assignment Functions
  /**
   * Validates a player's requested set of tag values for one challenge and computes the final set to store.
   * Dies with an error response on invalid input.
   *
   * Rules:
   * - All ids must exist.
   * - "Locked" values can neither be added nor removed through this function. They are kept as they are.
   *   A value is locked if its category is archived, or if its tag is team-only and the account is not a team member.
   * - For tags whose values changed: 'single' tags allow at most 1 value, 'range' tags require a gap-free block.
   *
   * @param array $requested_ids the tag value ids the player wants to have assigned
   * @param array $existing_ids the tag value ids the player currently has assigned
   * @return int[] the final set of tag value ids
   */
  static function resolve_player_tags($DB, $account, $requested_ids, array $existing_ids): array
  {
    if (!is_array($requested_ids) || !array_is_list($requested_ids)) {
      die_json(400, "tag_value_ids must be a list");
    }
    if (count($requested_ids) > 200) {
      die_json(400, "Too many tag values");
    }

    $defs = Tag::get_definitions($DB);
    $is_team = is_helper($account);

    $requested = [];
    foreach ($requested_ids as $id) {
      if (!is_int($id) && !(is_string($id) && is_id_string($id))) {
        die_json(400, "Invalid tag value id");
      }
      $id = intval($id);
      if (!isset($defs['values'][$id])) {
        die_json(400, "Tag value with id {$id} does not exist");
      }
      if (!in_array($id, $requested, true))
        $requested[] = $id;
    }

    $final = [];
    foreach ($requested as $id) {
      $locked_reason = self::get_locked_reason($defs, $id, $is_team);
      if ($locked_reason !== null && !in_array($id, $existing_ids, true)) {
        $tag = self::get_tag_of_value($defs, $id);
        die_json(403, "Tag '{$tag->name}' {$locked_reason}");
      }
      $final[] = $id;
    }
    foreach ($existing_ids as $id) {
      if (!isset($defs['values'][$id]) || in_array($id, $final, true))
        continue;
      if (self::get_locked_reason($defs, $id, $is_team) !== null)
        $final[] = $id;
    }

    $final_by_tag = self::group_value_ids_by_tag($defs, $final);
    $existing_by_tag = self::group_value_ids_by_tag($defs, $existing_ids);
    $tag_ids = array_unique(array_merge(array_keys($final_by_tag), array_keys($existing_by_tag)));
    foreach ($tag_ids as $tag_id) {
      $new_values = $final_by_tag[$tag_id] ?? [];
      $old_values = $existing_by_tag[$tag_id] ?? [];
      sort($new_values);
      sort($old_values);
      if ($new_values === $old_values)
        continue;

      $tag = $defs['tags'][$tag_id];
      if ($tag->selection_mode === 'single' && count($new_values) > 1) {
        die_json(400, "Only one value of tag '{$tag->name}' can be assigned");
      }
      if ($tag->selection_mode === 'range' && !$tag->are_values_contiguous($new_values)) {
        die_json(400, "Values of tag '{$tag->name}' must form a continuous range");
      }
    }

    sort($final);
    return $final;
  }

  /**
   * Merges a partial selection into the existing set: for every tag that appears in $partial_ids,
   * the player's existing values of that tag are replaced. Tags not mentioned are left untouched.
   */
  static function merge_partial_selection($DB, $partial_ids, array $existing_ids): array
  {
    if (!is_array($partial_ids) || !array_is_list($partial_ids)) {
      die_json(400, "tag_value_ids must be a list");
    }
    $defs = Tag::get_definitions($DB);
    $partial = array_map('intval', $partial_ids);
    $touched_tags = array_keys(self::group_value_ids_by_tag($defs, $partial));

    $merged = $partial;
    foreach ($existing_ids as $id) {
      $tag = self::get_tag_of_value($defs, $id);
      if ($tag !== null && !in_array($tag->id, $touched_tags, true) && !in_array($id, $merged, true))
        $merged[] = $id;
    }
    return $merged;
  }

  /**
   * Replaces a player's tag values on a challenge with the given (already validated) set in a single statement.
   * @return array ['added' => int, 'removed' => int]
   */
  static function set_player_tags($DB, int $challenge_id, int $player_id, array $value_ids): array
  {
    $ids_str = "{" . implode(",", array_map('intval', $value_ids)) . "}";
    $result = pg_query_params_or_die(
      $DB,
      "WITH removed AS (
         DELETE FROM challenge_tag
         WHERE challenge_id = $1 AND player_id = $2 AND NOT (tag_value_id = ANY($3::int[]))
         RETURNING id
       ), added AS (
         INSERT INTO challenge_tag (challenge_id, player_id, tag_value_id)
         SELECT $1, $2, value_id FROM unnest($3::int[]) AS value_id
         ON CONFLICT (challenge_id, player_id, tag_value_id) DO NOTHING
         RETURNING id
       )
       SELECT (SELECT COUNT(*) FROM added) AS added, (SELECT COUNT(*) FROM removed) AS removed",
      [$challenge_id, $player_id, $ids_str],
      "Failed to save tag assignments"
    );
    $row = pg_fetch_assoc($result);
    return [
      'added' => intval($row['added']),
      'removed' => intval($row['removed']),
    ];
  }

  /**
   * Transfers all assignments from one challenge to another (challenge merging / splitting).
   * If a player already has a value of the same tag on the target challenge, the target's values win.
   * @param bool $copy if false, the assignments on the source challenge are deleted afterwards
   */
  static function transfer_challenge_tags($DB, int $from_challenge_id, int $to_challenge_id, bool $copy = false)
  {
    pg_query_params_or_die(
      $DB,
      "INSERT INTO challenge_tag (challenge_id, player_id, tag_value_id, date_created)
       SELECT $2, src.player_id, src.tag_value_id, src.date_created
       FROM challenge_tag src
       JOIN tag_value src_value ON src_value.id = src.tag_value_id
       WHERE src.challenge_id = $1
         AND NOT EXISTS (
           SELECT 1 FROM challenge_tag dst
           JOIN tag_value dst_value ON dst_value.id = dst.tag_value_id
           WHERE dst.challenge_id = $2 AND dst.player_id = src.player_id AND dst_value.tag_id = src_value.tag_id
         )
       ON CONFLICT (challenge_id, player_id, tag_value_id) DO NOTHING",
      [$from_challenge_id, $to_challenge_id],
      "Failed to transfer tag assignments"
    );

    if (!$copy) {
      pg_query_params_or_die($DB, "DELETE FROM challenge_tag WHERE challenge_id = $1", [$from_challenge_id], "Failed to delete old tag assignments");
    }
  }

  /**
   * Moves all assignments from one player to another (player merging).
   * If the target player already has a value of the same tag on a challenge, the target's values win.
   * @return int number of moved assignments
   */
  static function transfer_player_tags($DB, int $from_player_id, int $to_player_id): int
  {
    $result = pg_query_params_or_die(
      $DB,
      "INSERT INTO challenge_tag (challenge_id, player_id, tag_value_id, date_created)
       SELECT src.challenge_id, $2, src.tag_value_id, src.date_created
       FROM challenge_tag src
       JOIN tag_value src_value ON src_value.id = src.tag_value_id
       WHERE src.player_id = $1
         AND NOT EXISTS (
           SELECT 1 FROM challenge_tag dst
           JOIN tag_value dst_value ON dst_value.id = dst.tag_value_id
           WHERE dst.player_id = $2 AND dst.challenge_id = src.challenge_id AND dst_value.tag_id = src_value.tag_id
         )
       ON CONFLICT (challenge_id, player_id, tag_value_id) DO NOTHING",
      [$from_player_id, $to_player_id],
      "Failed to transfer tag assignments"
    );
    $moved = pg_affected_rows($result);

    pg_query_params_or_die($DB, "DELETE FROM challenge_tag WHERE player_id = $1", [$from_player_id], "Failed to delete old tag assignments");
    return $moved;
  }
  #endregion

  #region Filter Functions
  /**
   * Parses and resolves a tag filter into value id sets. Dies with an error response on invalid input.
   *
   * Input format:
   * {
   *   "confidence": 3,                    // optional, default 1
   *   "conditions": [
   *     { "tag_id": 1, "mode": "include", "min": 12, "max": 14 },   // ordinal tags only
   *     { "tag_id": 3, "mode": "exclude" },                         // all values of the tag
   *     { "tag_id": 7, "values": [20, 21] }                         // explicit value set, mode defaults to include
   *   ]
   * }
   *
   * Semantics: conditions are ANDed, values in one condition are ORed. A condition's "support" is the number of
   * distinct players that assigned any of its values. Includes match and excludes remove when support >= required.
   * Team-only tags always have a required support of 1.
   *
   * @param mixed $filter JSON string, decoded array or null
   * @return array ['confidence' => int, 'conditions' => list of ['tag_id', 'mode', 'value_ids', 'required']]
   */
  static function parse_filter($DB, $filter): array
  {
    if ($filter === null || $filter === '') {
      return ['confidence' => 1, 'conditions' => []];
    }
    if (is_string($filter)) {
      $filter = json_decode($filter, true);
      if (!is_array($filter)) {
        die_json(400, "filter is not valid JSON");
      }
    }
    if (!is_array($filter)) {
      die_json(400, "Invalid filter");
    }

    $confidence = 1;
    if (isset($filter['confidence'])) {
      if (!is_int($filter['confidence']) && !(is_string($filter['confidence']) && is_id_string($filter['confidence']))) {
        die_json(400, "filter.confidence must be a number");
      }
      $confidence = intval($filter['confidence']);
      if ($confidence < 1 || $confidence > self::$MAX_CONFIDENCE) {
        die_json(400, "filter.confidence must be between 1 and " . self::$MAX_CONFIDENCE);
      }
    }

    $raw_conditions = $filter['conditions'] ?? [];
    if (!is_array($raw_conditions) || !array_is_list($raw_conditions)) {
      die_json(400, "filter.conditions must be a list");
    }
    if (count($raw_conditions) > self::$MAX_FILTER_CONDITIONS) {
      die_json(400, "A filter can't have more than " . self::$MAX_FILTER_CONDITIONS . " conditions");
    }

    $defs = Tag::get_definitions($DB);
    $conditions = [];
    foreach ($raw_conditions as $raw) {
      if (!is_array($raw) || !isset($raw['tag_id'])) {
        die_json(400, "Each filter condition needs a tag_id");
      }
      $tag_id = intval($raw['tag_id']);
      $tag = $defs['tags'][$tag_id] ?? null;
      if ($tag === null) {
        die_json(400, "Tag with id {$tag_id} does not exist");
      }

      $mode = $raw['mode'] ?? 'include';
      if ($mode !== 'include' && $mode !== 'exclude') {
        die_json(400, "Filter condition mode must be 'include' or 'exclude'");
      }

      $has_values = isset($raw['values']);
      $has_bounds = isset($raw['min']) || isset($raw['max']);
      if ($has_values && $has_bounds) {
        die_json(400, "A filter condition can't have both values and min/max");
      }

      if ($has_values) {
        if (!is_array($raw['values']) || !array_is_list($raw['values']) || count($raw['values']) === 0) {
          die_json(400, "Filter condition values must be a non-empty list");
        }
        $value_ids = [];
        foreach ($raw['values'] as $value_id) {
          $value_id = intval($value_id);
          if (!$tag->has_value($value_id)) {
            die_json(400, "Value with id {$value_id} does not belong to tag '{$tag->name}'");
          }
          if (!in_array($value_id, $value_ids, true))
            $value_ids[] = $value_id;
        }
      } else if ($has_bounds) {
        if (!$tag->is_ordinal) {
          die_json(400, "Tag '{$tag->name}' is not ordinal and can't be filtered by min/max");
        }
        $min = isset($raw['min']) ? intval($raw['min']) : null;
        $max = isset($raw['max']) ? intval($raw['max']) : null;
        if ($min !== null && !$tag->has_value($min)) {
          die_json(400, "Value with id {$min} does not belong to tag '{$tag->name}'");
        }
        if ($max !== null && !$tag->has_value($max)) {
          die_json(400, "Value with id {$max} does not belong to tag '{$tag->name}'");
        }
        $value_ids = $tag->get_value_ids_between($min, $max);
        if (count($value_ids) === 0) {
          die_json(400, "min of tag '{$tag->name}' is above max");
        }
      } else {
        $value_ids = $tag->get_value_ids();
      }

      $conditions[] = [
        'tag_id' => $tag_id,
        'mode' => $mode,
        'value_ids' => $value_ids,
        'required' => $tag->is_player_assignable ? $confidence : 1,
      ];
    }

    return ['confidence' => $confidence, 'conditions' => $conditions];
  }

  /**
   * Builds the SQL fragments for a parsed filter (see parse_filter).
   * Returns CTE definitions to put in a WITH clause, and WHERE conditions on the given challenge id column.
   * All values are added to $params as query parameters.
   * @return array ['ctes' => string[], 'where' => string[]]
   */
  static function build_filter_sql(array $parsed_filter, array &$params, string $challenge_id_column = 'challenge.id'): array
  {
    $ctes = [];
    $where = [];

    foreach (['include' => 'tag_filter_included', 'exclude' => 'tag_filter_excluded'] as $mode => $cte_name) {
      $conditions = array_values(array_filter($parsed_filter['conditions'], fn($c) => $c['mode'] === $mode));
      if (count($conditions) === 0)
        continue;

      $all_value_ids = [];
      $having = [];
      foreach ($conditions as $condition) {
        $all_value_ids = array_merge($all_value_ids, $condition['value_ids']);
        $set_param = self::add_param($params, "{" . implode(",", $condition['value_ids']) . "}");
        $required = intval($condition['required']);
        $having[] = "COUNT(DISTINCT player_id) FILTER (WHERE tag_value_id = ANY({$set_param}::int[])) >= {$required}";
      }
      $all_param = self::add_param($params, "{" . implode(",", array_unique($all_value_ids)) . "}");

      // For excludes, a challenge is removed if ANY exclude condition matches
      $joiner = $mode === 'include' ? " AND " : " OR ";
      $ctes[] = "{$cte_name} AS (
        SELECT challenge_id
        FROM challenge_tag
        WHERE tag_value_id = ANY({$all_param}::int[])
        GROUP BY challenge_id
        HAVING " . implode($joiner, $having) . "
      )";

      $operator = $mode === 'include' ? "IN" : "NOT IN";
      $where[] = "{$challenge_id_column} {$operator} (SELECT challenge_id FROM {$cte_name})";
    }

    return ['ctes' => $ctes, 'where' => $where];
  }

  private static function add_param(array &$params, $value): string
  {
    $params[] = $value;
    return '$' . count($params);
  }
  #endregion

  #region Utility Functions
  private static function get_tag_of_value(array $defs, int $value_id): ?Tag
  {
    $value = $defs['values'][$value_id] ?? null;
    if ($value === null)
      return null;
    return $defs['tags'][$value->tag_id] ?? null;
  }

  /**
   * @return string|null null if the value can be freely assigned, otherwise the reason why it can't
   */
  private static function get_locked_reason(array $defs, int $value_id, bool $is_team): ?string
  {
    $tag = self::get_tag_of_value($defs, $value_id);
    if ($tag === null)
      return null;
    $category = $defs['categories_by_id'][$tag->category_id];
    if ($category->is_archived)
      return "belongs to an archived category and can't be assigned";
    if (!$tag->is_player_assignable && !$is_team)
      return "can only be assigned by team members";
    return null;
  }

  private static function group_value_ids_by_tag(array $defs, array $value_ids): array
  {
    $grouped = [];
    foreach ($value_ids as $id) {
      $tag = self::get_tag_of_value($defs, $id);
      if ($tag === null)
        continue;
      $grouped[$tag->id][] = $id;
    }
    return $grouped;
  }

  function __toString()
  {
    return "(ChallengeTag, id:{$this->id}, challenge_id:{$this->challenge_id}, player_id:{$this->player_id}, tag_value_id:{$this->tag_value_id})";
  }
  #endregion
}
