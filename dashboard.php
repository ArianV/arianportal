<?php
require __DIR__ . "/config.php";
require __DIR__ . "/auth.php";

require_login();

$username = $_SESSION["username"] ?? "User";

function days_until(DateTimeImmutable $due): int {
  $today = new DateTimeImmutable("today");
  return (int)$today->diff($due)->format('%r%a');
}

function countdown_label(int $days): string {
  if ($days === 0) return "Due today";
  if ($days === 1) return "Due in 1 day";
  if ($days > 1) return "Due in {$days} days";
  $over = abs($days);
  if ($over === 1) return "OVERDUE by 1 day";
  return "OVERDUE by {$over} days";
}

function urgency_rank(int $days): int {
  if ($days < 0) return 0;      // overdue
  if ($days <= 7) return 1;     // due soon
  return 2;                     // ok
}

$userId = (int)($_SESSION["user_id"] ?? 0);

// Load all active bills
$stmt = $pdo->prepare("
  SELECT id, name, next_due_date
  FROM user_bills
  WHERE user_id = ? AND is_active = true
");
$stmt->execute([$userId]);
$bills = $stmt->fetchAll();

// Decorate + sort by urgency then due date
$billRows = [];
foreach ($bills as $b) {
  $due = new DateTimeImmutable((string)$b["next_due_date"]);
  $days = days_until($due);
  $billRows[] = [
    "name" => (string)$b["name"],
    "due_fmt" => $due->format("m/d/Y"),
    "days" => $days,
    "countdown" => countdown_label($days),
    "rank" => urgency_rank($days),
    "due_ts" => $due->getTimestamp(),
  ];
}

usort($billRows, function($a, $b) {
  if ($a["rank"] !== $b["rank"]) return $a["rank"] <=> $b["rank"];
  return $a["due_ts"] <=> $b["due_ts"];
});
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
              <?php if (count($billRows) === 0): ?>
                <p class="sub" style="margin:10px 0 0; opacity:.75;">
                  No bills yet. Add some in <a href="bills_manage">Manage Bills</a>.
                </p>
              <?php else: ?>
                <?php foreach ($billRows as $r): ?>
                  <p class="sub" style="margin:10px 0 0;">
                    <span style="font-weight:700; font-size: 16px;"><?= htmlspecialchars($r["name"]) ?>:</span>
                    <span style="font-family:var(--mono);"><?= htmlspecialchars($r["countdown"]) ?></span>
                    <span style="opacity:.7; color: #ff4040;">(<?= htmlspecialchars($r["due_fmt"]) ?>)</span>
                  </p>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
    
            <div class="tile">
              <div class="t">ADMIN</div>
              <div class="v">Menu</div>

              <p class="sub" style="margin:10px 0 0;">
                <a href="savings">Savings</a>
              </p>
              <p class="sub" style="margin:10px 0 0;">
                <a href="payments">Payments</a>
              </p>

              <!-- Move this to SETTINGS page when created -->
              <?php if (($_SESSION["role"] ?? "") === "admin"): ?>
                <p class="sub" style="margin:10px 0 0;">
                  <a href="admin_create_user">Create user</a>
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