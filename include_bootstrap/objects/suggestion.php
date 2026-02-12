<?php

class Suggestion extends DbObject
{
  public static int $expiration_days = 7;
  public static int $placement_cooldown_days = 14;
  public static string $table_name = 'suggestion';

  public ?int $author_id = null;
  public ?int $challenge_id = null;
  public ?int $current_difficulty_id = null;
  public ?int $suggested_difficulty_id = null;
  public ?string $comment = null;
  public ?bool $is_verified = null;
  public JsonDateTime $date_created;
  public ?bool $is_accepted = null;
  public ?JsonDateTime $date_accepted = null;

  // Linked Objects
  public ?Player $author = null;
  public ?Challenge $challenge = null;
  public ?Difficulty $current_difficulty = null;
  public ?Difficulty $suggested_difficulty = null;

  // Associative Objects
  public ?array $votes = null; /* SuggestionVote[] */

  #region Abstract Functions
  function get_field_set()
  {
    return array(
      'author_id' => $this->author_id,
      'challenge_id' => $this->challenge_id,
      'current_difficulty_id' => $this->current_difficulty_id,
      'suggested_difficulty_id' => $this->suggested_difficulty_id,
      'comment' => $this->comment,
      'is_verified' => $this->is_verified,
      'date_created' => $this->date_created,
      'is_accepted' => $this->is_accepted,
      'date_accepted' => $this->date_accepted,
    );
  }
  static function static_field_set()
  {
    return [
      'author_id',
      'challenge_id',
      'current_difficulty_id',
      'suggested_difficulty_id',
      'comment',
      'is_verified',
      'date_created',
      'is_accepted',
      'date_accepted',
    ];
  }

  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->date_created = new JsonDateTime($arr[$prefix . 'date_created']);

    if (isset($arr[$prefix . 'author_id']))
      $this->author_id = intval($arr[$prefix . 'author_id']);
    if (isset($arr[$prefix . 'challenge_id']))
      $this->challenge_id = intval($arr[$prefix . 'challenge_id']);
    if (isset($arr[$prefix . 'current_difficulty_id']))
      $this->current_difficulty_id = intval($arr[$prefix . 'current_difficulty_id']);
    if (isset($arr[$prefix . 'suggested_difficulty_id']))
      $this->suggested_difficulty_id = intval($arr[$prefix . 'suggested_difficulty_id']);
    if (isset($arr[$prefix . 'comment']))
      $this->comment = $arr[$prefix . 'comment'];
    if (isset($arr[$prefix . 'is_verified']))
      $this->is_verified = $arr[$prefix . 'is_verified'] === 't';
    if (isset($arr[$prefix . 'is_accepted']))
      $this->is_accepted = $arr[$prefix . 'is_accepted'] === 't';
    if (isset($arr[$prefix . 'date_accepted']))
      $this->date_accepted = new JsonDateTime($arr[$prefix . 'date_accepted']);
  }

  protected function do_expand_foreign_keys($DB, $depth, $expand_structure)
  {
    if ($expand_structure) {
      if ($this->challenge_id !== null) {
        $this->challenge = Challenge::get_by_id($DB, $this->challenge_id, $depth - 1, $expand_structure);
      }
    }
    if ($this->author_id !== null) {
      $this->author = Player::get_by_id($DB, $this->author_id, 3, false);
    }
    if ($this->current_difficulty_id !== null) {
      $this->current_difficulty = Difficulty::get_by_id($DB, $this->current_difficulty_id);
    }
    if ($this->suggested_difficulty_id !== null) {
      $this->suggested_difficulty = Difficulty::get_by_id($DB, $this->suggested_difficulty_id);
    }
  }
  #endregion

  #region Expand Batching
  protected function get_expand_list($level, $expand_structure)
  {
    $arr = [];
    if ($level > 1) {
      if ($expand_structure && $this->challenge_id !== null) {
        DbObject::merge_expand_lists($arr, $this->challenge->get_expand_list($level - 1, $expand_structure));
      }
      if ($this->author_id !== null) {
        DbObject::merge_expand_lists($arr, $this->author->get_expand_list($level - 1, false));
      }
      if ($this->current_difficulty_id !== null) {
        DbObject::merge_expand_lists($arr, $this->current_difficulty->get_expand_list($level - 1, false));
      }
      if ($this->suggested_difficulty_id !== null) {
        DbObject::merge_expand_lists($arr, $this->suggested_difficulty->get_expand_list($level - 1, false));
      }
      return $arr;
    }

    if ($expand_structure && $this->challenge_id !== null) {
      DbObject::add_to_expand_list($arr, Challenge::class, $this->challenge_id);
    }
    if ($this->author_id !== null) {
      DbObject::add_to_expand_list($arr, Player::class, $this->author_id);
    }
    if ($this->current_difficulty_id !== null) {
      DbObject::add_to_expand_list($arr, Difficulty::class, $this->current_difficulty_id);
    }
    if ($this->suggested_difficulty_id !== null) {
      DbObject::add_to_expand_list($arr, Difficulty::class, $this->suggested_difficulty_id);
    }
    return $arr;
  }

  protected function apply_expand_data($data, $level, $expand_structure)
  {
    if ($level > 1) {
      if ($expand_structure && $this->challenge_id !== null) {
        $this->challenge->apply_expand_data($data, $level - 1, $expand_structure);
      }
      if ($this->author_id !== null) {
        $this->author->apply_expand_data($data, $level - 1, false);
      }
      if ($this->current_difficulty_id !== null) {
        $this->current_difficulty->apply_expand_data($data, $level - 1, false);
      }
      if ($this->suggested_difficulty_id !== null) {
        $this->suggested_difficulty->apply_expand_data($data, $level - 1, false);
      }
      return;
    }

    if ($expand_structure && $this->challenge_id !== null) {
      $this->challenge = new Challenge();
      $this->challenge->apply_db_data(DbObject::get_object_from_data_list($data, Challenge::class, $this->challenge_id));
    }
    if ($this->author_id !== null) {
      $this->author = new Player();
      $this->author->apply_db_data(DbObject::get_object_from_data_list($data, Player::class, $this->author_id));
    }
    if ($this->current_difficulty_id !== null) {
      $this->current_difficulty = new Difficulty();
      $this->current_difficulty->apply_db_data(DbObject::get_object_from_data_list($data, Difficulty::class, $this->current_difficulty_id));
    }
    if ($this->suggested_difficulty_id !== null) {
      $this->suggested_difficulty = new Difficulty();
      $this->suggested_difficulty->apply_db_data(DbObject::get_object_from_data_list($data, Difficulty::class, $this->suggested_difficulty_id));
    }
  }

  #endregion

  #region Find Functions
  function fetch_votes($DB): bool
  {
    $query = "SELECT * FROM view_suggestion_votes WHERE suggestion_vote_suggestion_id = $1";
    $result = pg_query_params($DB, $query, [$this->id]);

    $this->votes = [];
    if (!$result) {
      return false;
    }

    while ($row = pg_fetch_assoc($result)) {
      $vote = new SuggestionVote();
      $vote->apply_db_data($row, "suggestion_vote_");
      $vote->expand_foreign_keys($row, 2, false);
      $vote->find_submission($row, $this);
      $this->votes[] = $vote;
    }

    return true;
  }

  static function get_paginated($DB, $page, $per_page, $challenge = null, $expired = null, $account = null, $type = "all", $search = null)
  {
    $needs_join = $search !== null;

    if ($needs_join) {
      $query = "SELECT DISTINCT suggestion.* FROM suggestion
        LEFT JOIN challenge ON suggestion.challenge_id = challenge.id
        LEFT JOIN map ON challenge.map_id = map.id
        LEFT JOIN campaign ON COALESCE(challenge.campaign_id, map.campaign_id) = campaign.id
        LEFT JOIN player AS author ON suggestion.author_id = author.id";
    } else {
      $query = "SELECT * FROM suggestion";
    }

    $where = array();
    if ($search !== null) {
      $search = pg_escape_string($DB, $search);
      $where[] = "(campaign.name ILIKE '%" . $search . "%' OR map.name ILIKE '%" . $search . "%' OR author.name ILIKE '%" . $search . "%' OR suggestion.comment ILIKE '%" . $search . "%')";
    }
    if ($challenge !== null) {
      $where[] = "suggestion.challenge_id = " . $challenge;
    }
    if ($expired === true) {
      $where[] = "(suggestion.is_accepted IS NOT NULL OR suggestion.is_verified = false)";
    } else if ($expired === false) {
      $where[] = "suggestion.date_created >= NOW() - INTERVAL '" . self::$expiration_days . " days'";
      $where[] = "suggestion.is_accepted IS NULL";
      $where[] = "(suggestion.is_verified = true OR suggestion.is_verified IS NULL)";
    } else {
      //expired === null -> get expired but undecided suggestions
      $where[] = "suggestion.date_created < NOW() - INTERVAL '" . self::$expiration_days . " days'";
      $where[] = "suggestion.is_accepted IS NULL";
      $where[] = "(suggestion.is_verified = true OR suggestion.is_verified IS NULL)";
    }

    if ($account === null || (!is_helper($account) && $account->player_id === null)) {
      $where[] = "suggestion.is_verified = true";
    } else {
      if (is_helper($account)) {
        //$where[] = "is_verified IS NOT NULL";
      } else {
        $where[] = "(suggestion.is_verified = true OR suggestion.author_id = " . $account->player_id . ")";
      }
    }

    $player_id = $account !== null ? $account->player_id : null;

    if ($type === "general") {
      $where[] = "suggestion.challenge_id IS NULL";
    } else if ($type === "challenge") {
      $where[] = "suggestion.challenge_id IS NOT NULL";
    } else if ($type === "challenge_own" && $player_id !== null) {
      $where[] = "suggestion.challenge_id IS NOT NULL";
      if ($needs_join) {
        $query = "SELECT DISTINCT
            suggestion.*,
            BOOL_OR(sub_player.id = $player_id) AS has_player
          FROM suggestion
          LEFT JOIN challenge ON suggestion.challenge_id = challenge.id
          LEFT JOIN map ON challenge.map_id = map.id
          LEFT JOIN campaign ON COALESCE(challenge.campaign_id, map.campaign_id) = campaign.id
          LEFT JOIN player AS author ON suggestion.author_id = author.id
          JOIN submission ON challenge.id = submission.challenge_id
          JOIN player AS sub_player ON submission.player_id = sub_player.id";
      } else {
        $query = "SELECT 
            suggestion.*,
            BOOL_OR(player.id = $player_id) AS has_player
          FROM suggestion
          JOIN challenge ON suggestion.challenge_id = challenge.id
          JOIN submission ON challenge.id = submission.challenge_id
          JOIN player ON submission.player_id = player.id";
      }
    }

    if (count($where) > 0) {
      $query .= " WHERE " . implode(" AND ", $where);
    }

    if ($type === "challenge_own" && $player_id !== null) {
      if ($needs_join) {
        $query .= " GROUP BY suggestion.id HAVING BOOL_OR(sub_player.id = $player_id)";
      } else {
        $query .= " GROUP BY suggestion.id HAVING BOOL_OR(player.id = $player_id)";
      }
    }

    $query .= " ORDER BY suggestion.date_accepted DESC, suggestion.date_created DESC";

    $query = "
    WITH query AS (
      " . $query . "
    )
    SELECT *, count(*) OVER () AS total_count
    FROM query";

    if ($per_page !== -1) {
      $query .= " LIMIT " . $per_page . " OFFSET " . ($page - 1) * $per_page;
    }

    profiler_step("Fetching suggestions from database...");
    $result = pg_query_params_or_die($DB, $query, []);
    profiler_step("Fetched suggestions from database!");

    $maxCount = 0;
    $suggestions = [];
    while ($row = pg_fetch_assoc($result)) {
      if ($maxCount === 0) {
        $maxCount = intval($row['total_count']);
      }

      $suggestion = new Suggestion();
      $suggestion->apply_db_data($row);
      // $suggestion->expand_foreign_keys($DB, 5, true);
      // $suggestion->fetch_associated_content($DB);
      $suggestions[] = $suggestion;
    }

    DbObject::fetch_data_for_objects($DB, $suggestions, 5, true);
    profiler_step("Fetched FK data.");
    self::fetch_associated_contents($DB, $suggestions);
    profiler_step("Done fetching associated content for suggestions.");

    return array(
      'suggestions' => $suggestions,
      'max_count' => $maxCount,
      'max_page' => ceil($maxCount / $per_page),
      'page' => $page,
      'per_page' => $per_page,
    );
  }

  static function get_last_placement_suggestion($DB, $challenge_id, $placement = true)
  {
    $query = "SELECT * FROM suggestion WHERE is_verified = TRUE AND challenge_id = " . $challenge_id;
    if ($placement === true) {
      $query .= " AND suggested_difficulty_id IS NOT NULL";
    }
    $query .= " ORDER BY date_created DESC LIMIT 1";

    $result = pg_query($DB, $query);
    if (!$result) {
      die_json(500, "Failed to query database");
    }

    $suggestion = new Suggestion();
    if ($row = pg_fetch_assoc($result)) {
      $suggestion->apply_db_data($row);
      $suggestion->expand_foreign_keys($DB, 5, true);
      $suggestion->fetch_associated_content($DB);
    } else {
      $suggestion = null;
    }

    return $suggestion;
  }

  static function had_recent_placement_suggestion($DB, $challenge_id)
  {
    $suggestion = self::get_last_placement_suggestion($DB, $challenge_id);
    if ($suggestion === null) {
      return false;
    }

    $now = new DateTime();
    $diff = $now->diff($suggestion->date_created);
    return $diff->days < self::$placement_cooldown_days;
  }
  #endregion

  #region Utility Functions
  function __toString()
  {
    $dateStr = date_to_long_string($this->date_created);

    $authorStr = $this->author_id !== null ? $this->author_id : "<unknown>";
    $challengeStr = $this->challenge_id !== null ? ", challenge_id:{$this->challenge_id}" : "";

    return "(Suggestion, id:{$this->id}{$challengeStr}, author:{$authorStr}, date:{$dateStr})";
  }

  //This function assumes a fully expanded structure
  function fetch_associated_content($DB)
  {
    if ($this->challenge_id !== null) {
      $this->challenge->fetch_submissions($DB, true);
      profiler_step("Fetched submissions", 1);
      if ($this->challenge->map_id !== null) {
        $this->challenge->map->fetch_challenges($DB, true, false, true);
        profiler_step("Fetched challenges", 1);
        //Remove the $this->challenge from the map's challenges
        $this->challenge->map->challenges = array_values(array_filter($this->challenge->map->challenges, function ($c) {
          return $c->id !== $this->challenge->id;
        }));
      } else if ($this->challenge->campaign_id !== null) {
        $this->challenge->campaign->fetch_challenges($DB, true, false, true);
        profiler_step("Fetched campaign challenges", 1);
        //Remove the $this->challenge from the campaign's challenges.
        //Also remove challenges that dont have the same label. Thats how i'll just define "related challenges" for fgrs
        $this->challenge->campaign->challenges = array_values(array_filter($this->challenge->campaign->challenges, function ($c) {
          return $c->id !== $this->challenge->id && $c->label === $this->challenge->label;
        }));
      }
    }
    profiler_step("Fetching votes", 1);
    $this->fetch_votes($DB);
  }

  /**
   * Batch version of fetch_associated_content for multiple suggestions.
   * Fetches all submissions, related challenges, and votes in batched queries instead of per-suggestion.
   * Assumes all suggestions have been fully expanded (FKs resolved via fetch_data_for_objects).
   * @param resource $DB
   * @param Suggestion[] $suggestions
   */
  static function fetch_associated_contents($DB, array $suggestions)
  {
    // Step 1: Batch-fetch submissions for all suggestion challenges
    // Multiple suggestions may reference the same challenge ID but have separate PHP objects,
    // so we track all of them and distribute results after fetching
    $challenges_by_id = []; // challenge_id => Challenge[] (all distinct PHP objects)
    foreach ($suggestions as $suggestion) {
      if ($suggestion->challenge_id !== null && $suggestion->challenge !== null) {
        $challenges_by_id[$suggestion->challenge->id][] = $suggestion->challenge;
      }
    }
    // Use one representative challenge per ID for the batch call
    $representative_challenges = [];
    foreach ($challenges_by_id as $challenge_id => $challenge_objects) {
      $representative_challenges[] = $challenge_objects[0];
    }
    if (count($representative_challenges) > 0) {
      Challenge::batch_fetch_submissions($DB, $representative_challenges, true);
      profiler_step("Batch fetched submissions for suggestion challenges");

      // Distribute submissions to all challenge objects sharing the same ID
      foreach ($challenges_by_id as $challenge_id => $challenge_objects) {
        $submissions = $challenge_objects[0]->submissions;
        for ($i = 1; $i < count($challenge_objects); $i++) {
          $challenge_objects[$i]->submissions = $submissions;
        }
      }
    }

    // Step 2: Collect ALL map and campaign objects that need their challenges fetched, grouped by ID
    // Multiple suggestions may reference different PHP objects for the same map/campaign, so we track all of them
    $maps_by_id = [];       // map_id => Map[] (all distinct PHP objects)
    $campaigns_by_id = [];  // campaign_id => Campaign[]
    foreach ($suggestions as $suggestion) {
      if ($suggestion->challenge_id === null || $suggestion->challenge === null)
        continue;

      $challenge = $suggestion->challenge;
      if ($challenge->map_id !== null && $challenge->map !== null) {
        $maps_by_id[$challenge->map->id][] = $challenge->map;
      } else if ($challenge->campaign_id !== null && $challenge->campaign !== null) {
        $campaigns_by_id[$challenge->campaign->id][] = $challenge->campaign;
      }
    }

    // Step 2a: Batch-fetch challenges for maps (include_arbitrary=false, hide_rejected=true)
    // Use one representative map per ID for the batch call, then distribute to all duplicates
    $representative_maps = [];
    foreach ($maps_by_id as $map_id => $map_objects) {
      $representative_maps[] = $map_objects[0];
    }
    if (count($representative_maps) > 0) {
      Map::batch_fetch_challenges($DB, $representative_maps, false, true);
      profiler_step("Batch fetched challenges for maps");

      // Distribute challenges to all map objects sharing the same ID
      foreach ($maps_by_id as $map_id => $map_objects) {
        $challenges = $map_objects[0]->challenges;
        for ($i = 1; $i < count($map_objects); $i++) {
          $map_objects[$i]->challenges = $challenges;
        }
      }
    }

    // Step 2b: Batch-fetch challenges for campaigns (include_arbitrary=false, hide_rejected=true)
    $representative_campaigns = [];
    foreach ($campaigns_by_id as $campaign_id => $campaign_objects) {
      $representative_campaigns[] = $campaign_objects[0];
    }
    if (count($representative_campaigns) > 0) {
      Campaign::batch_fetch_challenges($DB, $representative_campaigns, false, true);
      profiler_step("Batch fetched challenges for campaigns");

      // Distribute challenges to all campaign objects sharing the same ID
      foreach ($campaigns_by_id as $campaign_id => $campaign_objects) {
        $challenges = $campaign_objects[0]->challenges;
        for ($i = 1; $i < count($campaign_objects); $i++) {
          $campaign_objects[$i]->challenges = $challenges;
        }
      }
    }

    // Step 3: Batch-fetch submissions for all the related challenges (from maps and campaigns)
    $related_challenges = [];
    foreach ($representative_maps as $map) {
      if ($map->challenges !== null) {
        foreach ($map->challenges as $challenge) {
          $related_challenges[$challenge->id] = $challenge;
        }
      }
    }
    foreach ($representative_campaigns as $campaign) {
      if ($campaign->challenges !== null) {
        foreach ($campaign->challenges as $challenge) {
          $related_challenges[$challenge->id] = $challenge;
        }
      }
    }
    if (count($related_challenges) > 0) {
      Challenge::batch_fetch_submissions($DB, array_values($related_challenges), true);
      profiler_step("Batch fetched submissions for related challenges");
    }

    // Step 4: Apply per-suggestion filtering (remove self, label matching for campaigns)
    // Since challenges arrays are shared across map/campaign objects with the same ID,
    // we need to create per-suggestion filtered copies
    foreach ($suggestions as $suggestion) {
      if ($suggestion->challenge_id === null || $suggestion->challenge === null)
        continue;

      $challenge = $suggestion->challenge;
      if ($challenge->map_id !== null && $challenge->map !== null && $challenge->map->challenges !== null) {
        // Create a filtered copy for this suggestion's map
        $challenge->map->challenges = array_values(array_filter($challenge->map->challenges, function ($c) use ($challenge) {
          return $c->id !== $challenge->id;
        }));
      } else if ($challenge->campaign_id !== null && $challenge->campaign !== null && $challenge->campaign->challenges !== null) {
        // Create a filtered copy: remove own challenge and filter by matching label
        $challenge->campaign->challenges = array_values(array_filter($challenge->campaign->challenges, function ($c) use ($challenge) {
          return $c->id !== $challenge->id && $c->label === $challenge->label;
        }));
      }
    }

    // Step 5: Batch-fetch votes for all suggestions
    self::batch_fetch_votes($DB, $suggestions);
    profiler_step("Batch fetched votes for suggestions");
  }

  /**
   * Batch-fetches votes for multiple suggestions from view_suggestion_votes in a single query.
   * @param resource $DB
   * @param Suggestion[] $suggestions
   */
  static function batch_fetch_votes($DB, array $suggestions)
  {
    $suggestion_ids = [];
    $suggestion_map = []; // id => Suggestion
    foreach ($suggestions as $suggestion) {
      $suggestion_ids[] = $suggestion->id;
      $suggestion_map[$suggestion->id] = $suggestion;
      $suggestion->votes = [];
    }

    if (count($suggestion_ids) === 0)
      return;

    $id_list = "{" . implode(",", $suggestion_ids) . "}";
    $query = "SELECT * FROM view_suggestion_votes WHERE suggestion_vote_suggestion_id = ANY ($1)";
    $result = pg_query_params_or_die($DB, $query, [$id_list]);

    while ($row = pg_fetch_assoc($result)) {
      $suggestion_id = intval($row['suggestion_vote_suggestion_id']);
      $suggestion = $suggestion_map[$suggestion_id] ?? null;
      if ($suggestion === null)
        continue;

      $vote = new SuggestionVote();
      $vote->apply_db_data($row, "suggestion_vote_");
      $vote->expand_foreign_keys($row, 2, false);
      $vote->find_submission($row, $suggestion);
      $suggestion->votes[] = $vote;
    }
  }

  function is_closed()
  {
    return $this->is_accepted !== null || $this->has_expired() || $this->is_verified === false;
  }

  function has_expired()
  {
    $now = new DateTime();
    $diff = $now->diff($this->date_created);
    return $diff->days > self::$expiration_days;
  }

  function get_url()
  {
    return constant("BASE_URL") . "/suggestions/" . $this->id;
  }
  #endregion
}