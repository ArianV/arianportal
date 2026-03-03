<?php
require __DIR__ . "/config.php";
require __DIR__ . "/auth.php";

require_login();

$username = $_SESSION["username"] ?? "User";
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Dashboard</title>
</head>
<body>
    <?php $pageTitle = "Dashboard"; require __DIR__ . "/partials/top.php"; ?>
    
    <div class="wrap">
      <div class="card" style="width:min(1020px,100%); max-width: 550px;">
        <div class="navbar">
          <div class="brand"><span class="dot"></span> ARIAN // PORTAL</div>
          <div class="actions">
            <a class="btn btn-ghost" href="logout.php">Logout</a>
          </div>
        </div>
    
        <div class="card-body">
          <div class="row" style="align-items:flex-end;">
            <div>
              <div class="h1" style="margin:0;">Welcome Back!</div>
              <p class="sub">Access Granted ✅</p>
            </div>
    
            <!-- Move this to SETTINGS page when created

            <form method="post" action="logout_all.php" onsubmit="return confirm('Log out everywhere?');">
              <button class="btn btn-ghost" type="submit">Log out of all devices</button>
            </form>
            
            -->
          </div>
    
          <div class="hr"></div>
    
          <div class="shell" style="grid-template-columns: 1fr 1fr;">

            <div class="tile">
              <div class="t">Finance</div>
              <div class="v">Upcoming Payments</div>
              <p class="sub" style="margin:10px 0 0;">Next Car Payment: ...</p>
              <p class="sub" style="margin:10px 0 0;">Next Insurance Payment: ...</p>
            </div>
    
            <div class="tile">
              <div class="t">ADMIN</div>
              <div class="v">Menu</div>

              <p class="sub" style="margin:10px 0 0;">
                <a href="savings.php">Savings</a>
              </p>
              <p class="sub" style="margin:10px 0 0;">
                <a href="payments.php">Payments</a>
              </p>

              <!-- Move this to SETTINGS page when created -->
              <?php if (($_SESSION["role"] ?? "") === "admin"): ?>
                <p class="sub" style="margin:10px 0 0;">
                  <a href="admin_create_user.php">Create user</a>
                </p>
              <?php endif; ?>
              <!-- -->
            </div>
          </div>
        </div>
      </div>
    </div>
            
    <?php require __DIR__ . "/partials/bottom.php"; ?>
</body>
</html>