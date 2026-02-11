<?php

class Objective extends DbObject
{
  public static string $table_name = 'objective';

  public string $name;
  public string $description;
  public ?string $display_name_suffix = null;
  public bool $is_arbitrary;
  public ?string $icon_url = null;


  // === Abstract Functions ===
  function get_field_set()
  {
    return array(
      'name' => $this->name,
      'description' => $this->description,
      'display_name_suffix' => $this->display_name_suffix,
      'is_arbitrary' => $this->is_arbitrary,
      'icon_url' => $this->icon_url
    );
  }
  static function static_field_set()
  {
    return [
      'name',
      'description',
      'display_name_suffix',
      'is_arbitrary',
      'icon_url'
    ];
  }

  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->name = $arr[$prefix . 'name'];
    $this->description = $arr[$prefix . 'description'];
    $this->is_arbitrary = $arr[$prefix . 'is_arbitrary'] === 't';

    if (isset($arr[$prefix . 'display_name_suffix']))
      $this->display_name_suffix = $arr[$prefix . 'display_name_suffix'];
    if (isset($arr[$prefix . 'icon_url']))
      $this->icon_url = $arr[$prefix . 'icon_url'];
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

  // === Find Functions ===

  // === Utility Functions ===
  function __toString()
  {
    $arbitraryStr = $this->is_arbitrary ? 'true' : 'false';
    return "(Objective, id:{$this->id}, name:'{$this->name}', is_arbitrary:{$arbitraryStr})";
  }
}