<?php
declare(strict_types=1);

function remember_cookie_name(): string {
  return "portal_remember";
}

function create_remember_me(PDO $pdo, int $userId, bool $secureCookies): void {
  $selector = bin2hex(random_bytes(12)); // 24 chars
  $token = bin2hex(random_bytes(32));    // 64 chars
  $tokenHash = hash("sha256", $token);

  $expires = new DateTimeImmutable("+30 days");
  $expiresStr = $expires->format("Y-m-d H:i:s");

  $stmt = $pdo->prepare("INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)");
  $stmt->execute([$userId, $selector, $tokenHash, $expiresStr]);

  $cookieValue = $selector . ":" . $token;

  setcookie(remember_cookie_name(), $cookieValue, [
    "expires" => $expires->getTimestamp(),
    "path" => "/",
    "httponly" => true,
    "secure" => $secureCookies,
    "samesite" => "Lax",
  ]);
}

function clear_remember_me(PDO $pdo): void {
  $cookie = $_COOKIE[remember_cookie_name()] ?? "";
  if ($cookie && str_contains($cookie, ":")) {
    [$selector] = explode(":", $cookie, 2);
    $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE selector = ?");
    $stmt->execute([$selector]);
  }

  setcookie(remember_cookie_name(), "", [
    "expires" => time() - 3600,
    "path" => "/",
    "httponly" => true,
    "secure" => false,
    "samesite" => "Lax",
  ]);
}

function revoke_all_remember_tokens(PDO $pdo, int $userId): void {
  $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
  $stmt->execute([$userId]);

  // Also clear current browser cookie
  setcookie(remember_cookie_name(), "", [
    "expires" => time() - 3600,
    "path" => "/",
    "httponly" => true,
    "secure" => false,
    "samesite" => "Lax",
  ]);
}

function try_auto_login(PDO $pdo, bool $secureCookies): void {
  if (!empty($_SESSION["user_id"])) return;

  $cookie = $_COOKIE[remember_cookie_name()] ?? "";
  if (!$cookie || !str_contains($cookie, ":")) return;

  [$selector, $token] = explode(":", $cookie, 2);
  if (!$selector || !$token) return;

  $stmt = $pdo->prepare("
    SELECT rt.user_id, rt.token_hash, rt.expires_at, u.username, u.role
    FROM remember_tokens rt
    JOIN users u ON u.id = rt.user_id
    WHERE rt.selector = ?
    LIMIT 1
  ");
  $stmt->execute([$selector]);
  $row = $stmt->fetch();

  if (!$row) return;

  // Expired?
  if (strtotime($row["expires_at"]) < time()) {
    $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE selector = ?");
    $stmt->execute([$selector]);
    return;
  }

  $incomingHash = hash("sha256", $token);

  // Constant-time compare
  if (!hash_equals($row["token_hash"], $incomingHash)) {
    // Possible theft attempt: revoke this token
    $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE selector = ?");
    $stmt->execute([$selector]);
    return;
  }

  // Success: log in
  session_regenerate_id(true);
  $_SESSION["user_id"] = (int)$row["user_id"];
  $_SESSION["username"] = $row["username"];
  $_SESSION["role"] = $row["role"];

  // ✅ ROTATE token (delete old + issue new)
  try {
    $pdo->beginTransaction();

    $del = $pdo->prepare("DELETE FROM remember_tokens WHERE selector = ?");
    $del->execute([$selector]);

    create_remember_me($pdo, (int)$row["user_id"], $secureCookies);

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // If rotation fails, user is still logged in via session — that's fine.
  }
}