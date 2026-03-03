<?php
declare(strict_types=1);

function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function csrf_verify(): void {
  $sent = $_POST['csrf_token'] ?? '';
  $real = $_SESSION['csrf_token'] ?? '';
  if (!$sent || !$real || !hash_equals($real, $sent)) {
    http_response_code(403);
    echo "Invalid CSRF token.";
    exit;
  }
}