<?php

class TagValue extends DbObject
{
  public static string $table_name = 'tag_value';

  public int $tag_id;
  public ?string $name = null; // null = implicit value of a tag without qualifiers
  public ?string $description = null;
  public int $sort = 0;

  // Linked Objects
  public ?Tag $tag = null;


  #region Abstract Functions
  function get_field_set()
  {
    return array(
      'tag_id' => $this->tag_id,
      'name' => $this->name,
      'description' => $this->description,
      'sort' => $this->sort,
    );
  }

  static function static_field_set()
  {
    return [
      'tag_id',
      'name',
      'description',
      'sort',
    ];
  }

  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->tag_id = intval($arr[$prefix . 'tag_id']);
    $this->sort = intval($arr[$prefix . 'sort']);

    if (isset($arr[$prefix . 'name']))
      $this->name = $arr[$prefix . 'name'];
    if (isset($arr[$prefix . 'description']))
      $this->description = $arr[$prefix . 'description'];
  }

  protected function do_expand_foreign_keys($DB, $depth, $expand_structure)
  {
    if ($expand_structure && isset($this->tag_id)) {
      $this->tag = Tag::get_by_id($DB, $this->tag_id, $depth - 1);
    }
  }

  protected function get_expand_list($level, $expand_structure)
  {
    return [];
  }

  protected function apply_expand_data($data, $level, $expand_structure)
  {
  }
  #endregion

  #region Utility Functions
  function is_implicit(): bool
  {
    return $this->name === null;
  }

  function __toString()
  {
    $nameStr = $this->name === null ? '<implicit>' : "'{$this->name}'";
    return "(TagValue, id:{$this->id}, tag_id:{$this->tag_id}, name:{$nameStr})";
  }
  #endregion
}
