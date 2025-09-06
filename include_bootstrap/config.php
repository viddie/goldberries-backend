<?php

#region General constants
DEFINE("ADMIN_EMAIL", "admin@domain.com");
DEFINE("NOREPLY_EMAIL", "noreply@goldberries.net");
DEFINE("DB_STRING", "host=localhost port=" . getenv("GB_DBPORT") . " dbname=" . getenv("GB_DBNAME") . " user=" . getenv("GB_DBUSER") . " password=" . getenv("GB_DBPASS"));
#endregion

#region Webhooks
DEFINE("SUGGESTION_BOX_WEBHOOK_URL", getenv("SUGGESTION_BOX_WEBHOOK_URL"));
DEFINE("CHANGELOG_WEBHOOK_URL", getenv("CHANGELOG_WEBHOOK_URL"));
DEFINE("NOTIFICATIONS_WEBHOOK_URL", getenv("NOTIFICATIONS_WEBHOOK_URL"));
DEFINE("POST_CHANGELOG_WEBHOOK_URL", getenv("POST_CHANGELOG_WEBHOOK_URL"));
DEFINE("POST_NEWS_WEBHOOK_URL", getenv("POST_NEWS_WEBHOOK_URL"));
DEFINE("REPORT_WEBHOOK_URL", getenv("REPORT_WEBHOOK_URL"));
DEFINE("MOD_REPORT_WEBHOOK_URL", getenv("MOD_REPORT_WEBHOOK_URL"));
#endregion

#region Paths
DEFINE("PYTHON_COMMAND", getenv("PYTHON_COMMAND"));
DEFINE("WKHTMLTOIMAGE_PATH", getenv("WKHTMLTOIMAGE_PATH")); //false if not set
#endregion

#region Difficulty constants
$TRIVIAL_ID = 20;
$UNDETERMINED_ID = 19;

$LOW_TIER_0_SORT = 17;
$LOW_TIER_3_SORT = 8;
$STANDARD_SORT_START = 1;
$STANDARD_SORT_END = 3;
$TIERED_SORT_START = 4;
$MAX_SORT = 20;
$MIN_SORT = -1;
$RAW_SESSION_REQUIRED_SORT = $LOW_TIER_3_SORT;
#endregion

#region URLs
if (getenv('DEBUG') === 'true') {
  DEFINE("BASE_URL", "http://localhost:3000");
  DEFINE("BASE_URL_API", "http://localhost/api");
  DEFINE("REGISTER_URL", "http://localhost:3000/register");
} else {
  DEFINE("BASE_URL", "https://goldberries.net");
  DEFINE("BASE_URL_API", "https://goldberries.net/api");
  DEFINE("REGISTER_URL", "https://goldberries.net/register");
}
#endregion

#region Session constants
DEFINE('DISCORD_CLIENT_ID', getenv('DISCORD_CLIENT_ID'));
DEFINE('DISCORD_CLIENT_SECRET', getenv('DISCORD_CLIENT_SECRET'));
DEFINE('DISCORD_TOKEN_URL', 'https://discord.com/api/oauth2/token');
DEFINE('DISCORD_API_URL', 'https://discord.com/api');
if (getenv('DEBUG') === 'true') {
  DEFINE('DISCORD_OAUTH_URL', 'https://discord.com/api/oauth2/authorize?client_id=1196814348203593729&response_type=code&redirect_uri=http%3A%2F%2Flocalhost%2Fapi%2Fauth%2Fdiscord_auth.php&scope=identify');
  DEFINE('DISCORD_REDIRECT_URI', 'http://localhost/api/auth/discord_auth.php');
  DEFINE('REDIRECT_POST_LOGIN', 'http://localhost:3000');
  DEFINE('REDIRECT_POST_LINK_ACCOUNT', 'http://localhost:3000/my-account');
} else {
  DEFINE('DISCORD_OAUTH_URL', 'https://discord.com/oauth2/authorize?client_id=1196814348203593729&response_type=code&redirect_uri=https%3A%2F%2Fgoldberries.net%2Fapi%2Fauth%2Fdiscord_auth.php&scope=identify');
  DEFINE('DISCORD_REDIRECT_URI', 'https://goldberries.net/api/auth/discord_auth.php');
  DEFINE('REDIRECT_POST_LOGIN', 'https://goldberries.net');
  DEFINE('REDIRECT_POST_LINK_ACCOUNT', 'https://goldberries.net/my-account');
}
#endregion

#region Roles
$USER = 0;
$EX_HELPER = 10;
$EX_VERIFIER = 11;
$EX_ADMIN = 12;
$NEWS_WRITER = 15;
$HELPER = 20;
$VERIFIER = 30;
$ADMIN = 40;

$VALID_ROLES = [$USER, $EX_HELPER, $EX_VERIFIER, $EX_ADMIN, $NEWS_WRITER, $HELPER, $VERIFIER, $ADMIN];
#endregion