<?php
require __DIR__ . "/config.php";
require __DIR__ . "/auth.php";
require_login();

$userId = (int)$_SESSION["user_id"];
$error = "";
$success = "";

/**
 * Returns the 20th-based "due date" for the current cycle:
 * - If today is on/before the 20th -> this month's 20th
 * - If today is after the 20th -> next month's 20th
 */
function default_next_due_date(): DateTimeImmutable {
  $today = new DateTimeImmutable("today");
  $year = (int)$today->format("Y");
  $month = (int)$today->format("n");
  $day = (int)$today->format("j");

  if ($day <= 20) {
    return new DateTimeImmutable(sprintf("%04d-%02d-20", $year, $month));
  }
  // next month 20th
  return (new DateTimeImmutable(sprintf("%04d-%02d-20", $year, $month)))->modify("+1 month");
}

function period_from_due(DateTimeImmutable $due): string {
  return $due->format("Y-m"); // YYYY-MM
}

function next_month_20th(DateTimeImmutable $due): DateTimeImmutable {
  // due is already a 20th; go to next month 20th
  return $due->modify("+1 month")->setDate(
    (int)$due->modify("+1 month")->format("Y"),
    (int)$due->modify("+1 month")->format("n"),
    20
  );
}

function bill_label(string $billKey): string {
  return $billKey === "car" ? "Car Payment" : "Insurance";
}

function bill_badge(string $billKey): string {
  return $billKey === "car" ? "CAR" : "INS";
}

// Handle button press
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  csrf_verify();

  $billKey = $_POST["bill_key"] ?? "";
  if (!in_array($billKey, ["car", "insurance"], true)) {
    $error = "Invalid bill.";
  } else {
    try {
      $pdo->beginTransaction();

      // Lock status row if it exists
      $stmt = $pdo->prepare("SELECT next_due_date FROM bill_status WHERE user_id = ? AND bill_key = ? FOR UPDATE");
      $stmt->execute([$userId, $billKey]);
      $row = $stmt->fetch();

      $due = $row
        ? new DateTimeImmutable((string)$row["next_due_date"])
        : default_next_due_date();

      $period = period_from_due($due);

      // Mark this period as paid (idempotent due to unique constraint)
      $ins = $pdo->prepare("INSERT INTO bill_payments (user_id, bill_key, period) VALUES (?, ?, ?)");
      try {
        $ins->execute([$userId, $billKey, $period]);
      } catch (PDOException $e) {
        // If already paid for this period, keep status the same and show a message
        // Postgres unique violation is 23505
        if (($e->getCode() ?? "") === "23505") {
          $pdo->rollBack();
          $error = bill_label($billKey) . " already marked paid for {$period}.";
          goto DONE;
        }
        throw $e;
      }

      // Advance next due to next month's 20th
      $nextDue = next_month_20th($due)->format("Y-m-d");

      if ($row) {
        $upd = $pdo->prepare("UPDATE bill_status SET next_due_date = ?, updated_at = now() WHERE user_id = ? AND bill_key = ?");
        $upd->execute([$nextDue, $userId, $billKey]);
      } else {
        $upsert = $pdo->prepare("
          INSERT INTO bill_status (user_id, bill_key, next_due_date)
          VALUES (?, ?, ?)
          ON CONFLICT (user_id, bill_key)
          DO UPDATE SET next_due_date = EXCLUDED.next_due_date, updated_at = now()
        ");
        $upsert->execute([$userId, $billKey, $nextDue]);
      }

      $pdo->commit();
      $success = bill_label($billKey) . " marked paid for {$period}. Next due: " . (new DateTimeImmutable($nextDue))->format("M j, Y") . ".";
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $error = "Could not update payment status.";
    }
  }
}
DONE:

// Read statuses (ensure defaults if missing)
$statusStmt = $pdo->prepare("SELECT bill_key, next_due_date FROM bill_status WHERE user_id = ?");
$statusStmt->execute([$userId]);
$statuses = [];
foreach ($statusStmt->fetchAll() as $r) {
  $statuses[$r["bill_key"]] = (string)$r["next_due_date"];
}

$carDue = isset($statuses["car"]) ? new DateTimeImmutable($statuses["car"]) : default_next_due_date();
$insDue = isset($statuses["insurance"]) ? new DateTimeImmutable($statuses["insurance"]) : default_next_due_date();

// Recent payments
$histStmt = $pdo->prepare("
  SELECT bill_key, period, paid_at
  FROM bill_payments
  WHERE user_id = ?
  ORDER BY paid_at DESC
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
        <span class="pill">Due day: 20</span>
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

      <div class="shell" style="grid-template-columns: 1fr 1fr; gap:16px; align-items:start;">
        <!-- Buttons -->
        <div class="tile">
          <div class="t">PAY THIS MONTH</div>
          <div class="sub" style="margin-top:8px;">Press to mark the current due period paid.</div>

          <div class="hr"></div>

          <div class="tile" style="margin-bottom:12px;">
            <div class="row">
              <div>
                <div class="t"><?= bill_badge("car") ?></div>
                <div class="v"><?= htmlspecialchars(bill_label("car")) ?></div>
                <div class="sub" style="margin-top:6px;">
                  Next due: <span style="font-family:var(--mono);"><?= htmlspecialchars($carDue->format("M j, Y")) ?></span>
                  • Period: <span style="font-family:var(--mono);"><?= htmlspecialchars($carDue->format("Y-m")) ?></span>
                </div>
              </div>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="bill_key" value="car">
                <button class="btn" type="submit">Mark Paid</button>
              </form>
            </div>
          </div>

          <div class="tile">
            <div class="row">
              <div>
                <div class="t"><?= bill_badge("insurance") ?></div>
                <div class="v"><?= htmlspecialchars(bill_label("insurance")) ?></div>
                <div class="sub" style="margin-top:6px;">
                  Next due: <span style="font-family:var(--mono);"><?= htmlspecialchars($insDue->format("M j, Y")) ?></span>
                  • Period: <span style="font-family:var(--mono);"><?= htmlspecialchars($insDue->format("Y-m")) ?></span>
                </div>
              </div>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="bill_key" value="insurance">
                <button class="btn btn-ghost" type="submit">Mark Paid</button>
              </form>
            </div>
          </div>
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
                $bk = (string)$h["bill_key"];
                $when = date("M j, Y g:i A", strtotime((string)$h["paid_at"]));
              ?>
                <div class="tile tx-row" style="padding:12px 12px;">
                  <div class="row" style="align-items:flex-start;">
                    <div>
                      <div class="t" style="opacity:.85;"><?= htmlspecialchars(bill_label($bk)) ?></div>
                      <div class="v" style="font-family:var(--mono); font-size:15px;">
                        Period <?= htmlspecialchars((string)$h["period"]) ?>
                      </div>
                      <div class="sub" style="margin-top:6px; font-family:var(--mono); opacity:.85;">
                        Paid: <?= htmlspecialchars($when) ?>
                      </div>
                    </div>
                    <span class="pill"><?= htmlspecialchars(strtoupper(bill_badge($bk))) ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
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
</script>

<?php require __DIR__ . "/partials/bottom.php"; ?>