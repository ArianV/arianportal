<?php
require __DIR__ . "/config.php";
require __DIR__ . "/auth.php";

require_login();

require_once __DIR__ . "/remember.php";

// Revoke all remember tokens for this user (all devices/browsers)
revoke_all_remember_tokens($pdo, (int)$_SESSION["user_id"]);

// Now log out this session too
$_SESSION = [];
if (ini_get("session.use_cookies")) {
  $params = session_get_cookie_params();
  setcookie(session_name(), "", time() - 42000,
    $params["path"], $params["domain"], $params["secure"], $params["httponly"]
  );
}
session_destroy();

header("Location: login.php");
exit;