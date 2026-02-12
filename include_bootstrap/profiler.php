<?php

$PROFILER_DATA = [];
$PROFILER_TIMER = null;
$PROFILER_DEPTH = 0;

/**
 * Start the profiler. Records the start timestamp.
 */
function profiler_start()
{
  global $PROFILER_DATA, $PROFILER_TIMER, $PROFILER_DEPTH;
  $PROFILER_TIMER = get_current_time_ms();
  $PROFILER_DEPTH = 0;
  $PROFILER_DATA = [
    'start' => $PROFILER_TIMER,
    'flat_steps' => []
  ];
}

/**
 * Record a profiler step. Appends to a flat sequential list with the current depth.
 * 
 * @param string|null $name Name of the step (auto-generated if null)
 * @param bool $has_substeps If true, subsequent steps will be recorded as substeps until profiler_close_substeps() is called
 * @param mixed $data Optional arbitrary data to attach to the step
 */
function profiler_step($name = null, $has_substeps = false, $data = null)
{
  global $PROFILER_DATA, $PROFILER_DEPTH;
  if (!isset($PROFILER_DATA['start'])) {
    profiler_start();
    return;
  }

  $diff = get_time_diff();

  $num_steps = count($PROFILER_DATA['flat_steps']);
  $entry = [
    'name' => $name ?? "Step $num_steps",
    'depth' => $PROFILER_DEPTH,
    'time' => $diff
  ];
  if ($data !== null) {
    $entry['data'] = $data;
  }
  $PROFILER_DATA['flat_steps'][] = $entry;

  if ($has_substeps) {
    $PROFILER_DEPTH++;
  }
}

/**
 * Close the current substep level, decrementing the internal depth.
 * Optionally records one final substep at the current depth before closing.
 * 
 * @param string|null $name If provided, creates a final substep with this name before closing
 * @param mixed $data Optional arbitrary data to attach to the final substep
 */
function profiler_close_substeps($name = null, $data = null)
{
  global $PROFILER_DEPTH;

  if ($name !== null) {
    profiler_step($name, false, $data);
  }

  if ($PROFILER_DEPTH > 0) {
    $PROFILER_DEPTH--;
  }
}

/**
 * End the profiler and calculate all statistics.
 * Builds the step/substep hierarchy from the flat list, then calculates stats.
 * 
 * @param array|null $output If provided, profiler data will be added to this array under 'profiler' key
 */
function profiler_end(&$output = null)
{
  global $PROFILER_DATA;
  $PROFILER_DATA['time'] = get_current_time_ms() - $PROFILER_DATA['start'];

  $PROFILER_DATA['steps'] = profiler_build_hierarchy($PROFILER_DATA['flat_steps']);
  profiler_calculate_stats($PROFILER_DATA['steps']);

  // Unset flat_steps
  unset($PROFILER_DATA['flat_steps']);

  if ($output !== null) {
    $output['profiler'] = $PROFILER_DATA;
  }
}

/**
 * Build a nested step/substep hierarchy from the flat sequential list.
 * Steps with a deeper depth than the previous step become substeps of that step.
 * 
 * @param array $flat_steps The flat sequential list of steps with depth values
 * @return array The nested hierarchy of steps
 */
function profiler_build_hierarchy($flat_steps)
{
  if (empty($flat_steps)) {
    return [];
  }

  $root = [];
  // Stack of references to the 'steps' arrays at each depth level
  // $stack[0] = &root, $stack[1] = &(last depth-0 step's 'steps'), etc.
  $stack = [&$root];

  foreach ($flat_steps as $entry) {
    $depth = $entry['depth'];

    $step = [
      'name' => $entry['name'],
      'time' => $entry['time']
    ];
    if (isset($entry['data'])) {
      $step['data'] = $entry['data'];
    }

    // Trim the stack to the target depth + 1 (so $stack[$depth] exists)
    // This handles cases where depth decreases
    $stack = array_slice($stack, 0, $depth + 1);

    // Ensure the target level exists in the stack
    if (!isset($stack[$depth])) {
      // This shouldn't happen in well-formed input, but handle gracefully
      $stack[$depth] = &$root;
    }

    // Append the step at the correct level
    $stack[$depth][] = $step;

    // Get a reference to the newly added step and prepare its substeps slot
    $new_step = &$stack[$depth][count($stack[$depth]) - 1];
    $new_step['steps'] = [];
    // Push reference to the new step's substeps array for the next depth level
    $stack[$depth + 1] = &$new_step['steps'];
    unset($new_step);
  }

  // Clean up empty 'steps' arrays
  profiler_cleanup_empty_steps($root);

  return $root;
}

/**
 * Recursively remove empty 'steps' arrays from the hierarchy.
 * 
 * @param array $steps Reference to steps array to clean up
 */
function profiler_cleanup_empty_steps(&$steps)
{
  foreach ($steps as &$step) {
    if (isset($step['steps'])) {
      if (empty($step['steps'])) {
        unset($step['steps']);
      } else {
        profiler_cleanup_empty_steps($step['steps']);
      }
    }
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
    if (isset($step['steps']) && !empty($step['steps'])) {
      $step['own_time'] = $step['time'];
      $step['substeps_total'] = profiler_calculate_stats($step['steps']);
      $step['time'] = $step['own_time'] + $step['substeps_total'];
    }

    $level_total += $step['time'];
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