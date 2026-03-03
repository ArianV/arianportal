<?php
declare(strict_types=1);

// Secure cookies should be FALSE on localhost (no https), TRUE on Railway (https)
$hostHeader = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = ($hostHeader === 'localhost' || str_starts_with($hostHeader, '127.0.0.1'));
$secureCookies = $isLocal ? false : true;

session_set_cookie_params([
  "httponly" => true,
  "secure" => $secureCookies,
  "samesite" => "Lax",
]);

session_start();

$dbHost = $_ENV["DB_HOST"] ?? "db.jskvgcvpvgwsneeohaow.supabase.co";
$dbPort = (int)($_ENV["DB_PORT"] ?? 5432);
$dbName = $_ENV["DB_NAME"] ?? "postgres";
$dbUser = $_ENV["DB_USER"] ?? "postgres";
$dbPass = $_ENV["DB_PASS"] ?? "";

// Supabase requires SSL
$dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName};sslmode=require";

try {
  $pdo = new PDO($dsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (PDOException $e) {
  http_response_code(500);
  echo "Database connection failed: " . htmlspecialchars($e->getMessage());
  exit;
}

require_once __DIR__ . "/remember.php";
try_auto_login($pdo, $secureCookies);