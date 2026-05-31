<?php

class StampSubmission extends DbObject
{
  public static string $table_name = 'stamp_submission';

  public int $stamp_id;
  public int $submission_id;
  public int $player_id;
  public JsonDateTime $date_assigned;

  // Linked Objects
  public ?Submission $submission = null;
  public ?Player $player = null;

  #region Abstract Functions
  function get_field_set()
  {
    return [
      'stamp_id' => $this->stamp_id,
      'submission_id' => $this->submission_id,
      'player_id' => $this->player_id,
      'date_assigned' => $this->date_assigned,
    ];
  }

  static function static_field_set()
  {
    return [
      'stamp_id',
      'submission_id',
      'player_id',
      'date_assigned',
    ];
  }

  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->stamp_id = intval($arr[$prefix . 'stamp_id']);
    $this->submission_id = intval($arr[$prefix . 'submission_id']);
    $this->player_id = intval($arr[$prefix . 'player_id']);
    $this->date_assigned = new JsonDateTime($arr[$prefix . 'date_assigned']);
  }

  protected function do_expand_foreign_keys($DB, $depth, $expand_structure)
  {
    if ($this->submission_id !== null) {
      $this->submission = Submission::get_by_id($DB, $this->submission_id, $depth - 1, $expand_structure);
    }
    if ($this->player_id !== null) {
      $this->player = Player::get_by_id($DB, $this->player_id, $depth - 1, $expand_structure);
    }
  }
  #endregion

  #region Expand Batching
  protected function get_expand_list($level, $expand_structure)
  {
    $arr = [];
    if ($level > 1) {
      if ($this->submission_id !== null && $this->submission !== null) {
        DbObject::merge_expand_lists($arr, $this->submission->get_expand_list($level - 1, $expand_structure));
      }
      if ($this->player_id !== null && $this->player !== null) {
        DbObject::merge_expand_lists($arr, $this->player->get_expand_list($level - 1, $expand_structure));
      }
      return $arr;
    }

    if ($this->submission_id !== null) {
      DbObject::add_to_expand_list($arr, Submission::class, $this->submission_id);
    }
    if ($this->player_id !== null) {
      DbObject::add_to_expand_list($arr, Player::class, $this->player_id);
    }
    return $arr;
  }

  protected function apply_expand_data($data, $level, $expand_structure)
  {
    if ($level > 1) {
      if ($this->submission_id !== null && $this->submission !== null) {
        $this->submission->apply_expand_data($data, $level - 1, $expand_structure);
      }
      if ($this->player_id !== null && $this->player !== null) {
        $this->player->apply_expand_data($data, $level - 1, $expand_structure);
      }
      return;
    }

    if ($this->submission_id !== null) {
      $this->submission = new Submission();
      $this->submission->apply_db_data(DbObject::get_object_from_data_list($data, Submission::class, $this->submission_id));
    }
    if ($this->player_id !== null) {
      $this->player = new Player();
      $this->player->apply_db_data(DbObject::get_object_from_data_list($data, Player::class, $this->player_id));
    }
  }
  #endregion

  #region Find Functions
  static function get_all_for_player($DB, $player_id)
  {

    $query = "SELECT * FROM " . static::$table_name . " WHERE player_id = $1 ORDER BY date_assigned DESC, id DESC";
    $result = pg_query_params_or_die($DB, $query, [$player_id], "Failed to fetch stamp submissions for player");

    $stampSubmissions = [];
    while ($row = pg_fetch_assoc($result)) {
      $stampSubmission = new StampSubmission();
      $stampSubmission->apply_db_data($row);
      $stampSubmissions[] = $stampSubmission;
    }
    return $stampSubmissions;
  }
  #endregion

  #region Utility Functions
  function __toString()
  {
    return "(StampSubmission, id:{$this->id}, stamp:{$this->stamp_id}, player:{$this->player_id}, submission:{$this->submission_id})";
  }
  #endregion
}
