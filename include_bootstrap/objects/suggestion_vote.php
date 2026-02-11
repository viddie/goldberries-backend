<?php

class SuggestionVote extends DbObject
{
  public static string $table_name = 'suggestion_vote';

  public int $suggestion_id;
  public int $player_id;
  public string $vote;
  public ?string $comment = null;

  // Linked Objects
  public ?Suggestion $suggestion = null;
  public ?Player $player = null;

  // Other Objects
  public ?Submission $submission = null;

  // === Abstract Functions ===
  function get_field_set()
  {
    return array(
      'suggestion_id' => $this->suggestion_id,
      'player_id' => $this->player_id,
      'vote' => $this->vote,
      'comment' => $this->comment,
    );
  }
  static function static_field_set()
  {
    return [
      'suggestion_id',
      'player_id',
      'vote',
      'comment',
    ];
  }

  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->vote = $arr[$prefix . 'vote'];

    if (isset($arr[$prefix . 'suggestion_id']))
      $this->suggestion_id = intval($arr[$prefix . 'suggestion_id']);
    if (isset($arr[$prefix . 'player_id']))
      $this->player_id = intval($arr[$prefix . 'player_id']);
    if (isset($arr[$prefix . 'comment'])) {
      $this->comment = $arr[$prefix . 'comment'];
      $this->comment = trim($this->comment);
    }
  }

  protected function do_expand_foreign_keys($DB, $depth, $expand_structure)
  {
    $isFromSqlResult = is_array($DB);

    if ($expand_structure && $this->suggestion_id !== null) {
      if ($isFromSqlResult) {
        //Not implemented
        $this->suggestion = null;
      } else {
        $this->suggestion = Suggestion::get_by_id($DB, $this->suggestion_id, $depth - 1, $expand_structure);
      }
    }
    if ($this->player_id !== null) {
      if ($isFromSqlResult) {
        $this->player = new Player();
        $this->player->apply_db_data($DB, 'suggestion_player_', false);
      } else {
        $this->player = Player::get_by_id($DB, $this->player_id, 3, false);
      }
    }
  }

  #region Expand Batching
  protected function get_expand_list($level, $expand_structure)
  {
    $arr = [];
    if ($level > 1) {
      if ($expand_structure && $this->suggestion_id !== null && $this->suggestion !== null) {
        DbObject::merge_expand_lists($arr, $this->suggestion->get_expand_list($level - 1, $expand_structure));
      }
      if ($this->player_id !== null && $this->player !== null) {
        DbObject::merge_expand_lists($arr, $this->player->get_expand_list($level - 1, false));
      }
      return $arr;
    }

    if ($expand_structure && $this->suggestion_id !== null) {
      DbObject::add_to_expand_list($arr, Suggestion::class, $this->suggestion_id);
    }
    if ($this->player_id !== null) {
      DbObject::add_to_expand_list($arr, Player::class, $this->player_id);
    }
    return $arr;
  }

  protected function apply_expand_data($data, $level, $expand_structure)
  {
    if ($level > 1) {
      if ($expand_structure && $this->suggestion_id !== null && $this->suggestion !== null) {
        $this->suggestion->apply_expand_data($data, $level - 1, $expand_structure);
      }
      if ($this->player_id !== null && $this->player !== null) {
        $this->player->apply_expand_data($data, $level - 1, false);
      }
      return;
    }

    if ($expand_structure && $this->suggestion_id !== null) {
      $this->suggestion = new Suggestion();
      $this->suggestion->apply_db_data(DbObject::get_object_from_data_list($data, Suggestion::class, $this->suggestion_id));
    }
    if ($this->player_id !== null) {
      $this->player = new Player();
      $this->player->apply_db_data(DbObject::get_object_from_data_list($data, Player::class, $this->player_id));
    }
  }
  #endregion

  // === Find Functions ===
  static function has_voted_on_suggestion($DB, $player_id, $suggestion_id)
  {
    $query = "SELECT * FROM suggestion_vote WHERE player_id = $1 AND suggestion_id = $2";
    $result = pg_query_params($DB, $query, array($player_id, $suggestion_id));
    return pg_num_rows($result) > 0;
  }

  // === Utility Functions ===
  function __toString()
  {
    return "(SuggestionVote, id:{$this->id}, player:{$this->player->name}, vote:{$this->vote}, comment:{$this->comment})";
  }

  // If the player has made a submission for the suggested challenge, fetches it
  function find_submission($DB, $suggestion)
  {
    if ($suggestion->challenge_id === null)
      return;

    $isFromSqlResult = is_array($DB);

    if ($isFromSqlResult) {
      if (isset($DB['submission_id'])) {
        $this->submission = new Submission();
        $this->submission->apply_db_data($DB, 'submission_');
        $this->submission->expand_foreign_keys($DB, 2, false);
      } else {
        $this->submission = null;
      }
    } else {
      $query = "SELECT * FROM submission WHERE player_id = $1 AND challenge_id = $2 AND is_verified = true";
      $result = pg_query_params($DB, $query, array($this->player_id, $suggestion->challenge_id));
      if (pg_num_rows($result) === 0)
        return;

      $row = pg_fetch_assoc($result);
      $this->submission = new Submission();
      $this->submission->apply_db_data($row);
      $this->submission->expand_foreign_keys($DB, 2, false);
    }
  }
}