<?php
require __DIR__ . "/config.php";
require __DIR__ . "/auth.php";

require_admin();

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $username = trim($_POST["username"] ?? "");
  $password = $_POST["password"] ?? "";
  $role = ($_POST["role"] ?? "user") === "admin" ? "admin" : "user";

  if ($username === "" || $password === "") {
    $error = "Username and password are required.";
  } elseif (strlen($username) < 2) {
    $error = "Username must be at least 2 characters.";
  } elseif (strlen($password) < 5) {
    $error = "Password must be at least 5 characters.";
  } else {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
      $error = "That username is not available.";
    } else {
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
      $stmt->execute([$username, $hash, $role]);
      $success = "User created.";
    }
  }
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Create User</title></head>
<body>
  <h1>Admin: Create Account</h1>

  <?php if ($error): ?><p style="color:red;"><?= htmlspecialchars($error) ?></p><?php endif; ?>
  <?php if ($success): ?><p style="color:green;"><?= htmlspecialchars($success) ?></p><?php endif; ?>

  <form method="post">
    <label>Username</label><br>
    <input name="username" required><br><br>

    <label>Password</label><br>
    <input type="password" name="password" required><br><br>

    <label>Role</label><br>
    <select name="role">
      <option value="user">user</option>
      <option value="admin">admin</option>
    </select><br><br>

    <button type="submit">Create</button>
  </form>

  <p><a href="dashboard">Back to dashboard</a></p>
</body>
</html>