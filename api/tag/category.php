<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (isset($_REQUEST['id'])) {
    $category = TagCategory::get_by_id($DB, check_id($_REQUEST['id']));
    if ($category === false) {
      die_json(404, "Tag category not found");
    }
    api_write($category);
    exit();
  }

  api_write(TagCategory::get_all($DB));
}
#endregion

#region POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $account = get_user_data();
  check_role($account, $HELPER);

  $data = parse_post_body_as_json();

  $old_category_str = null;
  if (isset($data['id'])) {
    $category = TagCategory::get_by_id($DB, intval($data['id']));
    if ($category === false) {
      die_json(404, "Tag category not found");
    }
    $old_category_str = (string) $category;
  } else {
    $category = new TagCategory();
  }

  $name = isset($data['name']) && is_string($data['name']) ? trim($data['name']) : '';
  if ($name === '' || mb_strlen($name) > 64) {
    die_json(400, "name is required and can't be longer than 64 characters");
  }
  $description = isset($data['description']) && is_string($data['description']) ? trim($data['description']) : null;
  if ($description === '')
    $description = null;
  if ($description !== null && mb_strlen($description) > 5000) {
    die_json(400, "description can't be longer than 5000 characters");
  }
  $color = isset($data['color']) && is_string($data['color']) ? trim($data['color']) : null;
  if ($color === '')
    $color = null;
  if ($color !== null && mb_strlen($color) > 32) {
    die_json(400, "color can't be longer than 32 characters");
  }

  $category->name = $name;
  $category->description = $description;
  $category->color = $color;
  $category->sort = isset($data['sort']) ? intval($data['sort']) : 0;
  $category->is_archived = isset($data['is_archived']) ? $data['is_archived'] === true : false;

  if ($old_category_str !== null) {
    if (!$category->update($DB)) {
      die_json(500, "Failed to update tag category");
    }
    log_info("'{$account->player->name}' updated {$old_category_str} to {$category}", "Tag");
  } else {
    if (!$category->insert($DB)) {
      die_json(500, "Failed to create tag category");
    }
    log_info("'{$account->player->name}' created {$category}", "Tag");
  }

  api_write($category);
}
#endregion

#region DELETE Request
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
  $account = get_user_data();
  check_role($account, $HELPER);
  reject_api_keys($account);

  if (!isset($_REQUEST['id'])) {
    die_json(400, "Missing id");
  }

  $category = TagCategory::get_by_id($DB, check_id($_REQUEST['id']));
  if ($category === false) {
    die_json(404, "Tag category not found");
  }

  $count_tags = $category->count_tags($DB);
  if ($count_tags > 0) {
    die_json(400, "Tag category still contains {$count_tags} tag(s). Move or delete them first.");
  }

  if ($category->delete($DB)) {
    log_info("'{$account->player->name}' deleted {$category}", "Tag");
    api_write($category);
  } else {
    die_json(500, "Failed to delete tag category");
  }
}
#endregion
