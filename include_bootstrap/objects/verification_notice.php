<?php

class VerificationNotice extends DbObject
{
  public static string $table_name = 'verification_notice';

  public int $verifier_id;
  public int $submission_id;
  public ?string $message = null;

  // Linked Objects
  public ?Player $verifier = null;
  public ?Submission $submission = null;

  #region Abstract Functions
  function get_field_set()
  {
    return array(
      'verifier_id' => $this->verifier_id,
      'submission_id' => $this->submission_id,
      'message' => $this->message,
    );
  }
  static function static_field_set()
  {
    return [
      'verifier_id',
      'submission_id',
      'message',
    ];
  }

  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->verifier_id = intval($arr[$prefix . 'verifier_id']);
    $this->submission_id = intval($arr[$prefix . 'submission_id']);

    if (isset($arr[$prefix . 'message'])) {
      $this->message = $arr[$prefix . 'message'];
    }
  }

  protected function do_expand_foreign_keys($DB, $depth, $expand_structure)
  {
    if ($expand_structure) {
      if ($this->submission_id !== null) {
        $this->submission = Submission::get_by_id($DB, $this->submission_id, $depth - 1, $expand_structure);
      }
    }
    if ($this->verifier_id !== null) {
      $this->verifier = Player::get_by_id($DB, $this->verifier_id, $depth - 1, $expand_structure);
    }
  }
  #endregion

  #region Expand Batching
  protected function get_expand_list($level, $expand_structure)
  {
    $arr = [];
    if ($level > 1) {
      if ($expand_structure && $this->submission_id !== null && $this->submission !== null) {
        DbObject::merge_expand_lists($arr, $this->submission->get_expand_list($level - 1, $expand_structure));
      }
      if ($this->verifier_id !== null && $this->verifier !== null) {
        DbObject::merge_expand_lists($arr, $this->verifier->get_expand_list($level - 1, $expand_structure));
      }
      return $arr;
    }

    if ($expand_structure && $this->submission_id !== null) {
      DbObject::add_to_expand_list($arr, Submission::class, $this->submission_id);
    }
    if ($this->verifier_id !== null) {
      DbObject::add_to_expand_list($arr, Player::class, $this->verifier_id);
    }
    return $arr;
  }

  protected function apply_expand_data($data, $level, $expand_structure)
  {
    if ($level > 1) {
      if ($expand_structure && $this->submission_id !== null && $this->submission !== null) {
        $this->submission->apply_expand_data($data, $level - 1, $expand_structure);
      }
      if ($this->verifier_id !== null && $this->verifier !== null) {
        $this->verifier->apply_expand_data($data, $level - 1, $expand_structure);
      }
      return;
    }

    if ($expand_structure && $this->submission_id !== null) {
      $this->submission = new Submission();
      $this->submission->apply_db_data(DbObject::get_object_from_data_list($data, Submission::class, $this->submission_id));
    }
    if ($this->verifier_id !== null) {
      $this->verifier = new Player();
      $this->verifier->apply_db_data(DbObject::get_object_from_data_list($data, Player::class, $this->verifier_id));
    }
  }
  #endregion

  #region Find Functions
  static function get_all($DB)
  {
    $query = "SELECT * FROM " . static::$table_name;
    $result = pg_query($DB, $query);
    $notices = [];
    while ($row = pg_fetch_assoc($result)) {
      $notice = new VerificationNotice();
      $notice->apply_db_data($row);
      $notice->expand_foreign_keys($DB, 5, false);
      $notices[] = $notice;
    }
    return $notices;
  }
  #endregion

  #region Utility Functions
  static function delete_for_submission_id($DB, $submission_id)
  {
    $query = "DELETE FROM " . static::$table_name . " WHERE submission_id = $1";
    $result = pg_query_params($DB, $query, array($submission_id));
    return $result;
  }

  function __toString()
  {
    $verifierName = $this->verifier?->name ?? "null";
    return "(VerificationNotice, id:{$this->id}, verifier:{$verifierName}, submission_id:{$this->submission_id})";
  }
  #endregion
}