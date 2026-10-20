<?php

require_once('../api_bootstrap.inc.php');

// Truncates the ingame_status table and fills it with test data covering all display cases of the Online page.
// All dates are relative to NOW(), so re-run this whenever the data has dropped off (after 5 minutes).

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_access($account, false);
if (!is_admin($account)) {
  die_json(403, "Not authorized");
}

header('Content-Type: text/plain');

#region Load challenges
// One map challenge per difficulty, hardest first
$query = "SELECT DISTINCT ON (difficulty.sort) challenge.id
  FROM challenge JOIN difficulty ON difficulty.id = challenge.difficulty_id
  WHERE challenge.map_id IS NOT NULL AND challenge.is_rejected = false
  ORDER BY difficulty.sort DESC, challenge.id
  LIMIT 4";
$result = pg_query_params_or_die($DB, $query, []);
$map_challenges = array_map(fn($row) => intval($row['id']), pg_fetch_all($result) ?: []);
if (count($map_challenges) < 4) {
  die_json(500, "Need at least 4 map challenges with different difficulties");
}

// A full game challenge (no map), so the campaign banner and campaign-only display are covered
$result = pg_query_params_or_die($DB, "SELECT id FROM challenge WHERE map_id IS NULL AND campaign_id IS NOT NULL AND is_rejected = false ORDER BY id LIMIT 1", []);
$row = pg_fetch_assoc($result);
$fullgame_challenge = $row !== false ? intval($row['id']) : $map_challenges[3];
#endregion

#region Build scenarios
$scenarios = [];

// Golden run grind: practice with an off-path detour, deaths, a reset, heartbeats, session PB != overall PB.
// Currently in a golden run (golden line on the graph)
$s = new TestScenario("Golden run grind (deaths, reset, off-path detour, heartbeats, in golden run)", ['challenge_id' => $map_challenges[0], 'max_room_number' => 40], null, 30);
$s->practice([5, 6, 6, 7]);
$s->off_path('lvl-07-secret', false);
$s->practice([8]);
$s->run(1, 12, 'death');
$s->status(1, true);
$s->run(2, 21, 'death');
$s->practice([22, 23, 23, 24]);
$s->run(1, 8, 'reset');
$s->run(1, 17);
$s->heartbeat(2);
$scenarios[] = $s->finish(15);

// Win followed by exit: row shows the win styling with the last real status, no exit marker on the graph
$s = new TestScenario("Win + exit (win styling, last real status shown)", ['challenge_id' => $map_challenges[1], 'max_room_number' => 25], 20, 20);
$s->run(1, 9, 'death');
$s->run(1, 25, 'win');
$s->event('exit', 25, false);
$scenarios[] = $s->finish(60);

// Win, still in the map
$s = new TestScenario("Win, still in map (win styling)", ['challenge_id' => $map_challenges[3], 'max_room_number' => 20], 14, 14);
$s->run(1, 14, 'death');
$s->run(1, 20, 'win');
$scenarios[] = $s->finish(25);

// Win + exit, then continued playing another map: back to a normal row
$s = new TestScenario("Win + exit, then entered another map (normal row again)", ['challenge_id' => $map_challenges[1], 'max_room_number' => 25], 25, 25);
$s->run(1, 25, 'win');
$s->event('exit', 25, false);
$s->switch(['challenge_id' => $map_challenges[2], 'max_room_number' => 30], null, 11);
$s->practice([1, 2, 3, 4]);
$scenarios[] = $s->finish(35);

// Unknown map: after a debug teleport reset the player is in an off-path room and hasn't been on the path this run
$s = new TestScenario("Unknown map, reset via debug teleport, off path without room number", ['map_name' => "My Cool WIP Map", 'max_room_number' => 12], null, 7);
$s->practice([1, 2, 3]);
$s->run(1, 6, 'reset');
$s->off_path('debug-lobby', false);
$s->off_path('debug-lobby-2', false);
$scenarios[] = $s->finish(40);

// Unknown map without a path: no room numbers, only debug room names
$s = new TestScenario("Unknown map without path (only room names)", ['map_name' => "Some Random Map Without A Path", 'max_room_number' => null]);
$s->off_path('a-00', false);
$s->off_path('a-01', false);
$s->off_path('b-00', false);
$scenarios[] = $s->finish(90);

// Exited the map without a win: "In overworld", graph still shows the last challenge with an exit marker
$s = new TestScenario("Exit without win (In overworld)", ['challenge_id' => $map_challenges[2], 'max_room_number' => 30], null, 18);
$s->run(1, 14, 'death');
$s->run(1, 5);
$s->event('exit', 5, true);
$scenarios[] = $s->finish(120);

// Switched challenge: older statuses on another challenge, now practicing a full game challenge, in an off-path room
// with the room number of the last visited on-path room
$s = new TestScenario("Switched challenge, full game challenge, off path with last room number", ['challenge_id' => $map_challenges[3], 'max_room_number' => 20], 10, 15);
$s->run(1, 10, 'death');
$s->practice([11, 12]);
$s->switch(['challenge_id' => $fullgame_challenge, 'max_room_number' => 150], null, 80);
$s->practice([40, 41, 42, 42, 43]);
$s->off_path('secret-room', false);
$scenarios[] = $s->finish(30);

// Death as the very first entry (no previous point to attach the marker to)
$s = new TestScenario("Death as first entry (edge case)", ['challenge_id' => $map_challenges[0], 'max_room_number' => 40], 9, 30);
$s->event('death', 9, true);
$s->status(1, false);
$s->status(2, false);
$scenarios[] = $s->finish(10);

// Single status without any PBs, on a path without custom room names
$s = new TestScenario("Single status, no PBs, on path without custom room name", ['challenge_id' => $map_challenges[1], 'max_room_number' => 25]);
$s->status(3, false, ['room_name' => 'a-03', 'custom_room_name' => null, 'debug_room_name' => 'a-03']);
$scenarios[] = $s->finish(5);

// Status without challenge and map (old way of reporting the menu): "In overworld"
$s = new TestScenario("Status without location (In overworld)", ['max_room_number' => null]);
$s->status(null, false);
$scenarios[] = $s->finish(150);

// Long session (>300 entries): tests the long session history mode, which fetches 300 instead of 100 updates.
// Grind with increasingly longer runs, currently in a golden run that started after some practice
$s = new TestScenario("Long session (>300 entries, for long session history mode)", ['challenge_id' => $map_challenges[0], 'max_room_number' => 40], null, 35);
grind_session($s, 20, 40);
$s->practice([14, 15, 15, 16]);
$s->run(1, 9);
$scenarios[] = $s->finish(20);

// Medium session (100 < x < 200 entries): the normal mode cuts off the oldest updates, the long mode shows all of them
$s = new TestScenario("Medium session (100-200 entries)", ['challenge_id' => $map_challenges[2], 'max_room_number' => 30], null, 22);
grind_session($s, 9, 30);
$s->practice([3, 4, 5]);
$scenarios[] = $s->finish(45);

// Stale: last activity 8 minutes ago, should NOT show up on the Online page
$s = new TestScenario("Stale (8 minutes ago, should NOT be shown)", ['challenge_id' => $map_challenges[2], 'max_room_number' => 30]);
$s->practice([3, 4, 5]);
$scenarios[] = $s->finish(8 * 60);
#endregion

#region Load accounts
// The current admin first (if they have a player), then accounts with a stream URL, so that the stream link is visible too
$query = "SELECT account.id, player.name, account.stream_url
  FROM account JOIN player ON player.id = account.player_id
  WHERE account.is_suspended = false
  ORDER BY (account.id = $1) DESC, (account.stream_url IS NOT NULL) DESC, account.id
  LIMIT $2";
$result = pg_query_params_or_die($DB, $query, [$account->id, count($scenarios)]);
$accounts = pg_fetch_all($result) ?: [];
if (count($accounts) < count($scenarios)) {
  die_json(500, "Need at least " . count($scenarios) . " non-suspended accounts with a player");
}
#endregion

#region Insert
pg_query_params_or_die($DB, "BEGIN", []);
pg_query_params_or_die($DB, "TRUNCATE ingame_status RESTART IDENTITY", []);

$insert_query = "INSERT INTO ingame_status
  (account_id, date, event, challenge_id, map_name, room_number, max_room_number, room_name, custom_room_name, debug_room_name, in_golden_run, session_pb_room_number, overall_pb_room_number)
  VALUES ($1, NOW() - make_interval(secs => $2), $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13)";

$total = 0;
foreach ($scenarios as $i => $scenario) {
  $target = $accounts[$i];
  foreach ($scenario->rows as $row) {
    pg_query_params_or_die($DB, $insert_query, [
      $target['id'],
      $row['seconds_ago'],
      $row['event'],
      $row['challenge_id'],
      $row['map_name'],
      $row['room_number'],
      $row['max_room_number'],
      $row['room_name'],
      $row['custom_room_name'],
      $row['debug_room_name'],
      $row['in_golden_run'] ? 't' : 'f',
      $row['session_pb_room_number'],
      $row['overall_pb_room_number'],
    ]);
    $total++;
  }
  $stream = $target['stream_url'] !== null ? " [stream]" : "";
  echo str_pad("{$target['name']}{$stream}", 32) . " " . str_pad(count($scenario->rows) . " entries", 12) . " {$scenario->name}\n";
}

pg_query_params_or_die($DB, "COMMIT", []);
echo "\nInserted {$total} ingame statuses. Re-run this script to refresh the data.\n";
#endregion


#region Utility Functions
// Alternates practice segments and golden runs that get longer over time. Each iteration adds roughly 12-20 entries
function grind_session(TestScenario $s, int $iterations, int $max_room)
{
  for ($i = 0; $i < $iterations; $i++) {
    $practice_start = min($max_room - 5, 4 + $i);
    $s->practice(range($practice_start, $practice_start + 5));
    if ($i % 4 === 3) {
      $s->heartbeat(1);
    }
    $s->run(1, min($max_room - 1, 4 + $i), $i % 5 === 4 ? 'reset' : 'death');
  }
}
#endregion

#region Utility Classes
// Builds a chronological list of statuses. Time moves forward while building, finish() then converts it into "seconds ago"
class TestScenario
{
  public string $name;
  public array $rows = [];
  private array $location;
  private ?int $session_pb;
  private ?int $overall_pb;
  private int $elapsed = 0;
  private ?int $last_path_room = null; // Last visited on-path room of the current run

  private const STATUS_INTERVAL = 25;
  private const EVENT_DELAY = 3;
  private const RESPAWN_DELAY = 8;
  private const HEARTBEAT_INTERVAL = 120;

  function __construct(string $name, array $location, ?int $session_pb = null, ?int $overall_pb = null)
  {
    $this->name = $name;
    $this->location = $location;
    $this->session_pb = $session_pb;
    $this->overall_pb = $overall_pb;
  }

  function status(?int $room, bool $golden, array $overrides = [])
  {
    $this->push('status', $room, $golden, $overrides);
    $this->elapsed += self::STATUS_INTERVAL;
  }

  // Room that isn't on the path: room_number stays at the last visited on-path room of the run (or null), only the
  // debug room name is sent
  function off_path(string $debug_room_name, bool $golden)
  {
    $this->push('status', $this->last_path_room, $golden, ['room_name' => null, 'custom_room_name' => null, 'debug_room_name' => $debug_room_name]);
    $this->elapsed += self::STATUS_INTERVAL;
  }

  function event(string $event, ?int $room, bool $golden)
  {
    // Events happen shortly after the previous entry, not a full interval later
    $last = end($this->rows);
    if ($last !== false) {
      $this->elapsed = $last['elapsed'] + self::EVENT_DELAY;
    }
    $this->push($event, $room, $golden);
    $this->elapsed += self::RESPAWN_DELAY;
    if (in_array($event, ['death', 'reset', 'exit'], true)) {
      $this->last_path_room = null;
    }
  }

  function practice(array $rooms)
  {
    foreach ($rooms as $room) {
      $this->status($room, false);
    }
  }

  // Golden run from room $from to $to, optionally ending with an event in the last room
  function run(int $from, int $to, ?string $end_event = null)
  {
    for ($room = $from; $room <= $to; $room++) {
      if ($this->session_pb === null || $room > $this->session_pb) {
        $this->session_pb = $room;
      }
      if ($this->overall_pb !== null && $this->session_pb > $this->overall_pb) {
        $this->overall_pb = $this->session_pb;
      }
      $this->status($room, true);
    }
    if ($end_event !== null) {
      $this->event($end_event, $to, true);
    }
  }

  // Repeats the last status $count times in heartbeat intervals
  function heartbeat(int $count)
  {
    $last = end($this->rows);
    for ($i = 0; $i < $count; $i++) {
      $this->elapsed += self::HEARTBEAT_INTERVAL - self::STATUS_INTERVAL;
      $this->status($last['room_number'], $last['in_golden_run'], ['room_number' => $last['room_number'], 'room_name' => $last['room_name'], 'custom_room_name' => $last['custom_room_name'], 'debug_room_name' => $last['debug_room_name']]);
    }
  }

  function switch(array $location, ?int $session_pb, ?int $overall_pb)
  {
    $this->location = $location;
    $this->session_pb = $session_pb;
    $this->overall_pb = $overall_pb;
    $this->last_path_room = null;
    $this->elapsed += 60;
  }

  // Shifts all entries so that the newest one is $latest_seconds_ago old
  function finish(int $latest_seconds_ago): TestScenario
  {
    $last_elapsed = end($this->rows)['elapsed'];
    foreach ($this->rows as &$row) {
      $row['seconds_ago'] = $latest_seconds_ago + ($last_elapsed - $row['elapsed']);
    }
    unset($row);
    return $this;
  }

  private function push(string $event, ?int $room, bool $golden, array $overrides = [])
  {
    $has_path = ($this->location['max_room_number'] ?? null) !== null;
    $on_path = $has_path && $room !== null;
    if ($on_path && ($overrides['room_name'] ?? '') !== null) {
      $this->last_path_room = $room;
    }
    $this->rows[] = array_merge([
      'elapsed' => $this->elapsed,
      'event' => $event,
      'challenge_id' => $this->location['challenge_id'] ?? null,
      'map_name' => $this->location['map_name'] ?? null,
      'room_number' => $on_path ? $room : null,
      'max_room_number' => $this->location['max_room_number'] ?? null,
      'room_name' => $on_path ? sprintf("lvl-%02d", $room) : null,
      'custom_room_name' => $on_path ? self::custom_room_name($room) : null,
      'debug_room_name' => $on_path ? sprintf("lvl-%02d", $room) : null,
      'in_golden_run' => $golden,
      'session_pb_room_number' => $has_path ? $this->session_pb : null,
      'overall_pb_room_number' => $has_path ? $this->overall_pb : null,
    ], $overrides);
  }

  private static function custom_room_name(int $room): string
  {
    $checkpoint = intdiv($room - 1, 10);
    $prefix = $checkpoint === 0 ? "Start" : "CP{$checkpoint}";
    return $prefix . "-" . (($room - 1) % 10 + 1);
  }
}
#endregion
