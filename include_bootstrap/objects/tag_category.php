<?php

class TagCategory extends DbObject
{
  public static string $table_name = 'tag_category';

  public string $name;
  public ?string $description = null;
  public int $sort = 0;
  public ?string $color = null;
  public bool $is_archived = false;

  // Associative Objects
  public ?array $tags = null; /* Tag[] */


  #region Abstract Functions
  function get_field_set()
  {
    return array(
      'name' => $this->name,
      'description' => $this->description,
      'sort' => $this->sort,
      'color' => $this->color,
      'is_archived' => $this->is_archived,
    );
  }

  static function static_field_set()
  {
    return [
      'name',
      'description',
      'sort',
      'color',
      'is_archived',
    ];
  }

  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->name = $arr[$prefix . 'name'];
    $this->sort = intval($arr[$prefix . 'sort']);
    $this->is_archived = $arr[$prefix . 'is_archived'] === 't';

    if (isset($arr[$prefix . 'description']))
      $this->description = $arr[$prefix . 'description'];
    if (isset($arr[$prefix . 'color']))
      $this->color = $arr[$prefix . 'color'];
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
  static function get_all($DB): array
  {
    $result = pg_query_params_or_die($DB, "SELECT * FROM tag_category ORDER BY sort, id", [], "Failed to fetch tag categories");
    $categories = [];
    while ($row = pg_fetch_assoc($result)) {
      $category = new TagCategory();
      $category->apply_db_data($row);
      $categories[] = $category;
    }
    return $categories;
  }

  function count_tags($DB): int
  {
    $result = pg_query_params_or_die($DB, "SELECT COUNT(*) FROM tag WHERE category_id = $1", [$this->id], "Failed to count tags of category");
    return intval(pg_fetch_result($result, 0, 0));
  }
  #endregion

  #region Utility Functions
  function __toString()
  {
    $archivedStr = $this->is_archived ? 'true' : 'false';
    return "(TagCategory, id:{$this->id}, name:'{$this->name}', is_archived:{$archivedStr})";
  }
  #endregion
}
