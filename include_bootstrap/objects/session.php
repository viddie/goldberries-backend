<?php

$session_expire_days = 31;

class Session extends DbObject
{
  public static string $table_name = 'session';

  public string $token;
  public JsonDateTime $created;

  // Foreign Keys
  public int $account_id;

  // Linked Objects
  public ?Account $account = null;


  #region Abstract Functions
  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->token = $arr[$prefix . 'token'];
    $this->created = new JsonDateTime($arr[$prefix . 'created']);
    $this->account_id = intval($arr[$prefix . 'account_id']);
  }
  protected function do_expand_foreign_keys($DB, $depth, $expand_structure)
  {
    if ($this->account_id !== null) {
      $this->account = Account::get_by_id($DB, $this->account_id, $depth - 1);
      $this->account->expand_foreign_keys($DB, $depth - 1, $expand_structure);
    } else {
      $this->account = null;
    }
  }
  #endregion

  #region Expand Batching
  protected function get_expand_list($level, $expand_structure)
  {
    $arr = [];
    if ($level > 1) {
      if ($this->account_id !== null) {
        DbObject::merge_expand_lists($arr, $this->account->get_expand_list($level - 1, $expand_structure));
      }
      return $arr;
    }

    if ($this->account_id !== null) {
      DbObject::add_to_expand_list($arr, Account::class, $this->account_id);
    }
    return $arr;
  }

  protected function apply_expand_data($data, $level, $expand_structure)
  {
    if ($level > 1) {
      if ($this->account_id !== null) {
        $this->account->apply_expand_data($data, $level - 1, $expand_structure);
      }
      return;
    }

    if ($this->account_id !== null) {
      $this->account = new Account();
      $this->account->apply_db_data(DbObject::get_object_from_data_list($data, Account::class, $this->account_id));
    }
  }
  #endregion

  function get_field_set()
  {
    return array(
      'token' => $this->token,
      'created' => $this->created,
      'account_id' => $this->account_id
    );
  }
  static function static_field_set()
  {
    return [
      'token',
      'created',
      'account_id'
    ];
  }

  #region Find Functions
  static function find_by_token($DB, string $token)
  {
    global $session_expire_days;
    return find_in_db($DB, 'Session', "token = $1 AND created > NOW() - INTERVAL '$session_expire_days days'", array($token), new Session());
  }
  #endregion

  #region Utility Functions
  function __toString()
  {
    return "(Session, id: {$this->id}, account_id: {$this->account_id}, expires: {$this->created})";
  }
  #endregion
}
