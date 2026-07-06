<?php

$requestId = bin2hex(random_bytes(4));
header("X-Request-ID: $requestId");

define("GB_ROOT_LOCAL", dirname(__FILE__));

require_once(GB_ROOT_LOCAL . "/include_bootstrap/config.php");
require_once(GB_ROOT_LOCAL . "/include_bootstrap/DB.php");
require_once(GB_ROOT_LOCAL . "/include_bootstrap/profiler.php");

$requireObjects = array(
  "dbobject",
  "campaign",
  "challenge",
  "difficulty",
  "map",
  "objective",
  "player",
  "submission",
  "account",
  "session",
  "logging",
  "change",
  "newchallenge",
  "suggestion",
  "suggestion_vote",
  "showcase",
  "like",
  "verification_notice",
  "server_settings",
  "post",
  "badge",
  "badge_player",
  "stamp_submission",
);

foreach ($requireObjects as $obj) {
  require_once(GB_ROOT_LOCAL . "/include_bootstrap/objects/{$obj}.php");
}

require_once(GB_ROOT_LOCAL . "/include_bootstrap/util.php");
require_once(GB_ROOT_LOCAL . "/include_bootstrap/gamebanana.php");
require_once(GB_ROOT_LOCAL . "/include_bootstrap/logging.php");
require_once(GB_ROOT_LOCAL . "/include_bootstrap/session.php");
require_once(GB_ROOT_LOCAL . "/include_bootstrap/discord_webhook.php");
require_once(GB_ROOT_LOCAL . "/include_bootstrap/embed_manage.php");
require_once(GB_ROOT_LOCAL . "/include_bootstrap/campaign_data_index.php");


// Initialize folders
$directories = [
  __DIR__ . '/cache/campaign_data',
  __DIR__ . '/cache/campaign_data_temp',
  __DIR__ . '/temp/campaign_data',
  __DIR__ . '/temp/processing_locks',
  __DIR__ . '/embed/img/cache',
  __DIR__ . '/embed/img/campaign-collage',
  __DIR__ . '/embed/img/submission',
];

foreach ($directories as $dir) {
  ensureDirectory($dir);
}


/**
 * Error handler, passes flow over the exception logger with new ErrorException.
 */
// function log_error_state($num, $str, $file, $line, $context = null)
// {
//   log_exception(new ErrorException($str, 0, $num, $file, $line));
// }

error_log("[START][$requestId] " . $_SERVER['REQUEST_URI']);

/**
 * Uncaught exception handler.
 */
function log_exception($e)
{
  //$e is some kind of Exception or Error
  $message = "";

  if ($e instanceof Error) {
    $message = "Type: " . get_class($e) . "; Message: {$e->getMessage()}; File: {$e->getFile()}; Line: {$e->getLine()};";
    $message = "[Error] " . $message;
    error_log($message);
    log_error($message, "Server Error");
    http_response_code(500);
    exit();
  } else if ($e instanceof Exception) {
    $message = "Type: " . get_class($e) . "; Message: {$e->getMessage()}; File: {$e->getFile()}; Line: {$e->getLine()};";
    $message = "[Exception] " . $message;
    error_log($message);
    log_error($message, "Server Error");
    http_response_code(500);
    exit();
  }
}

// set_error_handler("log_error_state");
set_exception_handler("log_exception");

/**
 * Checks for a fatal error, work around for set_error_handler not working on fatal errors.
 */
register_shutdown_function(function () {
  global $requestId;
  error_log(
    "[STATUS][$requestId] " . http_response_code()
  );
  error_log(
    "[HEADERS][$requestId] " .
    json_encode(headers_list())
  );
  $error = error_get_last();
  if ($error !== null) {
    error_log("[LAST_ERROR][$requestId] " . json_encode($error));
  }
  error_log("[END][$requestId]");
});


function ensureDirectory(string $path, int $mode = 0775): void
{
  if (is_dir($path)) {
    return;
  }

  if (!mkdir($path, $mode, true) && !is_dir($path)) {
    throw new RuntimeException("Failed to create directory: {$path}");
  }

  // Ensure the permissions are correct even if the current umask restricted them.
  chmod($path, $mode);
}