<?php

class Post extends DbObject
{
  public static string $table_name = 'post';
  public static array $TYPES = ["news", "changelog"];

  public ?int $author_id = null;
  public JsonDateTime $date_created;
  public ?JsonDateTime $date_edited = null;
  public string $type;
  public ?string $image_url = null;
  public string $title;
  public string $content;

  // Linked Objects
  public ?Player $author = null;

  #region Abstract Functions
  function get_field_set()
  {
    return array(
      'author_id' => $this->author_id,
      'date_created' => $this->date_created,
      'date_edited' => $this->date_edited,
      'type' => $this->type,
      'image_url' => $this->image_url,
      'title' => $this->title,
      'content' => $this->content,
    );
  }
  static function static_field_set()
  {
    return [
      'author_id',
      'date_created',
      'date_edited',
      'type',
      'image_url',
      'title',
      'content',
    ];
  }

  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->date_created = new JsonDateTime($arr[$prefix . 'date_created']);
    $this->type = $arr[$prefix . 'type'];
    $this->title = $arr[$prefix . 'title'];
    $this->content = $arr[$prefix . 'content'];

    if (isset($arr[$prefix . 'author_id']))
      $this->author_id = intval($arr[$prefix . 'author_id']);
    if (isset($arr[$prefix . 'date_edited']))
      $this->date_edited = new JsonDateTime($arr[$prefix . 'date_edited']);
    if (isset($arr[$prefix . 'image_url']))
      $this->image_url = $arr[$prefix . 'image_url'];
  }

  protected function do_expand_foreign_keys($DB, $depth, $expand_structure)
  {
    if ($this->author_id !== null) {
      $this->author = Player::get_by_id($DB, $this->author_id, 3, false);
    }
  }
  #endregion

  #region Expand Batching
  protected function get_expand_list($level, $expand_structure)
  {
    $arr = [];
    if ($level > 1) {
      if ($this->author_id !== null) {
        DbObject::merge_expand_lists($arr, $this->author->get_expand_list($level - 1, false));
      }
      return $arr;
    }

    if ($this->author_id !== null) {
      DbObject::add_to_expand_list($arr, Player::class, $this->author_id);
    }
    return $arr;
  }

  protected function apply_expand_data($data, $level, $expand_structure)
  {
    if ($level > 1) {
      if ($this->author_id !== null) {
        $this->author->apply_expand_data($data, $level - 1, false);
      }
      return;
    }

    if ($this->author_id !== null) {
      $this->author = new Player();
      $this->author->apply_db_data(DbObject::get_object_from_data_list($data, Player::class, $this->author_id));
    }
  }
  #endregion

  #region Find Functions
  static function get_paginated($DB, $page, $per_page, $type, $search, $author_id)
  {
    $query = "SELECT * FROM post";

    $where = [];
    if (!in_array($type, self::$TYPES)) {
      die_json(400, "Invalid type");
    }
    $where[] = "type = '$type'";

    if ($search !== null) {
      $search = pg_escape_string($search);
      $where[] = "(content ILIKE '%$search%' OR title ILIKE '%$search%')";
    }
    if ($author_id !== null) {
      $where[] = "author_id = $author_id";
    }

    if (count($where) > 0) {
      $query .= " WHERE " . implode(" AND ", $where);
    }

    $query .= " ORDER BY post.date_created DESC";

    $query = "
    WITH query AS (
      " . $query . "
    )
    SELECT *, count(*) OVER () AS total_count
    FROM query";

    if ($per_page !== -1) {
      $query .= " LIMIT " . $per_page . " OFFSET " . ($page - 1) * $per_page;
    }

    $result = pg_query_params_or_die($DB, $query);

    $maxCount = 0;
    $posts = [];
    while ($row = pg_fetch_assoc($result)) {
      if ($maxCount === 0) {
        $maxCount = intval($row['total_count']);
      }

      $post = new Post();
      $post->apply_db_data($row);
      $post->expand_foreign_keys($DB, 5, true);
      $posts[] = $post;
    }

    return [
      'posts' => $posts,
      'max_count' => $maxCount,
      'max_page' => ceil($maxCount / $per_page),
      'page' => $page,
      'per_page' => $per_page,
    ];
  }
  #endregion

  #region Utility Functions
  function __toString()
  {
    $authorStr = $this->author_id !== null ? $this->author_id : "<unknown>";
    return "(Post, id:{$this->id}, author:{$authorStr}, title:'{$this->title}')";
  }

  //This function assumes a fully expanded structure
  function get_url()
  {
    return constant("BASE_URL") . "/" . $this->type . "/" . $this->id;
  }
  #endregion
}