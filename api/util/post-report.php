<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_access($account);

//Extract parameters: topic, message, url (optional)
$topic = trim($_REQUEST['topic'] ?? null);
$message = trim($_REQUEST['message'] ?? null);
$url = trim($_REQUEST['url'] ?? null);
if ($topic === null || $message === null) {
  die_json(400, 'Missing parameters');
}

$player = $account->player;

send_webhook_mod_report($player, $topic, $message, $url);