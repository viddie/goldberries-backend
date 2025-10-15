<?php

class Badge extends DbObject
{
  public static string $table_name = 'badge';

  public static int $BADGE_SHINY = 1;
  public static int $BADGE_GLOW = 2;
  public static int $BADGE_LEVEL_1 = 4;
  public static int $BADGE_LEVEL_2 = 8;
  public static int $BADGE_LEVEL_3 = 16;

  // Tier's sort value minus 1 = index in this array for the badge ID
  public static array $TIER_BADGES = [
    6, //t1
    7, //t2
    8, //t3
    9, //t4
    10, //t5
    11, //t6
    12, //t7
    13, //t8
    14, //t9
    15, //t10
    16, //t11
    17, //t12
    18, //t13
    19, //t14
    37, //t15
    20, //t16
    21, //t17
    22, //t18
    23, //t19
    24 //t20
  ];

  public string $icon_url;
  public string $title;
  public string $description;
  public string $color = "#ffffff";
  public JsonDateTime $date_created;
  public int $flags = 0; // flags for special badges, e.g. shiny badge

  // Associative Objects
  public ?array $data = []; // arbitrary data like badge_player.date_awarded field when fetching 1 specific players' badges

  // === Abstract Functions ===
  function get_field_set()
  {
    return array(
      'icon_url' => $this->icon_url,
      'title' => $this->title,
      'description' => $this->description,
      'color' => $this->color,
      'date_created' => $this->date_created,
      'flags' => $this->flags,
    );
  }

  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->date_created = new JsonDateTime($arr[$prefix . 'date_created']);
    $this->icon_url = $arr[$prefix . 'icon_url'] ?? '';
    $this->title = $arr[$prefix . 'title'] ?? '';
    $this->description = $arr[$prefix . 'description'] ?? '';
    $this->color = $arr[$prefix . 'color'];
    $this->flags = intval($arr[$prefix . 'flags']);
  }

  function do_expand_foreign_keys($DB, $depth = 2, $expand_structure = true)
  {
    if ($depth <= 1)
      return;
  }

  // === Find Functions ===
  static function get_all_for_player($DB, $player_id)
  {
    $query = "SELECT 
        badge.*,
        badge_player.date_awarded AS date_awarded
      FROM badge_player JOIN badge ON badge_player.badge_id = badge.id
      WHERE badge_player.player_id = $1";
    $result = pg_query_params_or_die($DB, $query, [$player_id]);

    $badges = [];
    while ($row = pg_fetch_assoc($result)) {
      $badge = new Badge();
      $badge->apply_db_data($row);
      $badge->data['date_awarded'] = new JsonDateTime($row['date_awarded']);
      $badges[] = $badge;
    }
    return $badges;
  }

  static function add_players_tier_badge($DB, $player_id, $sort)
  {
    if ($sort < 1 || $sort > count(self::$TIER_BADGES)) {
      log_error("Invalid tier sort value: $sort", "Submission");
      return false;
    }

    $current = Badge::get_players_tier_badge($DB, $player_id);
    if ($current !== null) {
      $current_sort = array_search($current->id, self::$TIER_BADGES) + 1;
      if ($current_sort >= $sort) {
        // Player already has this or a better badge
        return false;
      } else {
        // Player has a lower badge, delete it
        $badge_player = new BadgePlayer();
        $badge_player->id = $current->data['badge_player_id'];
        if ($badge_player->delete($DB)) {
          log_info("Deleted lower tier badge " . $current->id . " for player_id: $player_id", "Submission");
        } else {
          log_error("Failed to delete lower tier badge " . $current->id . " for player_id: $player_id", "Submission");
          return false;
        }
      }
    }

    // Add the new badge
    $new_badge_id = self::$TIER_BADGES[$sort - 1];
    $badge_player = new BadgePlayer();
    $badge_player->badge_id = $new_badge_id;
    $badge_player->player_id = $player_id;
    $badge_player->date_awarded = new JsonDateTime();
    if ($badge_player->insert($DB)) {
      log_info("Awarded highest tier badge $new_badge_id to player_id: $player_id", "Submission");
      return true;
    } else {
      log_error("Failed to award highest tier badge $new_badge_id to player_id: $player_id", "Submission");
      return false;
    }
  }

  static function get_players_tier_badge($DB, $player_id)
  {
    //Find the tier badge of the player. Every player can only have one tier badge.
    $badge_ids = implode(",", self::$TIER_BADGES);
    $query = "SELECT
      badge.*,
      badge_player.id AS badge_player_id,
      badge_player.date_awarded AS date_awarded
      FROM badge
        JOIN badge_player ON badge.id = badge_player.badge_id
      WHERE badge_player.player_id = $1 AND badge.id IN ($badge_ids)";

    $result = pg_query_params_or_die($DB, $query, [$player_id]);

    $badges = [];
    while ($row = pg_fetch_assoc($result)) {
      $badge = new Badge();
      $badge->apply_db_data($row);
      $badge->data['badge_player_id'] = intval($row['badge_player_id']);
      $badge->data['date_awarded'] = new JsonDateTime($row['date_awarded']);
      $badges[] = $badge;
    }

    // If there is more than 1 row, delete the lowest badges
    $count = count($badges);
    if ($count > 1) {
      // Sort badges by tier (highest tier first)
      usort($badges, function ($a, $b) {
        $sort_a = array_search($a->id, self::$TIER_BADGES);
        $sort_b = array_search($b->id, self::$TIER_BADGES);
        return $sort_b - $sort_a;
      });

      // Start at 1 to keep the highest
      for ($i = 1; $i < $count; $i++) {
        $badge_to_delete = $badges[$i];
        $badge_player = new BadgePlayer();
        $badge_player->id = $badge_to_delete->data['badge_player_id'];
        if ($badge_player->delete($DB)) {
          log_info("Deleted lower highest tier badge " . $badge->id . " for player_id: $player_id");
        } else {
          log_error("Failed to delete lower highest tier badge " . $badge->id . " for player_id: $player_id");
        }
      }
    }
    return $count === 0 ? null : $badges[0];
  }

  // Fetch the hardest submission of this player, remove the existing badge if available, add the definitely correct badge.
  static function check_players_tier_badge($DB, $player_id)
  {
    $submissions = Submission::get_hardest_for_player($DB, $player_id, 1);
    if (count($submissions) === 0) {
      return false;
    }

    $submission = $submissions[0];
    $hardest_sort = $submission->challenge->difficulty->sort;
    $current_badge = self::get_players_tier_badge($DB, $player_id);
    if ($current_badge !== null) {
      // Check if the current badge is different from the hardest submission
      $current_sort = array_search($current_badge->id, self::$TIER_BADGES) + 1;
      if ($current_sort === $hardest_sort) {
        // Player already has this or a better badge
        return false;
      }

      // Delete badge
      $badge_player = new BadgePlayer();
      $badge_player->id = $current_badge->data['badge_player_id'];
      if ($badge_player->delete($DB)) {
        log_info("Deleted current tier badge " . $current_badge->id . " for player_id: $player_id", "Submission");
      } else {
        log_error("Failed to delete current tier badge " . $current_badge->id . " for player_id: $player_id", "Submission");
        return false;
      }
    }
    // Add new badge
    self::add_players_tier_badge($DB, $player_id, $hardest_sort);
    return true;
  }

  // === Utility Functions ===
  function __toString()
  {
    return "(Badge, id:{$this->id}, title:{$this->title})";
  }

  function is_shiny()
  {
    return has_flag($this->flags, self::$BADGE_SHINY);
  }
}