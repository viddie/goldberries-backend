<?php

function task_report_gamebanana_urls($DB)
{
  $batch_size = 5;
  $max_consecutive_failures = 10;
  $request_delay_microseconds = 1500000;
  $topic = 'Maintenance';

  $result = pg_query_params_or_die(
    $DB,
    'SELECT * FROM campaign WHERE url IS NOT NULL ORDER BY id ASC',
    [],
    'Failed to query campaigns with URLs for GameBanana report'
  );

  $campaigns = [];
  while ($row = pg_fetch_assoc($result)) {
    $campaign = new Campaign();
    $campaign->apply_db_data($row);

    if ($campaign->get_gamebanana_info() === null) {
      continue;
    }

    $campaigns[] = $campaign;
  }

  $total_campaigns = count($campaigns);
  $projected_processing_seconds = intval(ceil(($request_delay_microseconds / 1000000) * $total_campaigns * 1.05));
  $projected_processing_time = report_gamebanana_urls_format_duration($projected_processing_seconds);
  $start_message = "Starting weekly GameBanana URL report for {$total_campaigns} campaign(s). Projected processing time: {$projected_processing_time}.";
  echo $start_message . "\n";
  send_simple_webhook_message(MOD_REPORT_WEBHOOK_URL, $start_message);
  log_info($start_message, $topic);

  $stop_after = getenv('DEBUG') === 'true' ? 10 : 999999;
  $checked_count = 0;
  $available_count = 0;
  $unavailable_count = 0;
  $consecutive_failure_count = 0;
  $error_count = 0;
  $batch_count = 0;
  $pending_unavailable = [];
  $stop_reason = null;

  foreach ($campaigns as $campaign) {
    if ($checked_count > $stop_after) {
      $stop_reason = "Stopped early because the debug limit of {$stop_after} campaigns was reached.";
      echo $stop_reason . "\n";
      break;
    }
    $checked_count++;
    $gb_info = $campaign->get_gamebanana_info();
    $probe = report_gamebanana_urls_probe_item($gb_info['item_type'], $gb_info['id']);

    if ($probe['status'] === 'available') {
      $available_count++;
      $consecutive_failure_count = 0;
    } else if ($probe['status'] === 'unavailable') {
      $unavailable_count++;
      $consecutive_failure_count = 0;
      $pending_unavailable[] = report_gamebanana_urls_format_unavailable_line($campaign);

      echo "[{$checked_count}/{$total_campaigns}] Missing: {$campaign->name} ({$campaign->url})\n";

      if (count($pending_unavailable) >= $batch_size) {
        $batch_count++;
        report_gamebanana_urls_flush_unavailable_batch($pending_unavailable);
        $pending_unavailable = [];
      }
    } else {
      $error_count++;
      $consecutive_failure_count++;
      echo "[{$checked_count}/{$total_campaigns}] Error: {$campaign->name} ({$probe['message']})\n";
      log_error("GameBanana check failed for campaign '{$campaign->name}' ({$campaign->url}): {$probe['message']}", $topic);

      if ($consecutive_failure_count >= $max_consecutive_failures) {
        $stop_reason = "Stopped early after {$consecutive_failure_count} consecutive GameBanana lookup failures. Last failure: '{$campaign->name}' ({$campaign->url}) - {$probe['message']}";
        echo $stop_reason . "\n";
        log_error($stop_reason, $topic);
        break;
      }
    }

    if ($checked_count % 25 === 0 || $checked_count === $total_campaigns) {
      echo "Progress: {$checked_count}/{$total_campaigns} checked, {$unavailable_count} unavailable, {$error_count} errors\n";
    }

    if ($checked_count < $total_campaigns) {
      usleep($request_delay_microseconds);
    }
  }

  if (count($pending_unavailable) > 0) {
    $batch_count++;
    report_gamebanana_urls_flush_unavailable_batch($pending_unavailable);
  }

  $summary_message = "Finished weekly GameBanana URL report. Checked: {$checked_count}, available: {$available_count}, unavailable: {$unavailable_count}, API errors: {$error_count}.";
  if ($stop_reason !== null) {
    $summary_message = $summary_message . " {$stop_reason}";
  }
  echo $summary_message . "\n";
  send_simple_webhook_message(MOD_REPORT_WEBHOOK_URL, $summary_message);
  log_info($summary_message, $topic);
}

function report_gamebanana_urls_flush_unavailable_batch($lines)
{
  $message = implode("\n", $lines);
  send_simple_webhook_message(MOD_REPORT_WEBHOOK_URL, $message);
}

function report_gamebanana_urls_format_unavailable_line($campaign)
{
  return '- ' . $campaign->get_name_for_discord() . " - <{$campaign->url}>";
}

function report_gamebanana_urls_format_duration($seconds)
{
  $seconds = max(0, intval($seconds));
  $hours = intdiv($seconds, 3600);
  $minutes = intdiv($seconds % 3600, 60);
  $remaining_seconds = $seconds % 60;

  if ($hours > 0) {
    return "{$hours}h {$minutes}m {$remaining_seconds}s";
  }

  if ($minutes > 0) {
    return "{$minutes}m {$remaining_seconds}s";
  }

  return "{$remaining_seconds}s";
}

function report_gamebanana_urls_probe_item($item_type, $item_id)
{
  $api_url = 'https://api.gamebanana.com/Core/Item/Data?itemtype=' . rawurlencode($item_type)
    . '&itemid=' . rawurlencode(strval($item_id))
    . '&fields=' . rawurlencode('Files().aFiles()');

  $response = fetch_data_response($api_url, 5, 15);
  $body = $response['body'];
  $http_code = $response['http_code'];
  $curl_error = $response['error'];

  if ($body === false) {
    return [
      'status' => 'error',
      'message' => $curl_error !== '' ? $curl_error : 'Unknown cURL error',
    ];
  }

  $decoded = json_decode($body, true);
  if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
    return [
      'status' => 'error',
      'message' => 'Failed to decode GameBanana response: ' . json_last_error_msg(),
    ];
  }

  if (is_array($decoded) && isset($decoded['error_code'])) {
    $error_message = $decoded['error'] ?? 'Unknown GameBanana API error';
    if ($decoded['error_code'] === 'INVALID_PARAMS' && strpos($error_message, "doesn't exist") !== false) {
      return [
        'status' => 'unavailable',
        'message' => $error_message,
      ];
    }

    return [
      'status' => 'error',
      'message' => $error_message,
    ];
  }

  if ($http_code >= 400) {
    return [
      'status' => 'error',
      'message' => "GameBanana returned HTTP {$http_code}",
    ];
  }

  if (is_array($decoded)) {
    return [
      'status' => 'available',
      'message' => 'OK',
    ];
  }

  return [
    'status' => 'error',
    'message' => 'Unexpected GameBanana response shape',
  ];
}