<?php

function task_report_gamebanana_urls($DB)
{
  $batch_size = 5;
  $max_consecutive_failures = 15;
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
  $counter_width = strlen(strval($total_campaigns));
  $projected_processing_seconds = intval(ceil(($request_delay_microseconds / 1000000) * $total_campaigns * 1.2));
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
    $counter = str_pad($checked_count, $counter_width, ' ', STR_PAD_LEFT);
    $gb_info = $campaign->get_gamebanana_info();
    $probe = report_gamebanana_urls_probe_item($gb_info['item_type'], $gb_info['id']);

    if ($probe['status'] === 'available') {
      $available_count++;
      $consecutive_failure_count = 0;
    } else if ($probe['status'] === 'unavailable') {
      $unavailable_count++;
      $consecutive_failure_count = 0;
      $pending_unavailable[] = report_gamebanana_urls_format_unavailable_line($campaign);

      echo "[{$counter}/{$total_campaigns}] MISSING {$campaign->name} ({$campaign->url})\n";

      if (count($pending_unavailable) >= $batch_size) {
        $batch_count++;
        report_gamebanana_urls_flush_unavailable_batch($pending_unavailable);
        $pending_unavailable = [];
      }
    } else {
      $error_count++;
      $consecutive_failure_count++;
      echo "[{$counter}/{$total_campaigns}] ERROR   {$campaign->name} ({$probe['message']})\n";
      log_error("GameBanana check failed for campaign '{$campaign->name}' ({$campaign->url}): {$probe['message']}", $topic);

      if ($consecutive_failure_count >= $max_consecutive_failures) {
        $stop_reason = "Stopped early after {$consecutive_failure_count} consecutive GameBanana lookup failures. Last failure: '{$campaign->name}' ({$campaign->url}) - {$probe['message']}";
        echo $stop_reason . "\n";
        log_error($stop_reason, $topic);
        break;
      }
    }

    if ($checked_count % 25 === 0 || $checked_count === $total_campaigns) {
      echo "[{$counter}/{$total_campaigns}]         {$unavailable_count} unavailable, {$error_count} errors\n";
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
  $api_url = gamebanana_api_url($item_type, $item_id, '_idRow,_bIsTrashed,_bIsWithheld,_bIsPrivate');

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

  if ($http_code === 404) {
    return [
      'status' => 'unavailable',
      'message' => 'Item not found on GameBanana',
    ];
  }

  if ($http_code >= 400) {
    return [
      'status' => 'error',
      'message' => "GameBanana returned HTTP {$http_code}",
    ];
  }

  $decoded = json_decode($body, true);
  if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
    return [
      'status' => 'error',
      'message' => 'Failed to decode GameBanana response: ' . json_last_error_msg(),
    ];
  }

  if (!is_array($decoded) || !isset($decoded['_idRow'])) {
    return [
      'status' => 'error',
      'message' => 'Unexpected GameBanana response shape',
    ];
  }

  if (!empty($decoded['_bIsTrashed'])) {
    return [
      'status' => 'unavailable',
      'message' => 'Item is trashed on GameBanana',
    ];
  }

  if (!empty($decoded['_bIsPrivate'])) {
    return [
      'status' => 'unavailable',
      'message' => 'Item is private on GameBanana',
    ];
  }

  if (!empty($decoded['_bIsWithheld'])) {
    return [
      'status' => 'unavailable',
      'message' => 'Item is withheld on GameBanana',
    ];
  }

  return [
    'status' => 'available',
    'message' => 'OK',
  ];
}