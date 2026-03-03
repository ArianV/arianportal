<?php
declare(strict_types=1);

function require_login(): void {
  if (empty($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
  }
}

function is_logged_in(): bool {
  return !empty($_SESSION["user_id"]);
}

function require_admin(): void {
  require_login();
  if (($_SESSION["role"] ?? "") !== "admin") {
    http_response_code(403);
    echo "Forbidden";
    exit;
  }
}