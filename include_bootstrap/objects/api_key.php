<?php

class ApiKey extends DbObject
{
  public static string $table_name = 'api_key';

  public static int $MAX_KEYS_PER_ACCOUNT = 20;
  public static int $MAX_NAME_LENGTH = 64;

  // User scopes
  public static int $SCOPE_SUBMISSION_CREATE = 1;
  public static int $SCOPE_SUBMISSION_MANAGE = 2;
  public static int $SCOPE_ACCOUNT_MANAGE = 4;
  public static int $SCOPE_LIKE = 8;
  public static int $SCOPE_INGAME_STATUS = 16;
  public static int $SCOPE_SUGGESTION = 32;
  public static int $SCOPE_TAG = 64;
  public static int $SCOPE_ALL_USER = 127;

  // Helper+ scopes
  public static int $SCOPE_CONTENT_CREATE = 256;
  public static int $SCOPE_CONTENT_MANAGE = 512;
  public static int $SCOPE_SUBMISSION_VERIFY = 1024;
  public static int $SCOPE_ALL_HELPER = 1792;

  public static int $SCOPE_ALL = 1919;

  public static array $SCOPE_NAMES = [
    1 => "Create submissions",
    2 => "Manage own submissions",
    4 => "Manage account",
    8 => "Create and manage likes/wishlist",
    16 => "Create and manage ingame status",
    32 => "Create and manage suggestions and votes",
    64 => "Create and manage own tag votes",
    256 => "Create campaigns/maps/challenges/players",
    512 => "Manage campaigns/maps/challenges",
    1024 => "Verify and manage all submissions",
  ];

  public int $account_id;
  public string $name;
  public string $key;
  public int $scopes = 0;
  public ?JsonDateTime $date_created = null;

  #region Abstract Functions
  function get_field_set()
  {
    return array(
      'account_id' => $this->account_id,
      'name' => $this->name,
      'key' => $this->key,
      'scopes' => $this->scopes,
      'date_created' => $this->date_created,
    );
  }

  static function static_field_set()
  {
    return [
      'account_id',
      'name',
      'key',
      'scopes',
      'date_created',
    ];
  }

  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->account_id = intval($arr[$prefix . 'account_id']);
    $this->name = $arr[$prefix . 'name'];
    $this->key = $arr[$prefix . 'key'];
    $this->scopes = intval($arr[$prefix . 'scopes']);
    $this->date_created = new JsonDateTime($arr[$prefix . 'date_created']);
  }

  protected function do_expand_foreign_keys($DB, $depth, $expand_structure)
  {
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
  static function find_by_key($DB, string $key)
  {
    $keys = find_in_db($DB, 'api_key', "key = $1", [$key], new ApiKey());
    if ($keys === false || count($keys) === 0) {
      return false;
    }
    return $keys[0];
  }

  static function find_all_for_account($DB, int $account_id)
  {
    $keys = find_in_db($DB, 'api_key', "account_id = $1", [$account_id], new ApiKey());
    return $keys === false ? [] : $keys;
  }
  #endregion

  #region Utility Functions
  function __toString()
  {
    return "(ApiKey, id: {$this->id}, account_id: {$this->account_id}, name: '{$this->name}', scopes: {$this->scopes})";
  }

  function has_scope(int $scope): bool
  {
    return ($this->scopes & $scope) === $scope;
  }

  static function generate_key(): string
  {
    // 32 random characters from 0-9a-f
    return bin2hex(random_bytes(16));
  }

  // Bitmask of all scopes an account with the given role may assign to its keys
  static function allowed_scopes_for_role(int $role): int
  {
    global $HELPER;
    return $role >= $HELPER ? ApiKey::$SCOPE_ALL : ApiKey::$SCOPE_ALL_USER;
  }

  static function get_scope_name(int $scope): string
  {
    return ApiKey::$SCOPE_NAMES[$scope] ?? "Unknown scope ({$scope})";
  }
  #endregion
}
