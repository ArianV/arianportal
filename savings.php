<?php
require __DIR__ . "/config.php";
require __DIR__ . "/auth.php";
require_login();

$userId = (int)$_SESSION["user_id"];
$error = "";
$success = "";

function money_fmt(int $cents): string {
  return "$" . number_format($cents / 100, 2, ".", ",");
}

// Handle add/withdraw
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  csrf_verify();

  $action = $_POST["action"] ?? "";
  $amountStr = trim($_POST["amount"] ?? "");
  $note = trim($_POST["note"] ?? "");
  if ($note === "") $note = null;

  if (!in_array($action, ["add", "withdraw"], true)) {
    $error = "Invalid action.";
  } elseif (!preg_match('/^\d+(\.\d{1,2})?$/', $amountStr)) {
    $error = "Enter a valid amount (e.g. 12 or 12.34).";
  } else {
    $amountCents = (int) round(((float)$amountStr) * 100);

    if ($amountCents <= 0) {
      $error = "Amount must be greater than 0.";
    } else {
      try {
        $pdo->beginTransaction();

        // Lock user row
        $stmt = $pdo->prepare("SELECT balance_cents FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException("User not found.");

        $balance = (int)$row["balance_cents"];
        $newBalance = $balance;

        if ($action === "add") {
          $newBalance = $balance + $amountCents;
        } else { // withdraw
          $newBalance = $balance - $amountCents;
          if ($newBalance < 0) {
            $pdo->rollBack();
            $error = "Insufficient funds.";
          }
        }

        if (!$error) {
          // Update balance
          $upd = $pdo->prepare("UPDATE users SET balance_cents = ? WHERE id = ?");
          $upd->execute([$newBalance, $userId]);

          // Insert transaction history
          $ins = $pdo->prepare("
            INSERT INTO money_transactions (user_id, type, amount_cents, balance_after_cents, note)
            VALUES (?, ?, ?, ?, ?)
          ");
          $ins->execute([$userId, $action, $amountCents, $newBalance, $note]);

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

// Read recent transactions
$txStmt = $pdo->prepare("
  SELECT type, amount_cents, balance_after_cents, note, created_at
  FROM money_transactions
  WHERE user_id = ?
  ORDER BY created_at DESC
  LIMIT 25
");
$txStmt->execute([$userId]);
$tx = $txStmt->fetchAll();

$pageTitle = "Money Counter";
require __DIR__ . "/partials/top.php";
?>

<div class="wrap">
  <div class="card" style="width:min(980px,100%);">
    <div class="navbar">
      <div class="brand"><span class="dot"></span> ARIAN // PORTAL</div>
      <div class="actions" style="flex-wrap: nowrap;">
        <a class="btn btn-ghost" href="/dashboard">Dashboard</a>
        <a class="btn btn-ghost" href="/logout">Logout</a>
      </div>
    </div>

    <div class="card-body">
      <div class="row" style="align-items:flex-end;">
        <div>
          <div class="h1" style="margin:0;">Savings</div>
          <p class="sub">Personal Savings Tracker.</p>
        </div>
        <span class="pill">Balance</span>
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

      <div style="text-align:center; margin: 6px 0 18px;">
        <div style="font-family:var(--mono); color: rgba(255,255,255,.65); letter-spacing:.4px;">CURRENT BALANCE</div>

        <div id="balance"
             data-balance="<?= htmlspecialchars((string)$balanceCents) ?>"
             style="font-size:50px; font-weight:850; letter-spacing:.5px;">
          <?= htmlspecialchars(money_fmt($balanceCents)) ?>
        </div>
      </div>

      <div class="shell" style="grid-template-columns: 1fr 1fr; gap:16px; align-items:start; max-width: 1500px;">
        <!-- Left: entry + actions + quick add -->
        <div class="tile">
          <div class="t">ENTER AMOUNT</div>

          <div style="display:flex; gap:10px; align-items:center; margin-top:10px;">
            <div style="font-size:18px; font-family:var(--mono); opacity:.85;">$</div>
            <input id="amount" class="input" inputmode="decimal" placeholder="0.00"
                   style="font-family:var(--mono); font-size:18px;">
          </div>

          <div class="hr"></div>

          <!--  -->
          <!--  -->
          <!-- Load to Savings after so refreshing page doesnt do another transaction -->
          <!--  -->
          <!--  -->
          <div class="t">QUICK ADD</div>
          <div class="quick-grid" style="margin-top:10px;">
            <?php foreach ([5,10,20,50,100] as $q): ?>
              <button type="button" class="btn btn-ghost quickBtn" data-amt="<?= $q ?>" style="font-family:var(--mono);">
                +$<?= $q ?>
              </button>
            <?php endforeach; ?>
          </div>

          <div class="hr"></div>

          <div class="row">
            <form method="post" id="addForm" style="flex:1;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="amount" id="addAmount">
              <input type="hidden" name="note" id="addNote">
              <button class="btn" type="submit" style="width:100%;">Add</button>
            </form>

            <form method="post" id="withForm" style="flex:1;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
              <input type="hidden" name="action" value="withdraw">
              <input type="hidden" name="amount" id="withAmount">
              <input type="hidden" name="note" id="withNote">
              <button class="btn btn-ghost" type="submit" style="width:100%;">Withdraw</button>
            </form>
          </div>
        </div>

        <!-- Right: numpad + history -->
        <div class="tile">
          <div class="t">KEYPAD</div>

          <div id="pad" style="margin-top:10px; display:grid; grid-template-columns: repeat(3, 1fr); gap:10px;">
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

          <div class="hr"></div>

          <div class="row">
            <div>
              <div class="t">RECENT ACTIVITY</div>
              <div class="sub" style="margin-top:6px;">Hidden by default — tap to expand.</div>
            </div>

            <button type="button" class="btn btn-ghost" id="toggleActivity"
                    style="font-family:var(--mono);">
              Show activity
            </button>
          </div>

          <div id="activityWrap" class="collapsible" style="margin-top:12px;">
            <div style="display:grid; gap:10px;">
              <?php if (count($tx) === 0): ?>
                <div class="sub">No transactions yet. Add or withdraw to start your history.</div>
              <?php else: ?>
                <?php foreach ($tx as $r):
                  $type = $r["type"];
                  $sign = ($type === "add") ? "+" : "-";
                  $amt = money_fmt((int)$r["amount_cents"]);
                  $after = money_fmt((int)$r["balance_after_cents"]);
                  $note = $r["note"];
                  $when = date("M j, Y g:i A", strtotime((string)$r["created_at"]));
                ?>
                  <div class="tile tx-row" style="padding:12px 12px;">
                    <div class="row" style="align-items:flex-start;">
                      <div>
                        <div class="t" style="opacity:.8;"><?= htmlspecialchars(strtoupper($type)) ?> • <?= htmlspecialchars($when) ?></div>
                        <div class="v" style="font-size:16px; font-family:var(--mono);">
                          <?= htmlspecialchars($sign . $amt) ?>
                        </div>
                        <?php if ($note): ?>
                          <div class="sub" style="margin-top:6px; font-family:var(--mono); opacity:.85;">
                            note: <?= htmlspecialchars($note) ?>
                          </div>
                        <?php endif; ?>
                      </div>
                      <div style="text-align:right;">
                        <div class="t">AFTER</div>
                        <div class="v" style="font-family:var(--mono); font-size:15px;"><?= htmlspecialchars($after) ?></div>
                      </div>
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
  const amount = document.getElementById('amount');
  const note = document.getElementById('note');
  const pad = document.getElementById('pad');
  const clearBtn = document.getElementById('clearBtn');
  const addAmount = document.getElementById('addAmount');
  const withAmount = document.getElementById('withAmount');
  const addNote = document.getElementById('addNote');
  const withNote = document.getElementById('withNote');
  const statusBox = document.getElementById('statusBox');
  const balanceEl = document.getElementById('balance');

  // ---- Count-up animation for balance ----
  function fmtMoneyFromCents(cents){
    const dollars = (cents / 100).toFixed(2);
    const parts = dollars.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return '$' + parts[0] + '.' + parts[1];
  }

  // Animate from previous cached value to current server value
  const newCents = parseInt(balanceEl.dataset.balance || '0', 10);
  const key = 'portal_balance_cents';
  const oldCents = parseInt(localStorage.getItem(key) || String(newCents), 10);

  if (!Number.isNaN(oldCents) && oldCents !== newCents) {
    const start = oldCents;
    const end = newCents;
    const dur = 420; // ms
    const t0 = performance.now();

    function tick(t){
      const p = Math.min(1, (t - t0) / dur);
      const eased = 1 - Math.pow(1 - p, 3); // easeOutCubic
      const cur = Math.round(start + (end - start) * eased);
      balanceEl.textContent = fmtMoneyFromCents(cur);
      if (p < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);

    // glow based on direction
    balanceEl.classList.remove('pulse-good','pulse-bad');
    balanceEl.classList.add(end > start ? 'pulse-good' : 'pulse-bad');
  } else {
    balanceEl.textContent = fmtMoneyFromCents(newCents);
  }

  localStorage.setItem(key, String(newCents));

  // ---- Input helpers ----
  function sanitize(v){
    v = (v || '').replace(/[^\d.]/g,'');
    const parts = v.split('.');
    if (parts.length > 2) v = parts[0] + '.' + parts.slice(1).join('');
    const [a,b] = v.split('.');
    if (typeof b !== 'undefined') v = a + '.' + b.slice(0,2);
    if (v.startsWith('0') && v.length > 1 && v[1] !== '.') v = String(parseInt(v,10));
    return v;
  }

  function syncHidden(){
    const v = sanitize(amount.value);
    amount.value = v;
    addAmount.value = v;
    withAmount.value = v;

    const n = (note.value || '').trim();
    addNote.value = n;
    withNote.value = n;
  }

  pad.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-key]');
    if(!btn) return;
    const key = btn.dataset.key;

    if (key === '⌫') amount.value = amount.value.slice(0, -1);
    else amount.value = (amount.value || '') + key;

    syncHidden();
  });

  clearBtn.addEventListener('click', () => {
    amount.value = '';
    syncHidden();
  });

  amount.addEventListener('input', syncHidden);
  note.addEventListener('input', syncHidden);

  // Quick add
  document.querySelectorAll('.quickBtn').forEach(btn => {
    btn.addEventListener('click', () => {
      const n = btn.dataset.amt;
      amount.value = sanitize(String(n));
      syncHidden();
      amount.focus();
    });
  });

  document.getElementById('maxBtn').addEventListener('click', () => {
    amount.focus();
  });

  document.getElementById('addForm').addEventListener('submit', syncHidden);
  document.getElementById('withForm').addEventListener('submit', syncHidden);

  // subtle feedback
  if (statusBox && statusBox.classList.contains('good')) {
    statusBox.style.transform = 'translateY(-2px)';
    statusBox.style.transition = 'transform .2s ease';
    setTimeout(() => statusBox.style.transform = 'translateY(0)', 140);
  }
})();

// Collapsible activity toggle
const toggleBtn = document.getElementById('toggleActivity');
const activityWrap = document.getElementById('activityWrap');

if (toggleBtn && activityWrap) {
  // start collapsed
  let open = false;

  toggleBtn.addEventListener('click', () => {
    open = !open;
    activityWrap.classList.toggle('open', open);
    toggleBtn.textContent = open ? 'Hide activity' : 'Show activity';
  });
}
</script>

<?php require __DIR__ . "/partials/bottom.php"; ?>