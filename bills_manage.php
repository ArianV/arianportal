<?php
require __DIR__ . "/config.php";
require __DIR__ . "/auth.php";
require_login();

$userId = (int)$_SESSION["user_id"];
$error = "";
$success = "";

// Simple flash helpers (if you already have these globally, remove duplicates)
function flash_set(string $key, string $value): void { $_SESSION["flash_$key"] = $value; }
function flash_get(string $key): string { $k="flash_$key"; $v=$_SESSION[$k] ?? ""; unset($_SESSION[$k]); return $v; }

function due_date_for_month(int $year, int $month, int $dueDay): DateTimeImmutable {
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

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  csrf_verify();
  $mode = $_POST["mode"] ?? "";

  if ($mode === "create") {
    $name = trim($_POST["name"] ?? "");
    $dueDay = (int)($_POST["due_day"] ?? 0);

    if ($name === "") $error = "Name is required.";
    elseif ($dueDay < 1 || $dueDay > 28) $error = "Due day must be 1–28.";
    else {
      $nextDue = default_next_due_date($dueDay)->format("Y-m-d");
      $stmt = $pdo->prepare("
        INSERT INTO user_bills (user_id, name, due_day, next_due_date)
        VALUES (?, ?, ?, ?)
      ");
      $stmt->execute([$userId, $name, $dueDay, $nextDue]);

      flash_set("success", "Bill created.");
      header("Location: /bills_manage");
      exit;
    }
  }

  if ($mode === "update") {
    $billId = (int)($_POST["bill_id"] ?? 0);
    $name = trim($_POST["name"] ?? "");
    $dueDay = (int)($_POST["due_day"] ?? 0);

    if ($billId <= 0) $error = "Invalid bill.";
    elseif ($name === "") $error = "Name is required.";
    elseif ($dueDay < 1 || $dueDay > 28) $error = "Due day must be 1–28.";
    else {
      // keep next_due_date aligned to due_day (future-only behavior)
      $nextDue = default_next_due_date($dueDay)->format("Y-m-d");

      $stmt = $pdo->prepare("
        UPDATE user_bills
        SET name = ?, due_day = ?, next_due_date = ?, updated_at = now()
        WHERE id = ? AND user_id = ?
      ");
      $stmt->execute([$name, $dueDay, $nextDue, $billId, $userId]);

      flash_set("success", "Bill updated.");
      header("Location: /bills_manage");
      exit;
    }
  }

  if ($mode === "toggle") {
    $billId = (int)($_POST["bill_id"] ?? 0);
    $to = ($_POST["to"] ?? "") === "0" ? false : true;

    if ($billId <= 0) $error = "Invalid bill.";
    else {
      $stmt = $pdo->prepare("
        UPDATE user_bills
        SET is_active = ?, updated_at = now()
        WHERE id = ? AND user_id = ?
      ");
      $stmt->execute([$to, $billId, $userId]);

      flash_set("success", $to ? "Bill enabled." : "Bill disabled.");
      header("Location: /bills_manage");
      exit;
    }
  }
}

$success = $success ?: flash_get("success");
$error   = $error   ?: flash_get("error");

$stmt = $pdo->prepare("
  SELECT id, name, due_day, next_due_date, is_active
  FROM user_bills
  WHERE user_id = ?
  ORDER BY is_active DESC, name ASC
");
$stmt->execute([$userId]);
$bills = $stmt->fetchAll();

$pageTitle = "Manage Bills";
require __DIR__ . "/partials/top.php";
?>

<div class="wrap">
  <div class="card" style="width:min(980px,100%);">
    <div class="navbar">
      <div class="brand"><span class="dot"></span> ARIAN // PORTAL</div>
      <div class="actions">
        <a class="btn btn-ghost" href="/payments">Payments</a>
        <a class="btn btn-ghost" href="/dashboard">Dashboard</a>
      </div>
    </div>

    <div class="card-body">
      <div class="h1" style="margin:0;">Manage Bills</div>
      <p class="sub">Create, edit, enable/disable bills. Due day range: 1–28.</p>

      <div class="hr"></div>

      <?php if ($error): ?><div class="alert bad"><?= htmlspecialchars($error) ?></div><div class="hr"></div><?php endif; ?>
      <?php if ($success): ?><div class="alert good"><?= htmlspecialchars($success) ?></div><div class="hr"></div><?php endif; ?>

      <style>
        @media (min-width: 550px){
          .shell { 
            grid-template-columns: 1fr 1fr;
        }
        }
      </style>

      <div class="shell" style="max-width: none; gap:16px; align-items:start;">
        <div class="tile">
          <div class="t">ADD NEW BILL</div>
          <form method="post" style="margin-top:10px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="mode" value="create">

            <label>Name</label>
            <input class="input" name="name" placeholder="e.g. Rent, Phone, Gym" required>

            <div style="height:10px;"></div>

            <label>Due day (1–28)</label>
            <select class="input" name="due_day" style="max-width:220px;">
              <?php for ($d=1; $d<=28; $d++): ?>
                <option value="<?= $d ?>" <?= ($d===20) ? "selected" : "" ?>><?= $d ?></option>
              <?php endfor; ?>
            </select>

            <div style="height:12px;"></div>
            <button class="btn" type="submit" style="width:100%;">Create Bill</button>
          </form>
        </div>

        <div class="tile">
          <div class="t">YOUR BILLS</div>
          <div style="margin-top:10px; display:grid; gap:10px;">
            <?php if (count($bills) === 0): ?>
              <div class="sub">No bills yet. Add one on the left.</div>
            <?php else: ?>
              <?php foreach ($bills as $b): ?>
                <div class="tile" style="padding:12px;">
                  <div class="row" style="align-items:flex-start;">
                    <div>
                      <div class="v" style="font-size:16px;"><?= htmlspecialchars($b["name"]) ?></div>
                      <div class="sub" style="margin-top:6px; font-family:var(--mono);">
                        due day: <?= (int)$b["due_day"] ?> • next due: <?= htmlspecialchars((new DateTimeImmutable($b["next_due_date"]))->format("M j, Y")) ?>
                        • <?= $b["is_active"] ? "active" : "disabled" ?>
                      </div>
                    </div>
                    <span class="pill">#<?= (int)$b["id"] ?></span>
                  </div>

                  <div class="hr"></div>

                  <form method="post" class="row" style="gap:10px; align-items:flex-end;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="mode" value="update">
                    <input type="hidden" name="bill_id" value="<?= (int)$b["id"] ?>">

                    <div style="flex:1;">
                      <label>Name</label>
                      <input class="input" name="name" value="<?= htmlspecialchars($b["name"]) ?>" required>
                    </div>

                    <div style="width:160px;">
                      <label>Due day</label>
                      <select class="input" name="due_day">
                        <?php for ($d=1; $d<=28; $d++): ?>
                          <option value="<?= $d ?>" <?= ((int)$b["due_day"]===$d) ? "selected" : "" ?>><?= $d ?></option>
                        <?php endfor; ?>
                      </select>
                    </div>

                    <button class="btn btn-ghost" type="submit">Save</button>
                  </form>

                  <div style="height:10px;"></div>

                  <form method="post" onsubmit="return confirm('Are you sure?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="mode" value="toggle">
                    <input type="hidden" name="bill_id" value="<?= (int)$b["id"] ?>">
                    <input type="hidden" name="to" value="<?= $b["is_active"] ? "0" : "1" ?>">
                    <button class="btn btn-ghost" type="submit" style="width:100%;">
                      <?= $b["is_active"] ? "Disable bill" : "Enable bill" ?>
                    </button>
                  </form>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<?php require __DIR__ . "/partials/bottom.php"; ?>