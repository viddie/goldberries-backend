<?php

require_once('../api_bootstrap.inc.php');

$is_debug = getenv('DEBUG') === 'true';
$is_cli = php_sapi_name() === 'cli';

if (!$is_cli) {
  if ($is_debug) {
    header('Content-Type: text/plain; charset=UTF-8');
  } else {
    die_json(405, 'Method Not Allowed');
  }
}

#region Task Registry
// Each task: 'name' => ['fn' => 'function_name', 'type' => 'hourly|daily|weekly', 'file' => 'tasks/filename.php']
$tasks = [
  'aggregate_traffic' => ['fn' => 'task_aggregate_traffic', 'type' => 'weekly', 'file' => 'tasks/aggregate_traffic.php'],
  'report_gamebanana_urls' => ['fn' => 'task_report_gamebanana_urls', 'type' => 'weekly', 'file' => 'tasks/report_gamebanana_urls.php'],
];
#endregion

// Read type parameter from CLI args or $_REQUEST in debug mode
$type = null;
if ($is_debug) {
  $type = $_REQUEST['type'] ?? null;
}
if ($type === null && isset($argv[1])) {
  $type = $argv[1];
}

$valid_types = ['hourly', 'daily', 'weekly'];
if ($type === null || !in_array($type, $valid_types)) {
  echo "Usage: php run.php <hourly|daily|weekly>\n";
  exit(1);
}

// Load task files
foreach ($tasks as $name => $task) {
  require_once(__DIR__ . '/' . $task['file']);
}

// Filter tasks by type
$tasks_to_run = array_filter($tasks, function ($task) use ($type) {
  return $task['type'] === $type;
});

if (count($tasks_to_run) === 0) {
  echo "No {$type} tasks registered.\n";
  exit(0);
}

echo "=== Maintenance: {$type} tasks ===\n";
echo "Tasks to run: " . count($tasks_to_run) . "\n\n";

$total_start = microtime(true);
$task_index = 0;
$total_tasks = count($tasks_to_run);

foreach ($tasks_to_run as $name => $task) {
  $task_index++;
  echo "--- [{$task_index}/{$total_tasks}] {$name} ---\n";
  $task_start = microtime(true);

  $task['fn']($DB);

  $task_elapsed = round(microtime(true) - $task_start, 1);
  echo "--- {$name} done in {$task_elapsed}s ---\n\n";
}

$total_elapsed = round(microtime(true) - $total_start, 1);
echo "=== All {$type} tasks complete in {$total_elapsed}s ===\n";
