<?php
require __DIR__ . "/config.php";
require __DIR__ . "/auth.php";
require_login();

$userId = (int)$_SESSION["user_id"];
$error = "";
$success = "";

function flash_set(string $key, string $value): void {
  $_SESSION["flash_" . $key] = $value;
}

function flash_get(string $key): string {
  $k = "flash_" . $key;
  $v = $_SESSION[$k] ?? "";
  unset($_SESSION[$k]);
  return $v;
}

function period_from_due(DateTimeImmutable $due): string {
  return $due->format("Y-m"); // YYYY-MM
}

function due_date_for_month(int $year, int $month, int $dueDay): DateTimeImmutable {
  // dueDay 1..28 so always valid
  return new DateTimeImmutable(sprintf("%04d-%02d-%02d", $year, $month, $dueDay));
}

function default_next_due_date(int $dueDay): DateTimeImmutable {
  $today = new DateTimeImmutable("today");
  $y = (int)$today->format("Y");
  $m = (int)$today->format("n");
  $d = (int)$today->format("j");

  if ($d <= $dueDay) return due_date_for_month($y, $m, $dueDay);
  return due_date_for_month($y, $m, $dueDay)->modify("+1 month");
}

function next_month_due(DateTimeImmutable $due, int $dueDay): DateTimeImmutable {
  $next = $due->modify("+1 month");
  return due_date_for_month((int)$next->format("Y"), (int)$next->format("n"), $dueDay);
}

function days_until(DateTimeImmutable $due): int {
  $today = new DateTimeImmutable("today");
  // difference in whole days (due - today)
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

// Handle button press (dynamic bills)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  csrf_verify();

  $mode = $_POST["mode"] ?? "pay";  

  // Mark paid
  $billId = (int)($_POST["bill_id"] ?? 0);
  if ($billId <= 0) {
    flash_set("error", "Invalid bill.");
    header("Location: /payments");
    exit;
  }

  try {
    $pdo->beginTransaction();

    // Lock the bill row
    $stmt = $pdo->prepare("
      SELECT id, name, due_day, next_due_date
      FROM user_bills
      WHERE id = ? AND user_id = ? AND is_active = true
      FOR UPDATE
    ");
    $stmt->execute([$billId, $userId]);
    $bill = $stmt->fetch();

    if (!$bill) {
      $pdo->rollBack();
      flash_set("error", "Bill not found or disabled.");
      header("Location: /payments");
      exit;
    }

    $due = new DateTimeImmutable((string)$bill["next_due_date"]);
    $dueDay = (int)$bill["due_day"];
    $period = period_from_due($due);

    // Insert payment history (unique constraint prevents duplicates)
    $ins = $pdo->prepare("INSERT INTO user_bill_payments (user_id, bill_id, period) VALUES (?, ?, ?)");
    try {
      $ins->execute([$userId, $billId, $period]);
    } catch (PDOException $e) {
      if (($e->getCode() ?? "") === "23505") {
        $pdo->rollBack();
        flash_set("error", $bill["name"] . " already marked paid for {$period}.");
        header("Location: /payments");
        exit;
      }
      throw $e;
    }

    // Advance next due date to next month on the same due day
    $nextDue = next_month_due($due, $dueDay)->format("Y-m-d");

    $upd = $pdo->prepare("UPDATE user_bills SET next_due_date = ?, updated_at = now() WHERE id = ? AND user_id = ?");
    $upd->execute([$nextDue, $billId, $userId]);

    $pdo->commit();
    flash_set("success", $bill["name"] . " marked paid for {$period}. Next due: " . (new DateTimeImmutable($nextDue))->format("M j, Y") . ".");
    header("Location: /payments");
    exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = "Could not update payment status.";
  }
}

$success = $success ?: flash_get("success");
$error   = $error   ?: flash_get("error");

// Read bills
$billsStmt = $pdo->prepare("
  SELECT id, name, due_day, next_due_date
  FROM user_bills
  WHERE user_id = ? AND is_active = true
  ORDER BY next_due_date ASC, name ASC
");
$billsStmt->execute([$userId]);
$bills = $billsStmt->fetchAll();

// Recent payments
$histStmt = $pdo->prepare("
  SELECT b.name, p.period, p.paid_at
  FROM user_bill_payments p
  JOIN user_bills b ON b.id = p.bill_id
  WHERE p.user_id = ?
  ORDER BY p.paid_at DESC
  LIMIT 20
");
$histStmt->execute([$userId]);
$history = $histStmt->fetchAll();

$pageTitle = "Payments";
require __DIR__ . "/partials/top.php";
?>

<div class="wrap">
  <div class="card" style="width:min(980px,100%);">
    <div class="navbar">
      <div class="brand"><span class="dot"></span> ARIAN // PORTAL</div>
      <div class="actions">
        <a class="btn btn-ghost" href="/dashboard">Dashboard</a>
        <a class="btn btn-ghost" href="/logout">Logout</a>
      </div>
    </div>

    <div class="card-body">
      <div class="row" style="align-items:flex-end;">
        <div>
          <div class="h1" style="margin:0;">Payments</div>
          <p class="sub">Tap to mark the month paid. Next due auto-sets to the 20th.</p>
        </div>
        <a href="#"><span class="pill">Manage Bills</span></a>
      </div>

      <div class="hr"></div>

      <?php if ($error): ?>
        <div class="alert bad" id="statusBox"><?= htmlspecialchars($error) ?></div>
        <div class="hr"></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="alert good" id="statusBox"><?= htmlspecialchars($success) ?></div>
        <div class="hr"></div>
      <?php endif; ?>

      <style>
        @media (min-width: 550px){
          .shell { 
            grid-template-columns: 1fr 1fr;
        }
        }
      </style>

      <div class="shell" style="gap:16px; align-items:start; max-width: none;">
        <!-- Buttons -->
        <div class="tile">
          <div class="t">PAY THIS MONTH</div>
          <div class="sub" style="margin-top:8px;">Press to mark the current due period paid.</div>

          <div class="hr"></div>

          <?php if (count($bills) === 0): ?>
          <div class="sub">No bills yet. Add some in <a href="/bills_manage">Manage Bills</a>.</div>
          <?php else: ?>
          <?php foreach ($bills as $b):
            $due = new DateTimeImmutable((string)$b["next_due_date"]);
            $period = $due->format("Y-m");
          ?>

          <?php
          $due = new DateTimeImmutable((string)$b["next_due_date"]);
          $days = days_until($due);
          $countdown = countdown_label($days);
          $period = $due->format("Y-m");
          ?>

          <div class="tile" style="margin-bottom:12px;">
            <div class="row">
              <div>
                <div class="t">BILL</div>
                <div class="v"><?= htmlspecialchars($b["name"]) ?></div>
                <div class="sub" style="margin-top:6px; margin-bottom: 6px;">
                  Next due: <span style="font-family:var(--mono);"><?= htmlspecialchars($due->format("m/j/Y")) ?></span>
                  • Period: <span style="font-family:var(--mono);"><?= htmlspecialchars($period) ?></span>
                </div>
                  <span class="pill" style="font-family:var(--mono);"><?= htmlspecialchars($countdown) ?></span>
              </div>

              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="bill_id" value="<?= (int)$b["id"] ?>">
                <input type="hidden" name="mode" value="pay">
                <button class="btn" type="submit">Mark Paid</button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
      </div>
      <!-- History -->
      <div class="tile">
        <div class="t">HISTORY</div>
        <div class="sub" style="margin-top:8px;">Last 20 payment confirmations.</div>

        <div class="hr"></div>

        <div style="display:grid; gap:10px;">
          <?php if (count($history) === 0): ?>
            <div class="sub">No payment history yet.</div>
          <?php else: ?>
            <?php foreach ($history as $h):
              $name = (string)$h["name"];
              $when = date("M j, Y g:i A", strtotime((string)$h["paid_at"]));
            ?>
              <div class="tile tx-row" style="padding:12px 12px;">
                <div class="row" style="align-items:flex-start;">
                  <div>
                    <div class="t" style="opacity:.85;"><?= htmlspecialchars($name) ?></div>
                    <div class="v" style="font-family:var(--mono); font-size:15px;">
                      Period <?= htmlspecialchars((string)$h["period"]) ?>
                    </div>
                    <div class="sub" style="margin-top:6px; font-family:var(--mono); opacity:.85;">
                      Paid: <?= htmlspecialchars($when) ?>
                    </div>
                  </div>
                  <span class="pill">PAID</span>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const statusBox = document.getElementById('statusBox');
  if (statusBox && statusBox.classList.contains('good')) {
    statusBox.style.transform = 'translateY(-2px)';
    statusBox.style.transition = 'transform .2s ease';
    setTimeout(() => statusBox.style.transform = 'translateY(0)', 140);
  }
})();

document.querySelectorAll('form button[type="submit"]').forEach(btn => {
  btn.addEventListener('click', () => {
    btn.disabled = true;
    btn.textContent = "Saving...";
    btn.closest('form').submit();
  });
});
</script>

<?php require __DIR__ . "/partials/bottom.php"; ?>