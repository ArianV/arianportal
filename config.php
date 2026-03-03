<?php
declare(strict_types=1);

// Cookies: secure should be true on HTTPS (Railway), but for localhost it must be false
$isLocal = ($_SERVER['HTTP_HOST'] ?? '') === 'localhost' || str_starts_with($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1');
$secureCookies = $isLocal ? false : true;

session_set_cookie_params([
  "httponly" => true,
  "secure" => $secureCookies,
  "samesite" => "Lax",
]);

session_start();

// Supabase Postgres connection (use env vars in Railway)
$dbHost = $_ENV["DB_HOST"] ?? "db.jskvgcvpvgwsneeohaow.supabase.co";
$dbPort = (int)($_ENV["DB_PORT"] ?? 5432);
$dbName = $_ENV["DB_NAME"] ?? "postgres";
$dbUser = $_ENV["DB_USER"] ?? "postgres";
$dbPass = $_ENV["DB_PASS"] ?? "";

// IMPORTANT: Supabase requires SSL
$dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName};sslmode=require";

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