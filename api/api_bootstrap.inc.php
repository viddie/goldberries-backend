<?php
require_once(__DIR__ . '/../bootstrap.inc.php');
require_once('api_functions.inc.php');

$DB = db_connect();

header('Content-Type: application/json; charset=UTF-8');

//CORS https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
//In the test environment, the frontend runs on localhost:3000 while the backend runs on localhost. This is a cross-origin request.
//In order to allow the test frontend to make credentialed requests, the access control allow origin header must have the correct value.
//Any other origin should not use the main domain's cookies, and thus will receive the wildcard value *, which does not allow credentials.
//Check if $_SERVER even has the HTTP_ORIGIN key, as it may not be set in some cases.
$http_origin = key_exists('HTTP_ORIGIN', $_SERVER) ? $_SERVER['HTTP_ORIGIN'] : null;
if ($http_origin === "http://localhost:3000") {
  header("Access-Control-Allow-Origin: http://localhost:3000");
  header('Access-Control-Allow-Credentials: true');
} else {
  header("Access-Control-Allow-Origin: *");
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: content-type, authorization');

if (key_exists('REQUEST_METHOD', $_SERVER) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

//CSRF protection: Browsers attach the session cookie no matter which site triggered a request, so state-changing requests from other origins are rejected.
//Browsers always send the Origin header on these methods, so a missing header means the request doesn't come from a website (e.g. a script).
//API key clients don't use the session cookie and aren't affected by this.
$allowed_origins = getenv('DEBUG') === 'true' ? ["http://localhost:3000", "http://localhost"] : [constant('BASE_URL')];
$is_safe_method = !key_exists('REQUEST_METHOD', $_SERVER) || in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true);
if (!$is_safe_method && isset($_COOKIE['token']) && $http_origin !== null && !in_array($http_origin, $allowed_origins, true)) {
  die_json(403, "Cross-site request rejected");
}