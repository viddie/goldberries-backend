<?php

class Like extends DbObject
{
  public static string $table_name = 'like';

  public int $challenge_id;
  public int $player_id;
  public ?JsonDateTime $date_created = null;
  public bool $is_wishlist = false;

  // Linked Objects
  public ?Challenge $challenge = null;
  public ?Player $player = null;



  // === Abstract Functions ===
  function get_field_set()
  {
    return array(
      'challenge_id' => $this->challenge_id,
      'player_id' => $this->player_id,
      'date_created' => $this->date_created,
      'is_wishlist' => $this->is_wishlist,
    );
  }

  static function static_field_set()
  {
    return [
      'challenge_id',
      'player_id',
      'date_created',
      'is_wishlist',
    ];
  }

  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->challenge_id = intval($arr[$prefix . 'challenge_id']);
    $this->player_id = intval($arr[$prefix . 'player_id']);
    $this->is_wishlist = $arr[$prefix . 'is_wishlist'] === 't';
    $this->date_created = new JsonDateTime($arr[$prefix . 'date_created']);
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

  // === Find Functions ===

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

  // === Utility Functions ===
  function __toString()
  {
    return "(Like, id:{$this->id}, challenge_id:{$this->challenge_id}, player_id:{$this->player_id})";
  }
}
