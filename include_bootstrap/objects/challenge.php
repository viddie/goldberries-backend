<?php

class Challenge extends DbObject
{
  public static string $table_name = 'challenge';

  public ?string $label = null;
  public ?string $description = null;
  public ?JsonDateTime $date_created = null;
  public bool $requires_fc = false;
  public bool $has_fc = false;
  public ?bool $is_arbitrary = null;
  public ?int $sort = null;
  public ?string $icon_url = null;
  public bool $is_rejected = false;
  public ?string $reject_note = null;

  // Foreign Keys
  public ?int $campaign_id = null;
  public ?int $map_id = null;
  public int $objective_id;
  public int $difficulty_id;

  // Linked Objects
  public ?Campaign $campaign = null;
  public ?Map $map = null;
  public ?Objective $objective = null;
  public ?Difficulty $difficulty = null;

  // Associative Objects
  public ?array $submissions = null; /* Submission[] */

  // Other
  public $data = null; // Used for any arbitrary data, based on context



  // === Abstract Functions ===
  function get_field_set()
  {
    return array(
      'label' => $this->label,
      'description' => $this->description,
      'date_created' => $this->date_created,
      'requires_fc' => $this->requires_fc,
      'has_fc' => $this->has_fc,
      'is_arbitrary' => $this->is_arbitrary,
      'campaign_id' => $this->campaign_id,
      'map_id' => $this->map_id,
      'objective_id' => $this->objective_id,
      'difficulty_id' => $this->difficulty_id,
      'sort' => $this->sort,
      'icon_url' => $this->icon_url,
      'is_rejected' => $this->is_rejected,
      'reject_note' => $this->reject_note,
    );
  }

  static function static_field_set()
  {
    return [
      'label',
      'description',
      'date_created',
      'requires_fc',
      'has_fc',
      'is_arbitrary',
      'campaign_id',
      'map_id',
      'objective_id',
      'difficulty_id',
      'sort',
      'icon_url',
      'is_rejected',
      'reject_note',
    ];
  }


  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->objective_id = intval($arr[$prefix . 'objective_id']);
    $this->difficulty_id = intval($arr[$prefix . 'difficulty_id']);
    $this->requires_fc = $arr[$prefix . 'requires_fc'] === 't';
    $this->has_fc = $arr[$prefix . 'has_fc'] === 't';
    $this->is_rejected = $arr[$prefix . 'is_rejected'] === 't';

    if (isset($arr[$prefix . 'date_created']))
      $this->date_created = new JsonDateTime($arr[$prefix . 'date_created']);
    if (isset($arr[$prefix . 'campaign_id']))
      $this->campaign_id = intval($arr[$prefix . 'campaign_id']);
    if (isset($arr[$prefix . 'map_id']))
      $this->map_id = intval($arr[$prefix . 'map_id']);
    if (isset($arr[$prefix . 'label']))
      $this->label = $arr[$prefix . 'label'];
    if (isset($arr[$prefix . 'description']))
      $this->description = $arr[$prefix . 'description'];
    if (isset($arr[$prefix . 'is_arbitrary']))
      $this->is_arbitrary = $arr[$prefix . 'is_arbitrary'] === 't';
    if (isset($arr[$prefix . 'sort']))
      $this->sort = intval($arr[$prefix . 'sort']);
    if (isset($arr[$prefix . 'icon_url']))
      $this->icon_url = $arr[$prefix . 'icon_url'];
    if (isset($arr[$prefix . 'reject_note']))
      $this->reject_note = $arr[$prefix . 'reject_note'];
  }

  protected function do_expand_foreign_keys($DB, $depth, $expand_structure)
  {
    $isFromSqlResult = is_array($DB);

    if ($expand_structure && isset($this->campaign_id)) {
      if ($isFromSqlResult) {
        $this->campaign = new Campaign();
        $this->campaign->apply_db_data($DB, "campaign_");
        $this->campaign->expand_foreign_keys($DB, $depth - 1);
      } else {
        $this->campaign = Campaign::get_by_id($DB, $this->campaign_id, $depth - 1);
      }
    }

    if ($expand_structure && isset($this->map_id)) {
      if ($isFromSqlResult) {
        $this->map = new Map();
        $this->map->apply_db_data($DB, "map_");
        $this->map->expand_foreign_keys($DB, $depth - 1);
      } else {
        $this->map = Map::get_by_id($DB, $this->map_id, $depth - 1);
      }
    }

    if ($isFromSqlResult) {
      $this->objective = new Objective();
      $this->objective->apply_db_data($DB, "objective_");
    } else {
      $this->objective = Objective::get_by_id($DB, $this->objective_id);
    }

    if ($isFromSqlResult) {
      $this->difficulty = new Difficulty();
      $this->difficulty->apply_db_data($DB, "difficulty_");
    } else {
      $this->difficulty = Difficulty::get_by_id($DB, $this->difficulty_id);
    }
  }

  // === Find Functions ===
  function fetch_submissions($DB, $filter_suspended = false): bool
  {
    $verified_cond = $this->is_rejected ? "1 = 1" : "submission_is_verified = true";
    $where = ["submission_challenge_id = $1", $verified_cond];
    if ($filter_suspended) {
      $where[] = "(player_account_is_suspended = false OR player_account_is_suspended IS NULL)";
    }

    $where_str = implode(" AND ", $where);

    $query = "SELECT * FROM view_challenge_submissions WHERE $where_str";
    $result = pg_query_params($DB, $query, [$this->id]);

    if ($result === false)
      return false;

    $submissions = [];
    while ($row = pg_fetch_assoc($result)) {
      $submission = new Submission();
      $submission->apply_db_data($row, "submission_");
      $submission->expand_foreign_keys($row, 2, false);
      $submissions[] = $submission;
    }
    $this->submissions = $submissions;
    return true;
  }

  function fetch_all_submissions($DB): bool
  {
    $submissions = $this->fetch_list($DB, 'challenge_id', Submission::class, null, "ORDER BY date_achieved ASC, id ASC");
    if ($submissions === false)
      return false;
    $this->submissions = $submissions;
    foreach ($this->submissions as $submission) {
      $submission->expand_foreign_keys($DB, 2, false);
    }
    return true;
  }

  function attach_campaign_challenges($DB, $with_submissions = false, $include_arbitrary = false)
  {
    if ($this->campaign_id === null) {
      $this->map->campaign->fetch_challenges($DB, $with_submissions, $include_arbitrary);
    } else {
      $this->campaign->fetch_challenges($DB, $with_submissions, $include_arbitrary);
    }
  }

  static function get_player_submission($DB, $challenge_id, $player_id)
  {
    $query = "SELECT * FROM submission WHERE challenge_id = $1 AND player_id = $2 AND (is_verified = true OR is_verified IS NULL)";
    $result = pg_query_params($DB, $query, array($challenge_id, $player_id));
    if ($result === false)
      return null;

    if (pg_num_rows($result) === 0)
      return null;

    $row = pg_fetch_assoc($result);
    $submission = new Submission();
    $submission->apply_db_data($row);
    return $submission;
  }

  static function get_all_rejected($DB)
  {
    $query = "SELECT * FROM view_challenges WHERE challenge_is_rejected = TRUE";
    $result = pg_query_params_or_die($DB, $query);

    $challenges = array();
    while ($row = pg_fetch_assoc($result)) {
      $challenge = new Challenge();
      $challenge->apply_db_data($row, "challenge_");
      $challenge->expand_foreign_keys($row, 5);
      $challenge->fetch_all_submissions($DB);
      $challenges[] = $challenge;
    }
    return $challenges;
  }

  // === Utility Functions ===
  function is_challenge_arbitrary(): bool
  {
    return $this->is_arbitrary || $this->objective->is_arbitrary;
  }

  function get_campaign(): ?Campaign
  {
    return $this->campaign_id === null ? $this->map->campaign : $this->campaign;
  }

  function get_icon_url(): string
  {
    if ($this->objective === null)
      return null;

    if ($this->icon_url !== null) {
      return $this->icon_url;
    }
    return $this->objective->icon_url;
  }

  function __toString()
  {
    return "(Challenge, id:{$this->id}, suffix:'{$this->get_suffix()}')";
  }

  function generate_create_changelog($DB, $from_split = false)
  {
    $addition = $from_split ? " via split" : "";
    Change::create_change($DB, 'challenge', $this->id, "Created challenge" . $addition);
  }

  static function generate_changelog($DB, $old, $new)
  {
    if ($old->id !== $new->id)
      return false;

    if ($old->map_id !== $new->map_id) {
      $oldMap = Map::get_by_id($DB, $old->map_id);
      $newMap = Map::get_by_id($DB, $new->map_id);
      Change::create_change($DB, 'challenge', $new->id, "Moved challenge from map '{$oldMap->name}' to '{$newMap->name}'");
    }
    if ($old->objective_id !== $new->objective_id) {
      $oldObjective = Objective::get_by_id($DB, $old->objective_id);
      $newObjective = Objective::get_by_id($DB, $new->objective_id);
      Change::create_change($DB, 'challenge', $new->id, "Changed objective from '{$oldObjective->name}' to '{$newObjective->name}'");
    }
    if ($old->difficulty_id !== $new->difficulty_id) {
      $oldDifficulty = Difficulty::get_by_id($DB, $old->difficulty_id);
      $newDifficulty = Difficulty::get_by_id($DB, $new->difficulty_id);
      Change::create_change($DB, 'challenge', $new->id, "Moved from '{$oldDifficulty->to_tier_name()}' to '{$newDifficulty->to_tier_name()}'");
    }
    if ($old->label !== $new->label) {
      Change::create_change($DB, 'challenge', $new->id, "Changed label from '{$old->label}' to '{$new->label}'");
    }
    if ($old->description !== $new->description) {
      Change::create_change($DB, 'challenge', $new->id, "Changed description from '{$old->description}' to '{$new->description}'");
    }
    if ($old->requires_fc !== $new->requires_fc) {
      $stateNow = $new->requires_fc ? "Requires FC" : "Doesn't require FC";
      Change::create_change($DB, 'challenge', $new->id, "Marked challenge as '{$stateNow}'");
    }
    if ($old->has_fc !== $new->has_fc) {
      $stateNow = $new->has_fc ? "Has FC" : "Doesn't have FC";
      Change::create_change($DB, 'challenge', $new->id, "Marked challenge as '{$stateNow}'");
    }
    if ($old->is_rejected !== $new->is_rejected) {
      $stateNow = $new->is_rejected ? "Rejected" : "Not Rejected";
      Change::create_change($DB, 'challenge', $new->id, "Marked challenge as '{$stateNow}'");
    }
    if ($old->is_arbitrary !== $new->is_arbitrary) {
      $stateNow = $new->is_arbitrary ? "Arbitrary challenge" : "Non-arbitrary challenge";
      Change::create_change($DB, 'challenge', $new->id, "Marked challenge as '{$stateNow}'");
    }
    if ($old->sort !== $new->sort) {
      $oldVal = $old->sort === null ? "null" : $old->sort;
      $newVal = $new->sort === null ? "null" : $new->sort;
      Change::create_change($DB, 'challenge', $new->id, "Changed sort order from '{$oldVal}' to '{$newVal}'");
    }

    return true;
  }

  function get_suffix(): ?string
  {
    if ($this->label !== null) {
      return $this->label;
    } else if ($this->objective !== null) {
      if ($this->objective->display_name_suffix !== null) {
        return $this->objective->display_name_suffix;
      }
    }

    return null;
  }

  function get_name($no_campaign = false, $no_map = false): string
  {
    $map_name = $this->map !== null ? $this->map->get_name($no_campaign) : $this->campaign->get_name();
    $objective_name = $this->objective->name;
    $c_fc = $this->get_c_fc();
    $label_suffix = $this->get_suffix() !== null ? " [{$this->get_suffix()}]" : "";

    if ($no_map) {
      return "{$objective_name} [{$c_fc}]{$label_suffix}";
    } else {
      return "{$map_name} / {$objective_name} [{$c_fc}]{$label_suffix}";
    }
  }
  function get_name_for_discord(): string
  {
    $map_name = $this->map !== null ? $this->map->get_name_for_discord() : $this->campaign->get_name_for_discord();
    $objective_name = $this->objective->name;
    $c_fc = $this->get_c_fc();
    $label_suffix = $this->get_suffix() !== null ? " [{$this->get_suffix()}]" : "";
    $challenge_url = $this->get_url();

    return "{$map_name} / [{$objective_name} [{$c_fc}]{$label_suffix}](<$challenge_url>)";
  }

  function get_c_fc(): string
  {
    if ($this->requires_fc)
      return "FC";
    else if ($this->has_fc)
      return "C/FC";
    else
      return "C";
  }

  function get_url(): string
  {
    return constant('BASE_URL') . "/challenge/{$this->id}";
  }
}