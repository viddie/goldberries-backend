<?php

class Like extends DbObject
{
  public static string $table_name = 'like';
  public static array $valid_states = ['current', 'on_hold', 'soon', 'backlog'];

  public int $challenge_id;
  public int $player_id;
  public ?JsonDateTime $date_created = null;
  public bool $is_wishlist = false;
  public ?string $state = null;
  public ?int $progress = null;
  public ?string $comment = null;
  public ?JsonDateTime $date_updated = null;

  // Linked Objects
  public ?Challenge $challenge = null;
  public ?Player $player = null;



  #region Abstract Functions
  function get_field_set()
  {
    return array(
      'challenge_id' => $this->challenge_id,
      'player_id' => $this->player_id,
      'date_created' => $this->date_created,
      'is_wishlist' => $this->is_wishlist,
      'state' => $this->state,
      'progress' => $this->progress,
      'comment' => $this->comment,
      'date_updated' => $this->date_updated,
    );
  }

  static function static_field_set()
  {
    return [
      'challenge_id',
      'player_id',
      'date_created',
      'is_wishlist',
      'state',
      'progress',
      'comment',
      'date_updated',
    ];
  }

  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->challenge_id = intval($arr[$prefix . 'challenge_id']);
    $this->player_id = intval($arr[$prefix . 'player_id']);
    $this->is_wishlist = $arr[$prefix . 'is_wishlist'] === 't';
    $this->date_created = new JsonDateTime($arr[$prefix . 'date_created']);

    if (isset($arr[$prefix . 'state']))
      $this->state = $arr[$prefix . 'state'];
    if (isset($arr[$prefix . 'progress']))
      $this->progress = intval($arr[$prefix . 'progress']);
    if (isset($arr[$prefix . 'comment']))
      $this->comment = $arr[$prefix . 'comment'];
    if (isset($arr[$prefix . 'date_updated']))
      $this->date_updated = new JsonDateTime($arr[$prefix . 'date_updated']);
  }

  protected function do_expand_foreign_keys($DB, $depth, $expand_structure)
  {
    if ($expand_structure && isset($this->challenge_id)) {
      $this->challenge = Challenge::get_by_id($DB, $this->challenge_id, $depth - 1);
    }
    if (isset($this->player_id)) {
      $this->player = Player::get_by_id($DB, $this->player_id, $depth, false);
    }
  }
  #endregion

  #region Expand Batching
  protected function get_expand_list($level, $expand_structure)
  {
    $arr = [];
    if ($level > 1) {
      if ($expand_structure && $this->challenge_id !== null && $this->challenge !== null) {
        DbObject::merge_expand_lists($arr, $this->challenge->get_expand_list($level - 1, $expand_structure));
      }
      if ($this->player_id !== null && $this->player !== null) {
        DbObject::merge_expand_lists($arr, $this->player->get_expand_list($level - 1, false));
      }
      return $arr;
    }

    if ($expand_structure && $this->challenge_id !== null) {
      DbObject::add_to_expand_list($arr, Challenge::class, $this->challenge_id);
    }
    if ($this->player_id !== null) {
      DbObject::add_to_expand_list($arr, Player::class, $this->player_id);
    }
    return $arr;
  }

  protected function apply_expand_data($data, $level, $expand_structure)
  {
    if ($level > 1) {
      if ($expand_structure && $this->challenge_id !== null && $this->challenge !== null) {
        $this->challenge->apply_expand_data($data, $level - 1, $expand_structure);
      }
      if ($this->player_id !== null && $this->player !== null) {
        $this->player->apply_expand_data($data, $level - 1, false);
      }
      return;
    }

    if ($expand_structure && $this->challenge_id !== null) {
      $this->challenge = new Challenge();
      $this->challenge->apply_db_data(DbObject::get_object_from_data_list($data, Challenge::class, $this->challenge_id));
    }
    if ($this->player_id !== null) {
      $this->player = new Player();
      $this->player->apply_db_data(DbObject::get_object_from_data_list($data, Player::class, $this->player_id));
    }
  }
  #endregion

  #region Find Functions

  /**
   * Get all likes for a specific challenge
   * @param resource $DB Database connection
   * @param int $challenge_id The challenge ID to get likes for
   * @return array Array of Like objects
   */
  static function getLikes($DB, int $challenge_id): array
  {
    $query = "SELECT * FROM \"like\" WHERE challenge_id = $1 ORDER BY date_created DESC";
    $result = pg_query_params_or_die($DB, $query, [$challenge_id], "Failed to get likes for challenge {$challenge_id}");

    $likes = [];
    while ($row = pg_fetch_assoc($result)) {
      $like = new Like();
      $like->apply_db_data($row);
      $like->expand_foreign_keys($DB, 2, false);
      $likes[] = $like;
    }

    return $likes;
  }

  /**
   * Find a like by challenge_id and player_id
   * @param resource $DB Database connection
   * @param int $challenge_id The challenge ID
   * @param int $player_id The player ID
   * @return Like|false The Like object or false if not found
   */
  static function findByChallengeAndPlayer($DB, int $challenge_id, int $player_id)
  {
    $query = "SELECT * FROM \"like\" WHERE challenge_id = $1 AND player_id = $2";
    $result = pg_query_params_or_die($DB, $query, [$challenge_id, $player_id], "Failed to find like for challenge {$challenge_id} and player {$player_id}");

    if (pg_num_rows($result) === 0) {
      return false;
    }

    $like = new Like();
    $like->apply_db_data(pg_fetch_assoc($result));
    return $like;
  }

  /**
   * Recalculate and update the likes count for a challenge
   * @param resource $DB Database connection
   * @param int $challenge_id The challenge ID to recalculate likes for
   * @return bool True on success, false on failure
   */
  static function recalculateChallengeLikes($DB, int $challenge_id): bool
  {
    // Count all likes for this challenge (excluding wishlists)
    $query = "SELECT COUNT(*) as count FROM \"like\" WHERE challenge_id = $1 AND is_wishlist = false";
    $result = pg_query_params_or_die($DB, $query, [$challenge_id], "Failed to count likes for challenge {$challenge_id}");

    $row = pg_fetch_assoc($result);
    $count = intval($row['count']);

    // Update the challenge's likes count
    $update_query = "UPDATE challenge SET likes = $1 WHERE id = $2";
    pg_query_params_or_die($DB, $update_query, [$count, $challenge_id], "Failed to update likes count for challenge {$challenge_id}");

    return true;
  }

  /**
   * Get all likes for a specific player, sorted by date_updated (or date_created if date_updated is null).
   * Returns an associative array with 'likes' (list of Like objects) and 'challenges' (challenge_id => Challenge).
   * @param resource $DB Database connection
   * @param int $player_id The player ID to get likes for
   * @return array { likes: Like[], challenges: array<int, Challenge> }
   */
  static function getPlayerLikes($DB, int $player_id): array
  {
    // Step 1: Fetch all like objects for the player
    $query = "SELECT * FROM \"like\" WHERE player_id = $1 ORDER BY COALESCE(date_updated, date_created) DESC";
    $result = pg_query_params_or_die($DB, $query, [$player_id], "Failed to get likes for player {$player_id}");

    $likes = [];
    $challenge_ids = [];
    while ($row = pg_fetch_assoc($result)) {
      $like = new Like();
      $like->apply_db_data($row);
      $likes[] = $like;
      $challenge_ids[] = $like->challenge_id;
    }

    // Step 2: Batch-fetch all challenges from view_challenges
    $challenges = [];
    if (count($challenge_ids) > 0) {
      $challenges = Challenge::fetch_challenges_assoc($DB, $challenge_ids);
    }

    // Step 3: insert them into the like objects
    foreach ($likes as $like) {
      if (isset($challenges[$like->challenge_id])) {
        $like->challenge = $challenges[$like->challenge_id];
      }
    }

    return $likes;
  }
  #endregion

  #region Utility Functions
  function __toString()
  {
    return "(Like, id:{$this->id}, challenge_id:{$this->challenge_id}, player_id:{$this->player_id})";
  }
  #endregion
}
