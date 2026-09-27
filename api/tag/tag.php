<?php

require_once('../api_bootstrap.inc.php');

#region GET Request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (isset($_REQUEST['id'])) {
    $tag = Tag::get_by_id($DB, check_id($_REQUEST['id']));
    if ($tag === false) {
      die_json(404, "Tag not found");
    }
    api_write($tag);
    exit();
  }

  // Full definition tree: categories -> tags -> values
  $definitions = Tag::get_definitions($DB);
  api_write($definitions['categories']);
}
#endregion

#region POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $account = get_user_data();
  check_role($account, $HELPER);

  $data = parse_post_body_as_json();

  $old_tag_str = null;
  if (isset($data['id'])) {
    $tag = Tag::get_by_id($DB, intval($data['id']));
    if ($tag === false) {
      die_json(404, "Tag not found");
    }
    $old_tag_str = "{$tag} [{$tag->get_values_string()}]";
  } else {
    $tag = new Tag();
  }

  // Validate fields
  $name = isset($data['name']) && is_string($data['name']) ? trim($data['name']) : '';
  if ($name === '' || mb_strlen($name) > 64) {
    die_json(400, "name is required and can't be longer than 64 characters");
  }
  if (!isset($data['description']) || !is_string($data['description'])) {
    die_json(400, "description is required");
  }
  $description = trim($data['description']);
  if (mb_strlen($description) > 5000) {
    die_json(400, "description can't be longer than 5000 characters");
  }
  if (!isset($data['category_id'])) {
    die_json(400, "category_id is required");
  }
  $category = TagCategory::get_by_id($DB, intval($data['category_id']));
  if ($category === false) {
    die_json(400, "Tag category with id {$data['category_id']} does not exist");
  }
  $selection_mode = $data['selection_mode'] ?? 'single';
  if (!in_array($selection_mode, Tag::$SELECTION_MODES, true)) {
    die_json(400, "selection_mode must be one of: " . implode(', ', Tag::$SELECTION_MODES));
  }
  $is_ordinal = isset($data['is_ordinal']) ? $data['is_ordinal'] === true : false;
  if ($selection_mode === 'range' && !$is_ordinal) {
    die_json(400, "Only ordinal tags can use the 'range' selection mode");
  }

  $tag->category_id = $category->id;
  $tag->name = $name;
  $tag->description = $description;
  $tag->sort = isset($data['sort']) ? intval($data['sort']) : 0;
  $tag->is_common = isset($data['is_common']) ? $data['is_common'] === true : false;
  $tag->is_player_assignable = isset($data['is_player_assignable']) ? $data['is_player_assignable'] === true : true;
  $tag->is_ordinal = $is_ordinal;
  $tag->selection_mode = $selection_mode;

  // Validate all values before writing anything
  $values = $tag->parse_values_payload($data['values'] ?? []);

  if ($old_tag_str !== null) {
    if (!$tag->update($DB)) {
      die_json(500, "Failed to update tag");
    }
  } else {
    if (!$tag->insert($DB)) {
      die_json(500, "Failed to create tag");
    }
  }
  $tag->save_values($DB, $values);

  if ($old_tag_str !== null) {
    log_info("'{$account->player->name}' updated {$old_tag_str} to {$tag} [{$tag->get_values_string()}]", "Tag");
  } else {
    log_info("'{$account->player->name}' created {$tag} [{$tag->get_values_string()}]", "Tag");
  }

  $tag = Tag::get_by_id($DB, $tag->id);
  api_write($tag);
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

  $tag = Tag::get_by_id($DB, check_id($_REQUEST['id']));
  if ($tag === false) {
    die_json(404, "Tag not found");
  }

  $result = pg_query_params_or_die(
    $DB,
    "SELECT COUNT(*) FROM challenge_tag JOIN tag_value ON tag_value.id = challenge_tag.tag_value_id WHERE tag_value.tag_id = $1",
    [$tag->id],
    "Failed to count tag assignments"
  );
  $count_assignments = intval(pg_fetch_result($result, 0, 0));

  if ($tag->delete($DB)) {
    log_info("'{$account->player->name}' deleted {$tag} [{$tag->get_values_string()}] with {$count_assignments} assignment(s)", "Tag");
    api_write($tag);
  } else {
    die_json(500, "Failed to delete tag");
  }
}
#endregion
