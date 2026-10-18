<?php

class IngameStatus extends DbObject
{
  public static string $table_name = 'ingame_status';

  public static int $MAX_ENTRIES_PER_ACCOUNT = 1000;
  public static int $MAX_STRING_LENGTH = 128;

  public int $account_id;
  public ?JsonDateTime $date = null;
  public ?int $challenge_id = null;
  public ?string $map_name = null; // Only set if challenge_id couldn't be determined
  public ?int $room_number = null;
  public ?int $max_room_number = null;
  public ?string $room_name = null;
  public ?string $custom_room_name = null;
  public bool $in_golden_run = false;
  public ?int $session_pb_room_number = null;
  public ?int $overall_pb_room_number = null;

  // Linked Objects
  public ?Challenge $challenge = null;

  #region Abstract Functions
  function get_field_set()
  {
    return array(
      'account_id' => $this->account_id,
      'date' => $this->date,
      'challenge_id' => $this->challenge_id,
      'map_name' => $this->map_name,
      'room_number' => $this->room_number,
      'max_room_number' => $this->max_room_number,
      'room_name' => $this->room_name,
      'custom_room_name' => $this->custom_room_name,
      'in_golden_run' => $this->in_golden_run,
      'session_pb_room_number' => $this->session_pb_room_number,
      'overall_pb_room_number' => $this->overall_pb_room_number,
    );
  }

  static function static_field_set()
  {
    return [
      'account_id',
      'date',
      'challenge_id',
      'map_name',
      'room_number',
      'max_room_number',
      'room_name',
      'custom_room_name',
      'in_golden_run',
      'session_pb_room_number',
      'overall_pb_room_number',
    ];
  }

  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->account_id = intval($arr[$prefix . 'account_id']);
    $this->date = new JsonDateTime($arr[$prefix . 'date']);
    $this->in_golden_run = $arr[$prefix . 'in_golden_run'] === 't';

    if (isset($arr[$prefix . 'challenge_id']))
      $this->challenge_id = intval($arr[$prefix . 'challenge_id']);
    if (isset($arr[$prefix . 'map_name']))
      $this->map_name = $arr[$prefix . 'map_name'];
    if (isset($arr[$prefix . 'room_number']))
      $this->room_number = intval($arr[$prefix . 'room_number']);
    if (isset($arr[$prefix . 'max_room_number']))
      $this->max_room_number = intval($arr[$prefix . 'max_room_number']);
    if (isset($arr[$prefix . 'room_name']))
      $this->room_name = $arr[$prefix . 'room_name'];
    if (isset($arr[$prefix . 'custom_room_name']))
      $this->custom_room_name = $arr[$prefix . 'custom_room_name'];
    if (isset($arr[$prefix . 'session_pb_room_number']))
      $this->session_pb_room_number = intval($arr[$prefix . 'session_pb_room_number']);
    if (isset($arr[$prefix . 'overall_pb_room_number']))
      $this->overall_pb_room_number = intval($arr[$prefix . 'overall_pb_room_number']);
  }

  protected function do_expand_foreign_keys($DB, $depth, $expand_structure)
  {
    if ($this->challenge_id !== null) {
      $this->challenge = Challenge::get_by_id($DB, $this->challenge_id, $depth - 1, $expand_structure);
    }
  }

  protected function get_expand_list($level, $expand_structure)
  {
    $arr = [];
    if ($level > 1) {
      if ($this->challenge_id !== null && $this->challenge !== null) {
        DbObject::merge_expand_lists($arr, $this->challenge->get_expand_list($level - 1, $expand_structure));
      }
      return $arr;
    }

    if ($this->challenge_id !== null) {
      DbObject::add_to_expand_list($arr, Challenge::class, $this->challenge_id);
    }
    return $arr;
  }

  protected function apply_expand_data($data, $level, $expand_structure)
  {
    if ($level > 1) {
      if ($this->challenge_id !== null && $this->challenge !== null) {
        $this->challenge->apply_expand_data($data, $level - 1, $expand_structure);
      }
      return;
    }

    if ($this->challenge_id !== null) {
      $this->challenge = new Challenge();
      $this->challenge->apply_db_data(DbObject::get_object_from_data_list($data, Challenge::class, $this->challenge_id));
    }
  }
  #endregion

  #region Find Functions
  // Returns the newest $count entries of an account, newest first
  static function find_latest_for_account($DB, int $account_id, int $count)
  {
    $query = "SELECT * FROM ingame_status WHERE account_id = $1 ORDER BY date DESC, id DESC LIMIT $2";
    $result = pg_query_params_or_die($DB, $query, [$account_id, $count]);
    $statuses = [];
    while ($row = pg_fetch_assoc($result)) {
      $status = new IngameStatus();
      $status->apply_db_data($row);
      $statuses[] = $status;
    }
    return $statuses;
  }

  // Returns the newest $count entries of every account that had activity in the last $recency_minutes, grouped by account_id.
  // Accounts are ordered by their most recent activity, newest first
  static function find_online($DB, int $recency_minutes, int $count, ?int $account_id = null)
  {
    $params = [$recency_minutes, $count];
    $account_filter = "";
    if ($account_id !== null) {
      $params[] = $account_id;
      $account_filter = "AND account_id = $3";
    }

    $query = "WITH active AS (
        SELECT account_id, MAX(date) AS last_activity
        FROM ingame_status
        WHERE date >= NOW() - make_interval(mins => $1) {$account_filter}
        GROUP BY account_id
      ), ranked AS (
        SELECT s.*, a.last_activity, ROW_NUMBER() OVER (PARTITION BY s.account_id ORDER BY s.date DESC, s.id DESC) AS rn
        FROM ingame_status s
        JOIN active a ON a.account_id = s.account_id
      )
      SELECT * FROM ranked WHERE rn <= $2 ORDER BY last_activity DESC, account_id, rn";
    $result = pg_query_params_or_die($DB, $query, $params);

    $grouped = [];
    while ($row = pg_fetch_assoc($result)) {
      $status = new IngameStatus();
      $status->apply_db_data($row);
      $grouped[$status->account_id][] = $status;
    }
    return $grouped;
  }

  static function count_for_account($DB, int $account_id): int
  {
    $result = pg_query_params_or_die($DB, "SELECT COUNT(*) FROM ingame_status WHERE account_id = $1", [$account_id]);
    return intval(pg_fetch_result($result, 0, 0));
  }

  // Deletes the oldest entries of an account so that at most $MAX_ENTRIES_PER_ACCOUNT remain
  static function prune_for_account($DB, int $account_id)
  {
    $query = "DELETE FROM ingame_status WHERE account_id = $1 AND id NOT IN (
        SELECT id FROM ingame_status WHERE account_id = $1 ORDER BY date DESC, id DESC LIMIT $2
      )";
    pg_query_params_or_die($DB, $query, [$account_id, IngameStatus::$MAX_ENTRIES_PER_ACCOUNT]);
  }

  static function delete_all_for_account($DB, int $account_id)
  {
    pg_query_params_or_die($DB, "DELETE FROM ingame_status WHERE account_id = $1", [$account_id]);
  }

  // Fetches the challenges of all statuses once per unique challenge and attaches them
  static function expand_challenges($DB, array $statuses)
  {
    $challenges = [];
    foreach ($statuses as $status) {
      if ($status->challenge_id === null) {
        continue;
      }
      if (!array_key_exists($status->challenge_id, $challenges)) {
        $challenge = Challenge::get_by_id($DB, $status->challenge_id, 3);
        $challenges[$status->challenge_id] = $challenge === false ? null : $challenge;
      }
      $status->challenge = $challenges[$status->challenge_id];
    }
  }
  #endregion

  #region Utility Functions
  function __toString()
  {
    $location = $this->challenge_id !== null ? "challenge_id: {$this->challenge_id}" : "map_name: '{$this->map_name}'";
    return "(IngameStatus, id: {$this->id}, account_id: {$this->account_id}, {$location}, room: {$this->room_number}/{$this->max_room_number})";
  }
  #endregion
}
