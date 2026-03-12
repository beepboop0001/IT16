<?php
require_once __DIR__ . '/../auth/Auth.php';
require_once __DIR__ . '/../auth/AuditLog.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../config/Encryption.php';
Auth::init();
Auth::requireLogin();
Auth::requirePermission('manage_cashiers');

$db  = Database::get();
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name     = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $phone    = trim($_POST['phone_number'] ?? '');
        $email    = trim($_POST['email'] ?? '');

        // ── Validation ─────────────────────────────────────────────────
        if (!$name || !$username || !$password) {
            $err = "Name, username, and password are required.";
        } elseif (!preg_match('/^[a-zA-Z\s\-]+$/', $name)) {
            $err = "Full name must contain letters only (no numbers or special characters).";
        } elseif (strlen($password) < 6) {
            $err = "Password must be 6+ characters.";
        } elseif (!empty($phone) && strlen($phone) > 11) {
            $err = "Phone number cannot exceed 11 characters.";
        } elseif (!empty($email) && !str_contains($email, '@')) {
            $err = "Email must contain @ symbol.";
        } else {
            $check = $db->prepare("SELECT id FROM users WHERE username=?");
            $check->execute([$username]);
            if ($check->fetch()) {
                $err = "Username already exists.";
            } else {
                $cashierRole = $db->query("SELECT id FROM roles WHERE name='cashier'")->fetchColumn();
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $encPhone = $phone ? Encryption::encrypt($phone) : null;
                $encEmail = $email ? Encryption::encrypt($email) : null;
                $db->prepare("INSERT INTO users (name,username,password,role_id,phone_number,email,first_login) VALUES (?,?,?,?,?,?,1)")
                   ->execute([$name, $username, $hash, $cashierRole, $encPhone, $encEmail]);
                $newId = (int)$db->lastInsertId();
                $msg = "Cashier \"$name\" added. They will be required to change their password on first login.";
                AuditLog::record('cashier_account_created',
                                 "Created cashier account for \"{$name}\" (username: \"{$username}\").",
                                 'cashier_account', $newId);
            }
        }

    } elseif ($action === 'toggle') {
        $id = (int)$_POST['user_id'];
        $target = $db->prepare("SELECT name, username, is_active FROM users WHERE id=?");
        $target->execute([$id]); $target = $target->fetch();
        $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id=? AND id != ?")
           ->execute([$id, Auth::user()['id']]);
        $newState = $target['is_active'] ? 'deactivated' : 'activated';
        $msg = "Account status updated.";
        AuditLog::record('cashier_account_toggled',
                         "Cashier account \"{$target['name']}\" (@{$target['username']}) was {$newState}.",
                         'cashier_account', $id);
    }
}

$cashierRoleId = $db->query("SELECT id FROM roles WHERE name='cashier'")->fetchColumn();
$cashiers = $db->prepare("
    SELECT u.*, COUNT(o.id) AS order_count,
           COALESCE(SUM(CASE WHEN o.status='paid' THEN o.total ELSE 0 END),0) AS total_revenue
    FROM users u
    LEFT JOIN orders o ON o.cashier_id = u.id
    WHERE u.role_id = ?
    GROUP BY u.id
");
$cashiers->execute([$cashierRoleId]);
$cashiers = $cashiers->fetchAll();

// Decrypt PII for display
foreach ($cashiers as &$c) {
    $c['phone_decrypted'] = Encryption::decrypt($c['phone_number']);
    $c['email_decrypted'] = Encryption::decrypt($c['email']);
    $c['phone_masked']    = Encryption::mask('phone', $c['phone_decrypted']);
    $c['email_masked']    = Encryption::mask('email', $c['email_decrypted']);
}
unset($c);

include __DIR__ . '/../includes/nav.php';
?>

<div class="page-header">
  <h2>👥 Cashier Management</h2>
  <p>Add and manage cashier accounts</p>
</div>

<?php if ($msg): ?><div class="alert alert-success">✔ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error">⚠ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="grid-2">

  <!-- ── Add New Cashier ──────────────────────────────────────────────── -->
  <div class="section-card" style="max-width:360px">
    <div class="section-title">Add New Cashier</div>

    <label class="form-label">Full Name</label>
    <input type="text" id="addName" placeholder="e.g. Pedro Reyes"
           oninput="validateCashierName(this)" style="margin-bottom:4px">
    <div id="nameError"
         style="display:none;color:#e8937a;font-size:.75rem;margin-bottom:10px">
      ⚠ Full name must contain letters only (no numbers or special characters).
    </div>

    <label class="form-label">Username</label>
    <input type="text" id="addUsername" placeholder="e.g. cashier3"
           oninput="validateUsername(this)" style="margin-bottom:4px">
    <div id="usernameError"
         style="display:none;color:#e8937a;font-size:.75rem;margin-bottom:10px">
      ⚠ Username can only contain letters, numbers, and underscores (no special characters).
    </div>

    <label class="form-label">Password</label>
    <input type="password" id="addPassword" placeholder="Min 6 characters"
           oninput="validatePassword(this)" style="margin-bottom:4px">
    <div id="passwordError"
         style="display:none;color:#e8937a;font-size:.75rem;margin-bottom:10px">
      ⚠ Password must be at least 6 characters.
    </div>

    <label class="form-label">Phone Number</label>
    <input type="text" id="addPhone" placeholder="e.g. 09123456789"
           oninput="validatePhone(this)" style="margin-bottom:4px">
    <div id="phoneError"
         style="display:none;color:#e8937a;font-size:.75rem;margin-bottom:10px">
      ⚠ Phone number must be exactly 11 digits (numbers only).
    </div>

    <label class="form-label">Email Address</label>
    <input type="text" id="addEmail" placeholder="e.g. pedro@example.com"
           oninput="validateEmail(this)" style="margin-bottom:4px">
    <div id="emailError"
         style="display:none;color:#e8937a;font-size:.75rem;margin-bottom:10px">
      ⚠ Email must contain @ and a valid domain (e.g. example.com).
    </div>

    <button class="btn btn-gold" style="width:100%;margin-top:4px"
            type="button" onclick="openAddCashierModal()">
      Add Cashier
    </button>
  </div>

  <!-- ── All Cashiers ─────────────────────────────────────────────────── -->
  <div class="section-card">
    <div class="section-title">All Cashiers (<?= count($cashiers) ?>)</div>
    <table>
      <thead>
        <tr>
          <th>Name</th><th>Username</th><th>Orders</th>
          <th>Revenue</th><th>Phone</th><th>Email</th><th>Status</th><th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($cashiers as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['name']) ?></td>
          <td style="color:var(--muted)"><?= htmlspecialchars($c['username']) ?></td>
          <td><?= $c['order_count'] ?></td>
          <td>₱<?= number_format($c['total_revenue'], 2) ?></td>
          <td style="font-size:.78rem;color:var(--muted)">
            <?= $c['phone_masked'] ? htmlspecialchars($c['phone_masked'], ENT_QUOTES, 'UTF-8') : '<span style="color:var(--muted)">-</span>' ?>
          </td>
          <td style="font-size:.78rem;color:var(--muted)">
            <?= $c['email_masked'] ? htmlspecialchars($c['email_masked'], ENT_QUOTES, 'UTF-8') : '<span style="color:var(--muted)">-</span>' ?>
          </td>
          <td>
            <?= $c['is_active']
                ? '<span class="badge badge-paid">Active</span>'
                : '<span class="badge badge-refunded">Inactive</span>' ?>
          </td>
          <td>
            <button class="btn btn-outline btn-sm"
                    onclick="openToggleModal(
                      <?= $c['id'] ?>,
                      '<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>',
                      '<?= htmlspecialchars($c['username'], ENT_QUOTES) ?>',
                      <?= $c['is_active'] ? 'true' : 'false' ?>
                    )">
              <?= $c['is_active'] ? 'Deactivate' : 'Activate' ?>
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Add Cashier Confirmation Modal ────────────────────────────────── -->
<div id="addCashierModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;
                                  background:rgba(0,0,0,.80);z-index:1000;
                                  align-items:center;justify-content:center">
  <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;
              padding:28px;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.6)">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
      <h3 style="font-family:'Playfair Display',serif;color:var(--cream)">👤 Confirm Add Cashier</h3>
      <button onclick="closeAddCashierModal()"
              style="background:none;border:none;color:var(--muted);font-size:1.4rem;
                     cursor:pointer;line-height:1;padding:0">✕</button>
    </div>

    <!-- Cashier preview -->
    <div style="background:rgba(201,147,58,.07);border:1px solid rgba(201,147,58,.25);
                border-radius:8px;padding:14px;margin-bottom:20px;font-size:.87rem;line-height:1.9">
      <table style="width:100%;border-collapse:collapse">
        <tr>
          <td style="color:var(--muted);width:40%">Full Name</td>
          <td><strong id="previewCashierName" style="color:var(--cream)"></strong></td>
        </tr>
        <tr>
          <td style="color:var(--muted)">Username</td>
          <td id="previewCashierUsername" style="color:var(--cream)"></td>
        </tr>
        <tr>
          <td style="color:var(--muted)">Phone</td>
          <td id="previewCashierPhone" style="color:var(--muted)"></td>
        </tr>
        <tr>
          <td style="color:var(--muted)">Email</td>
          <td id="previewCashierEmail" style="color:var(--muted)"></td>
        </tr>
      </table>
      <div style="margin-top:10px;padding-top:10px;border-top:1px solid rgba(201,147,58,.2);
                  font-size:.78rem;color:var(--muted)">
        🔒 Cashier will be required to change their password on first login.
      </div>
    </div>

    <!-- Hidden form submitted on confirm -->
    <form id="addCashierForm" method="POST">
      <input type="hidden" name="action"       value="add">
      <input type="hidden" name="name"         id="hiddenCashierName">
      <input type="hidden" name="username"     id="hiddenCashierUsername">
      <input type="hidden" name="password"     id="hiddenCashierPassword">
      <input type="hidden" name="phone_number" id="hiddenCashierPhone">
      <input type="hidden" name="email"        id="hiddenCashierEmail">

      <div style="display:flex;gap:8px">
        <button class="btn btn-gold" style="flex:1" type="submit">✔ Add Cashier</button>
        <button class="btn btn-outline" style="flex:1" type="button"
                onclick="closeAddCashierModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Toggle Confirmation Modal ─────────────────────────────────────── -->
<div id="toggleModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;
                              background:rgba(0,0,0,.80);z-index:1000;
                              align-items:center;justify-content:center">
  <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;
              padding:28px;max-width:400px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.6)">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
      <h3 id="toggleModalTitle"
          style="font-family:'Playfair Display',serif;color:var(--cream)"></h3>
      <button onclick="closeToggleModal()"
              style="background:none;border:none;color:var(--muted);font-size:1.4rem;
                     cursor:pointer;line-height:1;padding:0">✕</button>
    </div>

    <div id="toggleSummary"
         style="background:rgba(201,147,58,.07);border:1px solid rgba(201,147,58,.25);
                border-radius:8px;padding:14px;margin-bottom:20px;
                font-size:.87rem;line-height:1.9">
    </div>

    <p id="toggleWarning"
       style="font-size:.82rem;color:var(--muted);margin-bottom:20px;line-height:1.6"></p>

    <form id="toggleForm" method="POST">
      <input type="hidden" name="action"  value="toggle">
      <input type="hidden" name="user_id" id="toggleUserId">

      <div style="display:flex;gap:8px">
        <button id="toggleConfirmBtn" class="btn" style="flex:1" type="submit"></button>
        <button class="btn btn-outline" style="flex:1" type="button"
                onclick="closeToggleModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Name validation: letters, spaces, hyphens only ────────────────────
function validateCashierName(input) {
    const valid = /^[a-zA-Z\s\-]*$/.test(input.value);
    const errDiv = document.getElementById('nameError');
    if (!valid) {
        input.value = input.value.replace(/[^a-zA-Z\s\-]/g, '');
        errDiv.style.display = 'block';
        input.style.borderColor = '#e05c3a';
    } else {
        errDiv.style.display = 'none';
        input.style.borderColor = input.value.trim() ? 'var(--gold)' : 'var(--border)';
    }
}

// ── Field validators ─────────────────────────────────────────────────
function validateUsername(input) {
    const valid = /^[a-zA-Z0-9_]*$/.test(input.value);
    const errDiv = document.getElementById('usernameError');
    if (!valid) {
        input.value = input.value.replace(/[^a-zA-Z0-9_]/g, '');
        errDiv.style.display = 'block';
        input.style.borderColor = '#e05c3a';
    } else {
        errDiv.style.display = 'none';
        input.style.borderColor = input.value.trim() ? 'var(--gold)' : 'var(--border)';
    }
}

function validatePassword(input) {
    const errDiv = document.getElementById('passwordError');
    if (input.value.length > 0 && input.value.length < 6) {
        errDiv.style.display = 'block';
        input.style.borderColor = '#e05c3a';
    } else {
        errDiv.style.display = 'none';
        input.style.borderColor = input.value.length >= 6 ? 'var(--gold)' : 'var(--border)';
    }
}

function validatePhone(input) {
    // Strip non-numeric
    input.value = input.value.replace(/[^0-9]/g, '');
    // Enforce max 11 digits
    if (input.value.length > 11) input.value = input.value.slice(0, 11);
    const errDiv = document.getElementById('phoneError');
    if (input.value.length > 0 && input.value.length !== 11) {
        errDiv.style.display = 'block';
        input.style.borderColor = '#e05c3a';
    } else {
        errDiv.style.display = 'none';
        input.style.borderColor = input.value.length === 11 ? 'var(--gold)' : 'var(--border)';
    }
}

function validateEmail(input) {
    const errDiv = document.getElementById('emailError');
    const valid  = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value);
    if (input.value.length > 0 && !valid) {
        errDiv.style.display = 'block';
        input.style.borderColor = '#e05c3a';
    } else {
        errDiv.style.display = 'none';
        input.style.borderColor = valid ? 'var(--gold)' : 'var(--border)';
    }
}

// ── Add Cashier Modal ─────────────────────────────────────────────────
function openAddCashierModal() {
    const name     = document.getElementById('addName').value.trim();
    const username = document.getElementById('addUsername').value.trim();
    const password = document.getElementById('addPassword').value;
    const phone    = document.getElementById('addPhone').value.trim();
    const email    = document.getElementById('addEmail').value.trim();

    // Validate all fields before opening modal
    let hasError = false;

    if (!name || !/^[a-zA-Z\s\-]+$/.test(name)) {
        document.getElementById('nameError').style.display = 'block';
        highlight('addName'); hasError = true;
    }
    if (!username || !/^[a-zA-Z0-9_]+$/.test(username)) {
        document.getElementById('usernameError').style.display = 'block';
        highlight('addUsername'); hasError = true;
    }
    if (!password || password.length < 6) {
        document.getElementById('passwordError').style.display = 'block';
        highlight('addPassword'); hasError = true;
    }
    if (phone && (!/^[0-9]+$/.test(phone) || phone.length !== 11)) {
        document.getElementById('phoneError').style.display = 'block';
        highlight('addPhone'); hasError = true;
    }
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        document.getElementById('emailError').style.display = 'block';
        highlight('addEmail'); hasError = true;
    }
    if (hasError) return;

    // Populate preview
    document.getElementById('previewCashierName').textContent     = name;
    document.getElementById('previewCashierUsername').textContent = '@' + username;
    document.getElementById('previewCashierPhone').textContent    = phone || '—';
    document.getElementById('previewCashierEmail').textContent    = email || '—';

    // Populate hidden fields
    document.getElementById('hiddenCashierName').value     = name;
    document.getElementById('hiddenCashierUsername').value = username;
    document.getElementById('hiddenCashierPassword').value = password;
    document.getElementById('hiddenCashierPhone').value    = phone;
    document.getElementById('hiddenCashierEmail').value    = email;

    document.getElementById('addCashierModal').style.display = 'flex';
}

function closeAddCashierModal() {
    document.getElementById('addCashierModal').style.display = 'none';
}

document.getElementById('addCashierModal').addEventListener('click', e => {
    if (e.target.id === 'addCashierModal') closeAddCashierModal();
});

// ── Toggle (Activate / Deactivate) Modal ──────────────────────────────
function openToggleModal(userId, name, username, isActive) {
    const action = isActive ? 'Deactivate' : 'Activate';
    const icon   = isActive ? '🔴' : '🟢';

    document.getElementById('toggleModalTitle').textContent = icon + ' ' + action + ' Cashier';
    document.getElementById('toggleUserId').value = userId;

    document.getElementById('toggleSummary').innerHTML = `
        <table style="width:100%;border-collapse:collapse">
          <tr>
            <td style="color:var(--muted);width:40%">Name</td>
            <td><strong style="color:var(--cream)">${name}</strong></td>
          </tr>
          <tr>
            <td style="color:var(--muted)">Username</td>
            <td style="color:var(--muted)">@${username}</td>
          </tr>
          <tr>
            <td style="color:var(--muted)">Current Status</td>
            <td>
              <span style="font-weight:600;color:${isActive ? '#7dd87d' : '#e8937a'}">
                ${isActive ? 'Active' : 'Inactive'}
              </span>
            </td>
          </tr>
        </table>
    `;

    document.getElementById('toggleWarning').textContent = isActive
        ? '⚠ Deactivating this account will prevent the cashier from logging in until reactivated.'
        : '✔ Activating this account will allow the cashier to log in again.';

    const btn = document.getElementById('toggleConfirmBtn');
    btn.textContent = action + ' Account';
    if (isActive) {
        btn.style.cssText = 'flex:1;padding:9px 18px;border-radius:7px;font-family:\'DM Sans\',sans-serif;font-size:.85rem;font-weight:600;cursor:pointer;background:rgba(224,92,58,.8);color:#fff;border:none';
    } else {
        btn.style.cssText = 'flex:1;padding:9px 18px;border-radius:7px;font-family:\'DM Sans\',sans-serif;font-size:.85rem;font-weight:600;cursor:pointer;background:var(--gold);color:#0e0a06;border:none';
    }

    document.getElementById('toggleModal').style.display = 'flex';
}

function closeToggleModal() {
    document.getElementById('toggleModal').style.display = 'none';
}

document.getElementById('toggleModal').addEventListener('click', e => {
    if (e.target.id === 'toggleModal') closeToggleModal();
});

// ── Helper: highlight invalid field ──────────────────────────────────
function highlight(id) {
    const el = document.getElementById(id);
    el.style.borderColor = '#e05c3a';
    el.focus();
    el.addEventListener('input', () => el.style.borderColor = 'var(--border)', { once: true });
}
</script>

</main></div>