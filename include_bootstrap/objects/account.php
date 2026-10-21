<?php
require_once(__DIR__ . '/../../assets/data/country-codes.php');

class Account extends DbObject
{
  public static string $table_name = 'account';

  public static int $NOTIF_SUB_VERIFIED = 1;
  public static int $NOTIF_CHALL_PERSONAL = 2;
  public static int $NOTIF_SUGGESTION_VERIFIED = 4;
  public static int $NOTIF_CHALL_MOVED = 8;
  public static int $NOTIF_SUGGESTION_ACCEPTED = 16;
  public static int $NOTIF_NEW_HARDEST = 32;

  public static int $RESTRICT_RENAME = 1;
  public static int $RESTRICT_PROFILE = 2;
  public static int $RESTRICT_SUBMIT = 4;
  public static int $RESTRICT_SUGGEST_DIFFICULTY = 8;
  public static int $RESTRICT_CREATE_SUGGESTION = 16;
  public static int $RESTRICT_VOTE = 32;
  public static int $RESTRICT_ADD_TAGS = 64;
  public static int $RESTRICT_INGAME_STATUS = 128;
  public static int $RESTRICT_ALL = 255;

  public ?string $email = null;
  public ?string $password = null;
  public ?string $discord_id = null;
  public ?JsonDateTime $date_created;
  public int $role = 0;
  public bool $is_suspended = false;
  public ?string $suspension_reason = null;
  public ?JsonDateTime $date_suspended = null; // NULL for suspensions from before this field existed
  public ?JsonDateTime $date_appeal = null; // NULL = indefinite suspension
  public bool $email_verified = false;
  public ?string $email_verify_code = null;

  // Profile Customization
  public ?array $links = null; // array of links, in DB: TSV of links
  public ?string $input_method = null;
  public ?string $about_me = null;
  public ?string $name_color_start = null;
  public ?string $name_color_end = null;
  public ?string $country = null;
  public ?string $stream_url = null;

  // Other
  public ?JsonDateTime $last_player_rename = null;
  public int $notifications = 3; //Default notifications: Account::$NOTIF_SUB_VERIFIED | Account::$NOTIF_CHALL_PERSONAL
  public int $restrictions = 0;
  public bool $using_api_key = false; //Field not set from DB, only set through auth process
  public ?ApiKey $active_api_key = null; //Field not set from DB, the key used to authenticate this request
  public ?int $api_key_scopes = null; //Field not set from DB, only exposed through check_session when using an API key

  // Foreign Keys
  public ?int $player_id = null;
  public ?int $claimed_player_id = null;

  // Linked Objects
  public ?Player $player = null;
  public ?Player $claimed_player = null;

  #region Abstract Functions
  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->date_created = new JsonDateTime($arr[$prefix . 'date_created']);
    $this->role = intval($arr[$prefix . 'role']);
    $this->is_suspended = $arr[$prefix . 'is_suspended'] === 't';
    $this->notifications = intval($arr[$prefix . 'notifications']);
    $this->restrictions = intval($arr[$prefix . 'restrictions'] ?? 0);

    if (isset($arr[$prefix . 'player_id']))
      $this->player_id = intval($arr[$prefix . 'player_id']);
    if (isset($arr[$prefix . 'claimed_player_id']))
      $this->claimed_player_id = intval($arr[$prefix . 'claimed_player_id']);
    if (isset($arr[$prefix . 'email']))
      $this->email = $arr[$prefix . 'email'];
    if (isset($arr[$prefix . 'password']))
      $this->password = $arr[$prefix . 'password'];
    if (isset($arr[$prefix . 'discord_id']))
      $this->discord_id = $arr[$prefix . 'discord_id'];
    if (isset($arr[$prefix . 'suspension_reason']))
      $this->suspension_reason = $arr[$prefix . 'suspension_reason'];
    if (isset($arr[$prefix . 'date_suspended']))
      $this->date_suspended = new JsonDateTime($arr[$prefix . 'date_suspended']);
    if (isset($arr[$prefix . 'date_appeal']))
      $this->date_appeal = new JsonDateTime($arr[$prefix . 'date_appeal']);
    if (isset($arr[$prefix . 'email_verified']))
      $this->email_verified = $arr[$prefix . 'email_verified'] === 't';
    if (isset($arr[$prefix . 'email_verify_code']))
      $this->email_verify_code = $arr[$prefix . 'email_verify_code'];
    if (isset($arr[$prefix . 'links'])) {
      $value = $arr[$prefix . 'links'];
      $this->links = is_array($value) ? $value : explode("\t", $value);
      //Trim all strings
      $this->links = array_map(fn($link) => is_string($link) ? trim($link) : $link, $this->links);
      //Remove empty strings, reindexing so it serializes as a JSON array
      $this->links = array_values(array_filter($this->links, function ($link) {
        return $link !== '';
      }));
      //Set to null if empty
      if (count($this->links) === 0)
        $this->links = null;
    }
    if (isset($arr[$prefix . 'input_method']))
      $this->input_method = $arr[$prefix . 'input_method'];
    if (isset($arr[$prefix . 'about_me']))
      $this->about_me = $arr[$prefix . 'about_me'];
    if (isset($arr[$prefix . 'name_color_start']))
      $this->name_color_start = $arr[$prefix . 'name_color_start'];
    if (isset($arr[$prefix . 'name_color_end']))
      $this->name_color_end = $arr[$prefix . 'name_color_end'];
    if (isset($arr[$prefix . 'country']))
      $this->country = $arr[$prefix . 'country'];
    if (isset($arr[$prefix . 'stream_url']))
      $this->stream_url = $arr[$prefix . 'stream_url'];

    if (isset($arr[$prefix . 'last_player_rename']))
      $this->last_player_rename = new JsonDateTime($arr[$prefix . 'last_player_rename']);
  }
  protected function do_expand_foreign_keys($DB, $depth, $expand_structure)
  {
    if ($this->player_id !== null) {
      $this->player = Player::get_by_id($DB, $this->player_id, $depth - 1);
      $this->player->expand_foreign_keys($DB, $depth - 1, false);
    } else {
      $this->player = null;
    }

    if ($this->claimed_player_id !== null) {
      $this->claimed_player = Player::get_by_id($DB, $this->claimed_player_id, $depth - 1);
      $this->claimed_player->expand_foreign_keys($DB, $depth - 1, false);
    } else {
      $this->claimed_player = null;
    }
  }

  protected function get_expand_list($level, $expand_structure)
  {
    $arr = [];
    if ($level > 1) {
      if ($this->player_id !== null) {
        DbObject::merge_expand_lists($arr, $this->player->get_expand_list($level - 1, false));
      }
      if ($this->claimed_player_id !== null) {
        DbObject::merge_expand_lists($arr, $this->claimed_player->get_expand_list($level - 1, false));
      }
      return $arr;
    }

    if ($this->player_id !== null) {
      DbObject::add_to_expand_list($arr, Player::class, $this->player_id);
    }
    if ($this->claimed_player_id !== null) {
      DbObject::add_to_expand_list($arr, Player::class, $this->claimed_player_id);
    }
    return $arr;
  }

  protected function apply_expand_data($data, $level, $expand_structure)
  {
    if ($level > 1) {
      if ($this->player_id !== null) {
        $this->player->apply_expand_data($data, $level - 1, false);
      }
      if ($this->claimed_player_id !== null) {
        $this->claimed_player->apply_expand_data($data, $level - 1, false);
      }
      return;
    }

    if ($this->player_id !== null) {
      $this->player = new Player();
      $this->player->apply_db_data(DbObject::get_object_from_data_list($data, Player::class, $this->player_id));
    }
    if ($this->claimed_player_id !== null) {
      $this->claimed_player = new Player();
      $this->claimed_player->apply_db_data(DbObject::get_object_from_data_list($data, Player::class, $this->claimed_player_id));
    }
  }

  function get_field_set()
  {
    return array(
      'player_id' => $this->player_id,
      'claimed_player_id' => $this->claimed_player_id,
      'email' => $this->email,
      'password' => $this->password,
      'discord_id' => $this->discord_id,
      'suspension_reason' => $this->suspension_reason,
      'date_suspended' => $this->date_suspended,
      'date_appeal' => $this->date_appeal,
      'email_verified' => $this->email_verified,
      'email_verify_code' => $this->email_verify_code,
      'role' => $this->role,
      'is_suspended' => $this->is_suspended,
      'links' => $this->links !== null && count($this->links) > 0 ? implode("\t", $this->links) : null,
      'input_method' => $this->input_method,
      'about_me' => $this->about_me,
      'name_color_start' => $this->name_color_start,
      'name_color_end' => $this->name_color_end,
      'country' => $this->country,
      'stream_url' => $this->stream_url,
      'last_player_rename' => $this->last_player_rename,
      'notifications' => $this->notifications,
      'restrictions' => $this->restrictions,
    );
  }
  static function static_field_set()
  {
    return [
      'player_id',
      'claimed_player_id',
      'email',
      'password',
      'discord_id',
      'suspension_reason',
      'date_suspended',
      'date_appeal',
      'email_verified',
      'email_verify_code',
      'role',
      'is_suspended',
      'links',
      'input_method',
      'about_me',
      'name_color_start',
      'name_color_end',
      'country',
      'stream_url',
      'last_player_rename',
      'notifications',
      'restrictions',
    ];
  }
  #endregion

  #region Find Functions
  static function find_by_discord_id($DB, string $discord_id)
  {
    return find_in_db($DB, 'Account', "discord_id = $1", array($discord_id), new Account());
  }

  static function find_by_email($DB, string $email)
  {
    return find_in_db($DB, 'Account', "email = $1", array($email), new Account());
  }

  static function find_by_session_token($DB, string $session_token)
  {
    $sessions = Session::find_by_token($DB, $session_token);
    if ($sessions === false || count($sessions) === 0 || count($sessions) > 1) {
      return false;
    }

    $session = $sessions[0];
    $session->expand_foreign_keys($DB);
    return $session->account;
  }

  static function find_by_api_key($DB, string $key)
  {
    $api_key = ApiKey::find_by_key($DB, $key);
    if ($api_key === false) {
      return false;
    }
    $account = Account::get_by_id($DB, $api_key->account_id, 1);
    if ($account === false) {
      return false;
    }
    $account->using_api_key = true;
    $account->active_api_key = $api_key;
    return $account;
  }

  static function find_by_email_verify_code($DB, string $email_verify_code)
  {
    return find_in_db($DB, 'Account', "email_verify_code = $1", array($email_verify_code), new Account());
  }

  static function find_by_claimed_player_id($DB, int $player_id)
  {
    return find_in_db($DB, 'Account', "claimed_player_id = $1", array($player_id), new Account());
  }

  static function find_by_player_id($DB, int $player_id)
  {
    return find_in_db($DB, 'Account', "player_id = $1", array($player_id), new Account());
  }

  static function get_all_player_claims($DB)
  {
    return find_in_db($DB, 'Account', "claimed_player_id IS NOT NULL", array(), new Account());
  }
  #endregion

  #region Utility Functions
  function __toString()
  {
    $player_name = $this->player !== null ? "'{$this->player->name}'" : "<No Player>";
    return "(Account, id: {$this->id}, name: {$player_name})";
  }

  function remove_sensitive_info($remove_email = true)
  {
    $this->password = null;
    $this->email_verify_code = null;
    $this->using_api_key = false;
    $this->active_api_key = null;

    if ($remove_email) {
      $this->email = null;
    }
  }

  static function is_valid_country(?string $country): bool
  {
    global $COUNTRY_CODES;
    if ($country === null) {
      return true;
    }
    return array_key_exists($country, $COUNTRY_CODES);
  }

  function has_notification_flag(int $flag): bool
  {
    return ($this->notifications & $flag) === $flag;
  }

  function has_restriction_flag(int $flag): bool
  {
    return ($this->restrictions & $flag) === $flag;
  }

  // Suspension data sent to the user on a failed login, the frontend builds the (translated) message from it
  function get_suspension_info(): array
  {
    return [
      'reason' => $this->suspension_reason,
      'date_appeal' => $this->date_appeal,
    ];
  }

  /**
   * SQL condition that is true if the player in $player_id_column does not belong to a suspended account.
   * Backed by the partial index account_suspended_player_idx, so the subquery only scans suspended accounts.
   */
  static function sql_not_suspended(string $player_id_column): string
  {
    return "NOT EXISTS (SELECT 1 FROM account suspended_account WHERE suspended_account.player_id = {$player_id_column} AND suspended_account.is_suspended = true)";
  }
  #endregion
}
