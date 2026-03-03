<?php
require __DIR__ . "/config.php";
require __DIR__ . "/auth.php";

require_login();

$userId = (int)$_SESSION["user_id"];
$error = "";
$success = "";

// Handle add/withdraw
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  csrf_verify();

  $action = $_POST["action"] ?? "";
  $amountStr = trim($_POST["amount"] ?? "");

  // Accept formats like "12", "12.3", "12.34"
  if (!preg_match('/^\d+(\.\d{1,2})?$/', $amountStr)) {
    $error = "Enter a valid amount (e.g. 12 or 12.34).";
  } else {
    $amountCents = (int) round(((float)$amountStr) * 100);

    if ($amountCents <= 0) {
      $error = "Amount must be greater than 0.";
    } elseif ($amountCents > 1000000000) {
      $error = "Amount too large.";
    } else {
      try {
        $pdo->beginTransaction();

        // Lock row for update
        $stmt = $pdo->prepare("SELECT balance_cents FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row) {
          throw new RuntimeException("User not found.");
        }

        $balance = (int)$row["balance_cents"];

        if ($action === "add") {
          $newBalance = $balance + $amountCents;
        } elseif ($action === "withdraw") {
          $newBalance = $balance - $amountCents;
          if ($newBalance < 0) {
            $pdo->rollBack();
            $error = "Insufficient funds.";
          }
        } else {
          $error = "Invalid action.";
        }

        if (!$error) {
          $upd = $pdo->prepare("UPDATE users SET balance_cents = ? WHERE id = ?");
          $upd->execute([$newBalance, $userId]);
          $pdo->commit();
          $success = ($action === "add") ? "Added." : "Withdrew.";
        }
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Something went wrong updating balance.";
      }
    }
  }
}

// Read current balance
$stmt = $pdo->prepare("SELECT balance_cents FROM users WHERE id = ?");
$stmt->execute([$userId]);
$balanceCents = (int)($stmt->fetch()["balance_cents"] ?? 0);

function money_fmt(int $cents): string {
  return "$" . number_format($cents / 100, 2, ".", ",");
}

$pageTitle = "Money Counter";
require __DIR__ . "/partials/top.php";
?>

<div class="wrap">
  <div class="card" style="width:min(760px,100%);">
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
          <div class="h1" style="margin:0;">Money Counter</div>
          <p class="sub">Local tracker. Add or withdraw amounts using the keypad.</p>
        </div>
        <span class="pill">Balance</span>
      </div>

      <div class="hr"></div>

      <?php if ($error): ?>
        <div class="alert bad"><?= htmlspecialchars($error) ?></div>
        <div class="hr"></div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert good"><?= htmlspecialchars($success) ?></div>
        <div class="hr"></div>
      <?php endif; ?>

      <div style="text-align:center; margin: 10px 0 18px;">
        <div style="font-family:var(--mono); color: rgba(255,255,255,.65); letter-spacing:.4px;">CURRENT</div>
        <div id="balance" style="font-size:46px; font-weight:800; letter-spacing:.5px;">
          <?= htmlspecialchars(money_fmt($balanceCents)) ?>
        </div>
      </div>

      <div class="shell" style="grid-template-columns: 1fr 1fr; gap:16px;">
        <!-- Left: entry + actions -->
        <div class="tile">
          <div class="t">ENTER AMOUNT</div>

          <div style="display:flex; gap:10px; align-items:center; margin-top:10px;">
            <div style="font-size:18px; font-family:var(--mono); opacity:.85;">$</div>
            <input id="amount" name="amount" class="input" inputmode="decimal" placeholder="0.00" style="font-family:var(--mono); font-size:18px;">
          </div>

          <div class="hr"></div>

          <div class="row">
            <form method="post" id="addForm" style="flex:1;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="amount" id="addAmount">
              <button class="btn" type="submit" style="width:100%;">Add</button>
            </form>

            <form method="post" id="withForm" style="flex:1;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
              <input type="hidden" name="action" value="withdraw">
              <input type="hidden" name="amount" id="withAmount">
              <button class="btn btn-ghost" type="submit" style="width:100%;">Withdraw</button>
            </form>
          </div>

          <p class="sub" style="margin:12px 0 0;">Tip: Use the keypad or type directly (supports 2 decimals).</p>
        </div>

        <!-- Right: numpad -->
        <div class="tile">
          <div class="t">KEYPAD</div>

          <div id="pad" style="
              margin-top:10px;
              display:grid;
              grid-template-columns: repeat(3, 1fr);
              gap:10px;">
            <?php
              $keys = ["1","2","3","4","5","6","7","8","9",".","0","⌫"];
              foreach ($keys as $k):
            ?>
              <button type="button" class="btn btn-ghost" data-key="<?= htmlspecialchars($k) ?>"
                style="height:54px; font-family:var(--mono); font-size:18px;">
                <?= htmlspecialchars($k) ?>
              </button>
            <?php endforeach; ?>
          </div>

          <div class="row" style="margin-top:10px;">
            <button type="button" class="btn btn-ghost" id="clearBtn" style="width:100%;">Clear</button>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
(function(){
  const amount = document.getElementById('amount');
  const pad = document.getElementById('pad');
  const clearBtn = document.getElementById('clearBtn');
  const addAmount = document.getElementById('addAmount');
  const withAmount = document.getElementById('withAmount');

  function sanitize(v){
    // Keep only digits and one dot, max 2 decimals
    v = (v || '').replace(/[^\d.]/g,'');
    const parts = v.split('.');
    if (parts.length > 2) v = parts[0] + '.' + parts.slice(1).join('');
    const [a,b] = v.split('.');
    if (typeof b !== 'undefined') v = a + '.' + b.slice(0,2);
    // Avoid leading zeros like 00012 -> 12 (but allow "0" and "0.xx")
    if (v.startsWith('0') && v.length > 1 && v[1] !== '.') {
      v = String(parseInt(v,10));
    }
    return v;
  }

  function syncHidden(){
    const v = sanitize(amount.value);
    amount.value = v;
    addAmount.value = v;
    withAmount.value = v;
  }

  pad.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-key]');
    if(!btn) return;
    const key = btn.dataset.key;

    if (key === '⌫') {
      amount.value = amount.value.slice(0, -1);
    } else {
      amount.value = (amount.value || '') + key;
    }
    syncHidden();
  });

  clearBtn.addEventListener('click', () => {
    amount.value = '';
    syncHidden();
  });

  amount.addEventListener('input', syncHidden);

  // Ensure submit uses current sanitized amount
  document.getElementById('addForm').addEventListener('submit', syncHidden);
  document.getElementById('withForm').addEventListener('submit', syncHidden);
})();
</script>

<?php require __DIR__ . "/partials/bottom.php"; ?>