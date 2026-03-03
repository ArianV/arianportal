<?php
require __DIR__ . "/config.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $username = trim($_POST["username"] ?? "");
  $password = $_POST["password"] ?? "";

  if ($username === "" || $password === "") {
    $error = "Username and password are required.";
  } else {
    $stmt = $pdo->prepare("SELECT id, password_hash, role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user["password_hash"])) {
      $error = "Invalid username or password.";
    } else {
      // Session hardening
      session_regenerate_id(true);

      $_SESSION["user_id"] = $user["id"];
      $_SESSION["username"] = $username;
      $_SESSION["role"] = $user["role"];

      $secureCookies = true; // match config.php setting
      create_remember_me($pdo, (int)$user["id"], $secureCookies);

      header("Location: dashboard.php");
      exit;
    }
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Login</title>
</head>
<body>
    <?php $pageTitle = "Login"; require __DIR__ . "/partials/top.php"; ?>

    <div class="wrap">
      <div class="shell">
        <div class="card">
          <div class="card-head">
            <div class="brand"><span class="dot"></span> ARIAN // PORTAL</div>
            <div class="h1">Secure Login</div>
            <p class="sub">Authenticate to access your private dashboard.</p>
          </div>

          <div class="card-body">
            <?php if (!empty($error)): ?>
              <div class="alert bad"><?= htmlspecialchars($error) ?></div>
              <div class="hr"></div>
            <?php endif; ?>

            <form method="post" class="grid">
              <div>
                <label>Username</label>
                <input class="input" name="username" autocomplete="username" required>
              </div>

              <div>
                <label>Password</label>
                <input class="input" type="password" name="password" autocomplete="current-password" required>
              </div>

              <div class="row" style="margin-top: 10px;">
                <button class="btn" type="submit">Enter Portal</button>
              </div>
            </form>

          </div>
        </div>
      </div>
    </div>

    <?php require __DIR__ . "/partials/bottom.php"; ?>
</body>
</html>