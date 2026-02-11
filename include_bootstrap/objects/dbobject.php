<?php

abstract class DbObject
{
  public static string $table_name;

  public int $id;
  private int $max_expanded = 1;
  private int $max_expanded_structure = 1;

  #region Abstract Functions
  abstract function get_field_set();
  abstract static function static_field_set();
  abstract function apply_db_data($arr, $prefix = '');
  protected abstract function do_expand_foreign_keys($DB, $depth, $expand_structure);

  // $DB is either a database connection or an array containing a row of the results of a query
  function expand_foreign_keys($DB, $depth = 2, $expand_structure = true)
  {
    if ($depth <= 1)
      return;

    if ($expand_structure) {
      if ($this->max_expanded_structure >= $depth)
        return;
      $this->max_expanded_structure = $depth;
    } else {
      if ($this->max_expanded >= $depth)
        return;
      $this->max_expanded = $depth;
    }

    $this->do_expand_foreign_keys($DB, $depth, $expand_structure);
  }

  #endregion

  #region Update Functions
  function insert($DB)
  {
    $arr = $this->get_field_set();
    $id = db_insert($DB, static::$table_name, $arr);
    if ($id === false)
      return false;

    $arr = db_fetch_id($DB, static::$table_name, $id);
    if ($arr === false)
      return false;
    $this->apply_db_data($arr);

    return true;
  }

  function update($DB)
  {
    $arr = $this->get_field_set();
    return db_update($DB, static::$table_name, $this->id, $arr);
  }

  function delete($DB)
  {
    return db_delete($DB, static::$table_name, $this->id);
  }
  #endregion

  #region Find Functions
  static function get_by_id($DB, int $id, int $depth = 2, $expand_structure = true)
  {
    if ($id === null)
      return false;

    $arr = db_fetch_id($DB, static::$table_name, $id);
    if ($arr === false)
      return false;

    $obj = new static;
    $obj->apply_db_data($arr);
    $obj->expand_foreign_keys($DB, $depth, $expand_structure);
    return $obj;
  }

  static function get_many_by_id($DB, array $ids, int $depth = 2, $expand_structure = true): array|bool
  {
    if ($ids === null)
      return false;

    $result = db_fetch_id_many($DB, static::$table_name, $ids);
    if ($result === false)
      return false;

    $ret = [];
    while ($row = pg_fetch_assoc($result)) {
      $obj = new static;
      $obj->apply_db_data($row);
      $obj->expand_foreign_keys($DB, $depth, $expand_structure);
      $ret[] = $obj;
    }
    return $ret;
  }

  // $id can be an ID, an array of IDs, or "all"
  static function get_request($DB, $id, int $depth = 2, $expand_structure = true)
  {
    $json_arr = array();
    $table = static::$table_name;

    if ($id === "all") {
      $result = pg_query($DB, "SELECT * FROM {$table};");
      if ($result === false)
        die_json(500, "Failed to query database");

      while ($row = pg_fetch_assoc($result)) {
        $obj = new static;
        $obj->apply_db_data($row);
        if ($depth > 1)
          $obj->expand_foreign_keys($DB, $depth, $expand_structure);
        $json_arr[] = $obj;
      }
      return $json_arr;
    }
    if (!is_valid_id_query($id)) {
      die_json(400, 'Invalid or missing ID');
    }

    if (is_array($id)) {
      foreach ($id as $val) {
        $obj = static::get_by_id($DB, intval($val), $depth, $expand_structure);
        if ($obj === false) {
          die_json(404, "ID {$val} does not exist");
        }
        $json_arr[] = $obj;
      }
      return $json_arr;
    } else {
      $obj = static::get_by_id($DB, $id, $depth, $expand_structure);
      if ($obj === false) {
        die_json(404, "ID {$id} does not exist");
      }
      return $obj;
    }
  }

  function fetch_list($DB, $id_col, $class, string $whereAddition = null, $orderBy = "ORDER BY id")
  {
    $arr = db_fetch_assoc($DB, $class::$table_name, $id_col, $this->id, $class, $whereAddition, $orderBy);
    if ($arr === false)
      return false;
    return $arr;
  }
  #endregion

  #region Utility Functions
  function __toString()
  {
    return "({$this->table_name}, id: {$this->id})";
  }

  function has_fields_set(array $arr): bool
  {
    foreach ($arr as $field) {
      if (!isset($this->$field)) {
        return false;
      }
    }
    return true;
  }

  /**
   * Should return an array of fields formatted for use in a SELECT statement.
   * E.g. ["player.id AS player_id", "player.name AS player_name"]
   * @param mixed $prefix specify a prefix to add to the field names, e.g. "player_"
   * @param mixed $table_name specify a different table name than the default, e.g. postgres table alias
   * @return array An array of strings formatted for use in a SELECT statement.
   */
  static function format_fields_for_select($prefix = null, $table_name = null)
  {
    $table = $table_name ?? static::$table_name;
    $fields = static::static_field_set();
    // Add ID field as its in every table
    array_unshift($fields, 'id');
    $formatted = [];
    foreach ($fields as $field) {
      $as = $prefix ? "{$prefix}{$field}" : $field;
      $formatted[] = "{$table}.{$field} AS {$as}";
    }
    return $formatted;
  }

  #endregion


  #region Expand List
  /**
   * Fetches the FK data for the given list of DbObjects.
   * @param mixed $DB the database connection to use for fetching the data
   * @param mixed $objects an array of DbObjects to fetch the data for
   * @return void
   */
  static function fetch_data_for_objects($DB, $objects, $depth, $expand_structure)
  {
    // Algorithm:
    // - Loop through each level of the hierarchy until the max depth is reached
    // - For each level, get the expand list from all objects and merge them together
    // - Fetch all data for each table simultaneously and store it in a data list
    // - Loop through each object and apply the data from the data list
    $dataCache = [];
    for ($level = 1; $level <= $depth; $level++) {
      $expand_list = [];
      foreach ($objects as $obj) {
        $obj_expand_list = $obj->get_expand_list($level, $expand_structure);
        $expand_list = self::merge_expand_lists($expand_list, $obj_expand_list);
      }

      foreach ($expand_list as $table => $ids) {
        $fetched_data = db_fetch_id_many($DB, $table, $ids);
        if ($fetched_data === false) {
          continue;
        }
        foreach ($fetched_data as $obj) {
          if (!isset($dataCache[$table]))
            $dataCache[$table] = [];
          $dataCache[$table][$obj->id] = $obj;
        }
      }

      foreach ($objects as $obj) {
        $obj->apply_expand_data($dataCache, $level, $expand_structure);
      }
    }
  }

  static function add_to_expand_list($expand_list, $class, $id)
  {
    if (!isset($expand_list[$class::$table_name]))
      $expand_list[$class::$table_name] = [];
    if (!in_array($id, $expand_list[$class::$table_name]))
      $expand_list[$class::$table_name][] = $id;
  }
  static function merge_expand_lists($expand_list1, $expand_list2)
  {
    foreach ($expand_list2 as $table => $ids) {
      if (!isset($expand_list1[$table]))
        $expand_list1[$table] = [];
      foreach ($ids as $id) {
        if (!in_array($id, $expand_list1[$table]))
          $expand_list1[$table][] = $id;
      }
    }
    return $expand_list1;
  }
  static function get_object_from_data_list($data, $class, $id)
  {
    if (!isset($data[$class::$table_name]) || !in_array($id, $data[$class::$table_name]))
      return null;
    return $data[$class::$table_name][$id] ?? null;
  }
  #endregion
}