<?php

//This is an internal class and should never be written through the API
class Showcase extends DbObject implements JsonSerializable
{
  public static string $table_name = 'showcase';

  public int $account_id;
  public int $index;
  public int $submission_id;

  // Linked Objects
  public ?Account $account = null;
  public ?Submission $submission = null;

  #region Abstract Functions
  function get_field_set()
  {
    return array(
      'account_id' => $this->account_id,
      'index' => $this->index,
      'submission_id' => $this->submission_id,
    );
  }
  static function static_field_set()
  {
    return [
      'account_id',
      'index',
      'submission_id',
    ];
  }

  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->account_id = intval($arr[$prefix . 'account_id']);
    $this->index = intval($arr[$prefix . 'index']);
    $this->submission_id = intval($arr[$prefix . 'submission_id']);
  }

  protected function do_expand_foreign_keys($DB, $depth, $expand_structure)
  {
    if ($expand_structure) {
      if ($this->submission_id !== null) {
        $this->submission = Submission::get_by_id($DB, $this->submission_id, $depth - 1, $expand_structure);
      }
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
      return $arr;
    }

    if ($expand_structure && $this->submission_id !== null) {
      DbObject::add_to_expand_list($arr, Submission::class, $this->submission_id);
    }
    return $arr;
  }

  protected function apply_expand_data($data, $level, $expand_structure)
  {
    if ($level > 1) {
      if ($expand_structure && $this->submission_id !== null && $this->submission !== null) {
        $this->submission->apply_expand_data($data, $level - 1, $expand_structure);
      }
      return;
    }

    if ($expand_structure && $this->submission_id !== null) {
      $this->submission = new Submission();
      $this->submission->apply_db_data(DbObject::get_object_from_data_list($data, Submission::class, $this->submission_id));
    }
  }
  #endregion

  #region Find Functions
  static function find_all_for_account_id($DB, $account_id)
  {
    $query = "SELECT * FROM showcase WHERE account_id = $1 ORDER BY index ASC";
    $result = pg_query_params($DB, $query, array($account_id));

    $showcase_objs = array();
    while ($row = pg_fetch_assoc($result)) {
      $showcase_obj = new Showcase();
      $showcase_obj->apply_db_data($row);
      $showcase_obj->expand_foreign_keys($DB, 5);
      $showcase_objs[] = $showcase_obj;
    }

    return $showcase_objs;
  }
  #endregion

  #region Utility Functions
  function jsonSerialize()
  {
    //Throw an error to prevent serialization
    throw new Exception("Invalid operation");
  }

  function __toString()
  {
    return "(Showcase, id:{$this->id}, account_id:{$this->account_id}, index:{$this->index}, submission_id:{$this->submission_id})";
  }
  #endregion
}