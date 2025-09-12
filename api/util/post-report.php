<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_access($account);

//Extract parameters: topic, message, url (optional)
$data = format_assoc_array_bools(parse_post_body_as_json());
$topic = trim($data['topic'] ?? null);
$message = trim($data['message'] ?? null);
$url = trim($data['url'] ?? null);
if ($topic === null || $message === null) {
  die_json(400, 'Missing parameters');
}

$player = $account->player;

send_webhook_mod_report($player, $topic, $message, $url);