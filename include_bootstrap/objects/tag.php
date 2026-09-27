<?php

class Tag extends DbObject
{
  public static string $table_name = 'tag';
  public static array $SELECTION_MODES = ['single', 'multi', 'range'];

  public int $category_id;
  public string $name;
  public string $description;
  public int $sort = 0;
  public bool $is_common = false;
  public bool $is_player_assignable = true;
  public bool $is_ordinal = false;
  public string $selection_mode = 'single';

  // Linked Objects
  public ?TagCategory $category = null;

  // Associative Objects
  public ?array $values = null; /* TagValue[], ordered by sort */

  // Cache of all tag definitions for the current request, see get_definitions()
  private static ?array $definitions_cache = null;


  #region Abstract Functions
  function get_field_set()
  {
    return array(
      'category_id' => $this->category_id,
      'name' => $this->name,
      'description' => $this->description,
      'sort' => $this->sort,
      'is_common' => $this->is_common,
      'is_player_assignable' => $this->is_player_assignable,
      'is_ordinal' => $this->is_ordinal,
      'selection_mode' => $this->selection_mode,
    );
  }

  static function static_field_set()
  {
    return [
      'category_id',
      'name',
      'description',
      'sort',
      'is_common',
      'is_player_assignable',
      'is_ordinal',
      'selection_mode',
    ];
  }

  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->category_id = intval($arr[$prefix . 'category_id']);
    $this->name = $arr[$prefix . 'name'];
    $this->description = $arr[$prefix . 'description'];
    $this->sort = intval($arr[$prefix . 'sort']);
    $this->is_common = $arr[$prefix . 'is_common'] === 't';
    $this->is_player_assignable = $arr[$prefix . 'is_player_assignable'] === 't';
    $this->is_ordinal = $arr[$prefix . 'is_ordinal'] === 't';
    $this->selection_mode = $arr[$prefix . 'selection_mode'];
  }

  protected function do_expand_foreign_keys($DB, $depth, $expand_structure)
  {
    if ($expand_structure && isset($this->category_id)) {
      $this->category = TagCategory::get_by_id($DB, $this->category_id, $depth - 1);
    }
    $this->fetch_values($DB);
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
  function fetch_values($DB): array
  {
    $result = pg_query_params_or_die($DB, "SELECT * FROM tag_value WHERE tag_id = $1 ORDER BY sort, id", [$this->id], "Failed to fetch tag values");
    $this->values = [];
    while ($row = pg_fetch_assoc($result)) {
      $value = new TagValue();
      $value->apply_db_data($row);
      $this->values[] = $value;
    }
    return $this->values;
  }

  /**
   * Loads all tag definitions (categories -> tags -> values) once per request.
   * @return array {
   *   categories: TagCategory[] (with ->tags, each with ->values),
   *   tags: array<int, Tag>,
   *   values: array<int, TagValue>
   * }
   */
  static function get_definitions($DB): array
  {
    if (self::$definitions_cache !== null) {
      return self::$definitions_cache;
    }

    $categories = TagCategory::get_all($DB);
    $categories_by_id = [];
    foreach ($categories as $category) {
      $category->tags = [];
      $categories_by_id[$category->id] = $category;
    }

    $tags = [];
    $result = pg_query_params_or_die($DB, "SELECT * FROM tag ORDER BY sort, id", [], "Failed to fetch tags");
    while ($row = pg_fetch_assoc($result)) {
      $tag = new Tag();
      $tag->apply_db_data($row);
      $tag->values = [];
      $tags[$tag->id] = $tag;
      $categories_by_id[$tag->category_id]->tags[] = $tag;
    }

    $values = [];
    $result = pg_query_params_or_die($DB, "SELECT * FROM tag_value ORDER BY sort, id", [], "Failed to fetch tag values");
    while ($row = pg_fetch_assoc($result)) {
      $value = new TagValue();
      $value->apply_db_data($row);
      $values[$value->id] = $value;
      $tags[$value->tag_id]->values[] = $value;
    }

    self::$definitions_cache = [
      'categories' => $categories,
      'categories_by_id' => $categories_by_id,
      'tags' => $tags,
      'values' => $values,
    ];
    return self::$definitions_cache;
  }

  static function clear_definitions_cache()
  {
    self::$definitions_cache = null;
  }
  #endregion

  #region Value Functions
  function get_value_ids(): array
  {
    return array_map(fn($v) => $v->id, $this->values ?? []);
  }

  function has_value(int $value_id): bool
  {
    foreach ($this->values ?? [] as $value) {
      if ($value->id === $value_id)
        return true;
    }
    return false;
  }

  /**
   * Returns the ids of all values whose sort is between the sort of the min and max value (inclusive).
   * Either bound can be null to leave that side open.
   */
  function get_value_ids_between(?int $min_value_id, ?int $max_value_id): array
  {
    $min_sort = null;
    $max_sort = null;
    foreach ($this->values ?? [] as $value) {
      if ($value->id === $min_value_id)
        $min_sort = $value->sort;
      if ($value->id === $max_value_id)
        $max_sort = $value->sort;
    }

    $ids = [];
    foreach ($this->values ?? [] as $value) {
      if ($min_sort !== null && $value->sort < $min_sort)
        continue;
      if ($max_sort !== null && $value->sort > $max_sort)
        continue;
      $ids[] = $value->id;
    }
    return $ids;
  }

  /**
   * Checks whether the given value ids form a gap-free block in this tag's value order.
   */
  function are_values_contiguous(array $value_ids): bool
  {
    if (count($value_ids) <= 1)
      return true;

    $indices = [];
    foreach ($this->values ?? [] as $index => $value) {
      if (in_array($value->id, $value_ids, true))
        $indices[] = $index;
    }
    if (count($indices) !== count($value_ids))
      return false;
    return max($indices) - min($indices) === count($indices) - 1;
  }

  /**
   * Validates and normalizes the "values" payload of a tag upsert request. Dies on invalid input.
   * - An empty list or a single entry without a name means the tag has no qualifiers (one implicit value).
   * - Otherwise, all entries must have a unique, non-empty name.
   * - Entries with an "id" update an existing value of this tag, entries without one are created.
   * - Order of the list determines the sort of the values.
   * @param mixed $payload the raw "values" field from the request body
   * @return array list of ['id' => ?int, 'name' => ?string, 'description' => ?string, 'sort' => int]
   */
  function parse_values_payload($payload): array
  {
    if ($payload === null)
      $payload = [];
    if (!is_array($payload) || !array_is_list($payload)) {
      die_json(400, "values must be a list");
    }
    if (count($payload) > 50) {
      die_json(400, "A tag can't have more than 50 values");
    }

    $existing_ids = isset($this->id) ? $this->get_value_ids() : [];
    $normalized = [];
    $seen_names = [];
    $seen_ids = [];

    foreach ($payload as $index => $entry) {
      if (!is_array($entry)) {
        die_json(400, "Each value must be an object");
      }

      $id = null;
      if (isset($entry['id'])) {
        $id = intval($entry['id']);
        if (!in_array($id, $existing_ids, true)) {
          die_json(400, "Value with id {$id} does not belong to this tag");
        }
        if (in_array($id, $seen_ids, true)) {
          die_json(400, "Value with id {$id} is listed more than once");
        }
        $seen_ids[] = $id;
      }

      $name = isset($entry['name']) && is_string($entry['name']) ? trim($entry['name']) : null;
      if ($name === '')
        $name = null;
      if ($name !== null) {
        if (mb_strlen($name) > 64) {
          die_json(400, "Value names can't be longer than 64 characters");
        }
        $lower = mb_strtolower($name);
        if (in_array($lower, $seen_names, true)) {
          die_json(400, "Value name '{$name}' is used more than once");
        }
        $seen_names[] = $lower;
      }

      $description = isset($entry['description']) && is_string($entry['description']) ? trim($entry['description']) : null;
      if ($description === '')
        $description = null;
      if ($description !== null && mb_strlen($description) > 5000) {
        die_json(400, "Value descriptions can't be longer than 5000 characters");
      }

      $normalized[] = [
        'id' => $id,
        'name' => $name,
        'description' => $description,
        'sort' => $index,
      ];
    }

    $implicit_count = count(array_filter($normalized, fn($v) => $v['name'] === null));
    if ($implicit_count > 0 && count($normalized) > 1) {
      die_json(400, "All values need a name if a tag has more than one value");
    }

    if (count($normalized) === 0) {
      // No qualifiers: reuse the only existing value (keeps its assignments) or create a new implicit one
      $normalized[] = [
        'id' => count($existing_ids) === 1 ? $existing_ids[0] : null,
        'name' => null,
        'description' => null,
        'sort' => 0,
      ];
    }

    return $normalized;
  }

  /**
   * Applies a normalized values list (see parse_values_payload) to this tag.
   * Values missing from the list are deleted (and their assignments with them).
   */
  function save_values($DB, array $normalized)
  {
    $keep_ids = array_values(array_filter(array_map(fn($v) => $v['id'], $normalized)));
    $keep_ids_str = "{" . implode(",", $keep_ids) . "}";

    // Delete first, so that a new implicit value can't collide with an old one on the partial unique index
    pg_query_params_or_die(
      $DB,
      "DELETE FROM tag_value WHERE tag_id = $1 AND NOT (id = ANY($2::int[]))",
      [$this->id, $keep_ids_str],
      "Failed to delete removed tag values"
    );

    foreach ($normalized as $entry) {
      $value = new TagValue();
      $value->tag_id = $this->id;
      $value->name = $entry['name'];
      $value->description = $entry['description'];
      $value->sort = $entry['sort'];

      if ($entry['id'] !== null) {
        $value->id = $entry['id'];
        if (!$value->update($DB)) {
          die_json(500, "Failed to update tag value {$value->id}");
        }
      } else if (!$value->insert($DB)) {
        die_json(500, "Failed to create tag value");
      }
    }

    self::clear_definitions_cache();
    $this->fetch_values($DB);
  }
  #endregion

  #region Utility Functions
  function get_values_string(): string
  {
    $names = array_map(fn($v) => $v->name ?? '<implicit>', $this->values ?? []);
    return implode(', ', $names);
  }

  function __toString()
  {
    return "(Tag, id:{$this->id}, name:'{$this->name}', category_id:{$this->category_id}, selection_mode:{$this->selection_mode})";
  }
  #endregion
}
