<?php

$PROFILER_DATA = [];
$PROFILER_TIMER = null;

/**
 * Start the profiler. Records the start timestamp.
 */
function profiler_start()
{
  global $PROFILER_DATA, $PROFILER_TIMER;
  $PROFILER_TIMER = get_current_time_ms();
  $PROFILER_DATA = [
    'start' => $PROFILER_TIMER,
    'steps' => []
  ];
}

/**
 * Record a profiler step. Only captures timing data - no calculations performed.
 * 
 * @param string|null $name Name of the step (auto-generated if null)
 * @param int $depth Nesting depth (0 = top level, 1 = substep of most recent depth-0 step, etc.)
 * @param mixed $data Optional arbitrary data to attach to the step
 */
function profiler_step($name = null, $depth = 0, $data = null)
{
  global $PROFILER_DATA;
  if (!isset($PROFILER_DATA['start'])) {
    profiler_start();
    return;
  }

  $diff = get_time_diff();

  // Navigate to the correct nesting level
  $level = &$PROFILER_DATA['steps'];
  for ($i = 0; $i < $depth; $i++) {
    $last_step = &$level[count($level) - 1];
    if (!isset($last_step['steps'])) {
      $last_step['steps'] = [];
    }
    $level = &$last_step['steps'];
  }

  // Record the step data
  $num_steps = count($level);
  $entry = [
    'name' => $name ?? "Step $num_steps",
    'time' => $diff
  ];
  if ($data !== null) {
    $entry['data'] = $data;
  }
  $level[] = $entry;
}

/**
 * End the profiler and calculate all statistics.
 * 
 * @param array|null $output If provided, profiler data will be added to this array under 'profiler' key
 */
function profiler_end(&$output = null)
{
  global $PROFILER_DATA;
  $PROFILER_DATA['time'] = get_current_time_ms() - $PROFILER_DATA['start'];

  profiler_calculate_stats($PROFILER_DATA['steps']);

  if ($output !== null) {
    $output['profiler'] = $PROFILER_DATA;
  }
}

/**
 * Recursively calculate statistics for all steps.
 * Currently calculates: substeps_total (sum of time spent in all nested substeps)
 * 
 * @param array $steps Reference to steps array to process
 * @return int Total time of all steps at this level (including their substeps)
 */
function profiler_calculate_stats(&$steps)
{
  if (empty($steps)) {
    return 0;
  }

  $level_total = 0;

  foreach ($steps as &$step) {
    $level_total += $step['time'];

    if (isset($step['steps']) && !empty($step['steps'])) {
      $step['substeps_total'] = profiler_calculate_stats($step['steps']);
      $level_total += $step['substeps_total'];
    }
  }

  return $level_total;
}
function profiler_get_data()
{
  global $PROFILER_DATA;
  return $PROFILER_DATA;
}

function get_current_time_ms()
{
  return floor(microtime(true) * 1000);
}
function get_time_diff()
{
  global $PROFILER_TIMER;
  $current_time_ms = get_current_time_ms();
  $diff = $current_time_ms - $PROFILER_TIMER;
  $PROFILER_TIMER = $current_time_ms;
  return $diff;
}