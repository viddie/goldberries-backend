<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  die_json(405, "Method Not Allowed");
}

$account = get_user_data();
check_role($account, $HELPER);
reject_api_keys($account);

$data = parse_post_body_as_json();
if (!isset($data['tag_id']) || !is_int($data['tag_id']) || $data['tag_id'] <= 0) {
  die_json(400, "tag_id must be a positive integer");
}

$tag = Tag::get_by_id($DB, $data['tag_id']);
if ($tag === false) {
  die_json(404, "Tag not found");
}

$value_ids = null;
if (array_key_exists('tag_value_ids', $data)) {
  if (!is_array($data['tag_value_ids']) || !array_is_list($data['tag_value_ids']) || count($data['tag_value_ids']) === 0) {
    die_json(400, "tag_value_ids must be a non-empty list");
  }
  foreach ($data['tag_value_ids'] as $value_id) {
    if (!is_int($value_id) || !$tag->has_value($value_id)) {
      die_json(400, "Each tag_value_id must belong to this tag");
    }
  }
  $value_ids = array_values(array_unique($data['tag_value_ids']));
}

$params = [$tag->id];
$where = "tag_value.tag_id = $1";
if ($value_ids !== null) {
  $where .= " AND tag_value.id = ANY($2::integer[])";
  $params[] = '{' . implode(',', $value_ids) . '}';
}

$result = pg_query_params_or_die(
  $DB,
  "DELETE FROM challenge_tag USING tag_value WHERE challenge_tag.tag_value_id = tag_value.id AND {$where}",
  $params,
  "Failed to clear tag assignments"
);
$removed = pg_affected_rows($result);
$scope = $value_ids === null ? 'all values' : 'value ids: ' . implode(', ', $value_ids);
log_info("'{$account->player->name}' cleared {$removed} assignment(s) of {$tag} ({$scope})", "Tag");

api_write([
  'tag_id' => $tag->id,
  'tag_value_ids' => $value_ids,
  'removed' => $removed,
]);
