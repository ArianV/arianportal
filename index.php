<?php
require __DIR__ . "/config.php";
require __DIR__ . "/auth.php";

if (is_logged_in()) {
  header("Location: dashboard");
} else {
  header("Location: login");
}
exit;