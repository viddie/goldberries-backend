<?php

require_once('../api_bootstrap.inc.php');

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  fail_plain(405, 'Method Not Allowed');
}

$account = get_user_data();
check_role($account, $HELPER);

#region Input
$raw_input = <<<'TEXT'
7230 2026.05.01
2038 2026.04.26
2132 2026.05.27
2147 2026.03.10
2174 2025.12.11
6688 2026.05.29
2722 2026.04.10
6103 2026.02.15
2126 2025.11.14
2240 2026.02.01
2031 2026.04.11
2849 2026.04.05
2029 2026.04.12
3524 2026.02.17
2030 2026.04.05
2037 2026.04.03
5299 2025.12.04
3478 2025.06.30
2282 2026.01.28
3909 2025.11.20
4624 2026.02.07
2446 2025.11.03
2269 2025.12.09
4281 2026.01.29
8943 2026.02.06
3889 2025.12.18
4630 2026.02.07
4278 2026.01.30
4631 2026.02.08
4895 2025.12.03
2246 2026.01.30
8041 2025.12.18
2280 2026.01.10
2424 2026.01.26
2455 2025.05.22
2456 2026.01.21
2324 2025.12.08
1177 2025.12.14
2599 2025.12.07
1178 2025.12.13
1179 2025.12.13
534 2025.12.01
2316 2025.12.02
755 2025.12.16
6933 2025.08.31
2262 2025.12.04
2194 2025.12.01
2433 2025.11.01
3476 2025.07.01
2438 2025.11.22
870 2025.05.02
2441 2025.11.02
2444 2025.11.04
2440 2025.10.17
2436 2025.10.17
2721 2025.10.20
3636 2025.07.05
853 2025.07.05
861 2025.05.31
865 2025.07.05
868 2025.07.04
867 2025.06.01
2453 2025.10.16
2452 2025.10.10
2431 2025.05.24
2457 2025.06.14
2443 2025.05.31
2437 2025.06.12
1851 2025.05.25
2702 2025.05.13
2366 2024.08.07
TEXT;
#endregion

if (trim($raw_input) === '') {
  fail_plain(400, "No input data in source. Paste lines into \$raw_input in the format '<challenge_id> <YYYY.MM.DD>'.");
}

$player_id = 2609;
$lines = preg_split('/\r\n|\n|\r/', trim($raw_input));
$results = [];
$processed_lines = 0;
$updated_rows = 0;
$error_count = 0;

foreach ($lines as $index => $raw_line) {
  $line = trim($raw_line);
  if ($line === '') {
    continue;
  }

  $processed_lines++;
  $line_number = $index + 1;
  $parsed = parse_methanol_fix_line($line);

  if ($parsed['ok'] === false) {
    $error_count++;
    $results[] = [
      'line' => $line_number,
      'status' => 'error',
      'message' => $parsed['message'],
      'input' => $line,
    ];
    continue;
  }

  $challenge_id = $parsed['challenge_id'];
  $date_achieved = $parsed['date_achieved'];

  $exists_query = "SELECT id FROM submission WHERE player_id = $1 AND challenge_id = $2 AND submission.is_verified = TRUE";
  $exists_result = pg_query_params_or_die(
    $DB,
    $exists_query,
    [$player_id, $challenge_id],
    "Failed to check existing submission for challenge {$challenge_id}"
  );

  $submission_ids = [];
  while ($submission_row = pg_fetch_assoc($exists_result)) {
    $submission_ids[] = intval($submission_row['id']);
  }

  $exists_count = count($submission_ids);
  if ($exists_count !== 1) {
    $error_count++;
    $error_message = $exists_count === 0
      ? "Expected exactly 1 verified submission for player {$player_id} and challenge {$challenge_id}, found 0"
      : "Expected exactly 1 verified submission for player {$player_id} and challenge {$challenge_id}, found {$exists_count}";
    $results[] = [
      'line' => $line_number,
      'status' => 'error',
      'message' => $error_message,
      'challenge_id' => $challenge_id,
      'date_achieved' => $date_achieved,
    ];
    continue;
  }

  $submission_id = $submission_ids[0];

  $update_query = "UPDATE submission SET date_achieved = $1 WHERE id = $2";
  $update_result = pg_query_params_or_die(
    $DB,
    $update_query,
    [$date_achieved, $submission_id],
    "Failed to update submission #{$submission_id} date_achieved for challenge {$challenge_id}"
  );

  $affected_rows = pg_affected_rows($update_result);
  $updated_rows += $affected_rows;

  $results[] = [
    'line' => $line_number,
    'status' => 'ok',
    'message' => "Updated submission #{$submission_id} for challenge {$challenge_id}",
    'challenge_id' => $challenge_id,
    'submission_id' => $submission_id,
    'date_achieved' => $date_achieved,
  ];
}

echo "methanol-fix report\n";
echo "player_id: {$player_id}\n";
echo "processed_lines: {$processed_lines}\n";
echo "updated_rows: {$updated_rows}\n";
echo "error_count: {$error_count}\n\n";

foreach ($results as $result) {
  $line = $result['line'];
  $status = strtoupper($result['status']);
  $message = $result['message'];

  echo "[Line {$line}] {$status}: {$message}";

  if (isset($result['challenge_id'])) {
    echo " | challenge_id={$result['challenge_id']}";
  }
  if (isset($result['submission_id'])) {
    echo " | submission_id={$result['submission_id']}";
  }
  if (isset($result['date_achieved'])) {
    echo " | date_achieved={$result['date_achieved']}";
  }
  if (isset($result['input'])) {
    echo " | input={$result['input']}";
  }

  echo "\n";
}

#region Functions
function fail_plain($status_code, $message)
{
  http_response_code($status_code);
  echo $message . "\n";
  exit;
}

function parse_methanol_fix_line($line)
{
  $line = ltrim($line, "\xEF\xBB\xBF");

  if (!preg_match('/^(\d+)\s+(\d{4}\.\d{2}\.\d{2})$/', $line, $matches)) {
    return [
      'ok' => false,
      'message' => "Invalid format. Expected '<challenge_id> <YYYY.MM.DD>'",
    ];
  }

  $challenge_id = intval($matches[1]);
  if ($challenge_id <= 0) {
    return [
      'ok' => false,
      'message' => 'challenge_id must be a positive integer',
    ];
  }

  $date_human = $matches[2];
  $date_achieved = str_replace('.', '-', $date_human);
  $date_obj = DateTime::createFromFormat('Y-m-d', $date_achieved);

  if ($date_obj === false || $date_obj->format('Y-m-d') !== $date_achieved) {
    return [
      'ok' => false,
      'message' => "Invalid date '{$date_human}'",
    ];
  }

  return [
    'ok' => true,
    'challenge_id' => $challenge_id,
    'date_achieved' => $date_achieved,
  ];
}
#endregion
