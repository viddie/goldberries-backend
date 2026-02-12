<?php

class NewChallenge extends DbObject
{
  public static string $table_name = 'new_challenge';

  public string $url;
  public ?string $name = null;
  public ?string $description;
  public ?StringList $collectibles = null;
  public ?string $golden_changes = null;


  #region Abstract Functions
  function get_field_set()
  {
    return array(
      'url' => $this->url,
      'name' => $this->name,
      'description' => $this->description,
      'collectibles' => $this->collectibles === null ? null : $this->collectibles->__toString(),
      'golden_changes' => $this->golden_changes,
    );
  }
  static function static_field_set()
  {
    return [
      'url',
      'name',
      'description',
      'collectibles',
      'golden_changes',
    ];
  }

  function apply_db_data($arr, $prefix = '')
  {
    $this->id = intval($arr[$prefix . 'id']);
    $this->url = $arr[$prefix . 'url'];
    $this->description = $arr[$prefix . 'description'];

    if (isset($arr[$prefix . 'name']))
      $this->name = $arr[$prefix . 'name'];
    if (isset($arr[$prefix . 'collectibles'])) {
      $value = $arr[$prefix . 'collectibles'];
      if (is_array($value)) {
        if (count($value) > 0) {
          $this->collectibles = new StringList(5);
          $this->collectibles->arr = $value;
        } else {
          $this->collectibles = null;
        }
      } else {
        $this->collectibles = new StringList(5, $value);
      }
    }
    if (isset($arr[$prefix . 'golden_changes']))
      $this->golden_changes = $arr[$prefix . 'golden_changes'];
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
  #endregion

  #region Utility Functions
  function __toString()
  {
    return "(NewChallenge, id:{$this->id}, url:'{$this->url}', name:'{$this->name}', description:'{$this->description}')";
  }

  function get_name(): string
  {
    return "New Challenge: {$this->name}";
  }
  function get_name_for_discord(): string
  {
    $name = $this->get_name_escaped();
    return "`New Challenge: {$name}`";
  }

  function get_name_escaped()
  {
    //Regex remove backticks from the name, then return
    return preg_replace('/`/', '', $this->name);
  }
  #endregion
}