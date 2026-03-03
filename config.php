<?php
declare(strict_types=1);

// For localhost dev, secure=false is OK.
// In production (Railway + HTTPS), set secure=true.
$secureCookies = false;

session_set_cookie_params([
  "httponly" => true,
  "secure" => $secureCookies,
  "samesite" => "Lax",
]);

session_start();

// XAMPP defaults:
$dbHost = $_ENV["DB_HOST"] ?? "db.jskvgcvpvgwsneeohaow.supabase.co";
$dbName = $_ENV["DB_NAME"] ?? "arian_admin";
$dbUser = $_ENV["DB_USER"] ?? "postgres";
$dbPass = $_ENV["DB_PASS"] ?? "ArianVahdat03!";

$dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";

try {
  $pdo = new PDO($dsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (PDOException $e) {
  http_response_code(500);
  echo "Database connection failed.";
  exit;
}

require_once __DIR__ . "/remember.php";
try_auto_login($pdo, $secureCookies);