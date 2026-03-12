<?php
require_once __DIR__ . '/../auth/Auth.php';
require_once __DIR__ . '/../auth/AuditLog.php';
require_once __DIR__ . '/../config/Database.php';
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
        $phone = trim($_POST['phone_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        // Validation
        if (!$name || !$username || !$password) {
            $err = "Name, username, and password are required.";
        } elseif (preg_match('/[0-9]/', $name)) {
            $err = "Full name cannot contain numbers.";
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
                $db->prepare("INSERT INTO users (name,username,password,role_id,phone_number,email,first_login) VALUES (?,?,?,?,?,?,1)")
                   ->execute([$name, $username, $hash, $cashierRole, $phone ?: null, $email ?: null]);
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

include __DIR__ . '/../includes/nav.php';
?>

<div class="page-header">
  <h2>👥 Cashier Management</h2>
  <p>Add and manage cashier accounts</p>
</div>

<?php if ($msg): ?><div class="alert alert-success">✔ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error">⚠ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="grid-2">
  <div class="section-card" style="max-width:360px">
    <div class="section-title">Add New Cashier</div>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <label class="form-label">Full Name</label>
      <input type="text" name="name" placeholder="e.g. Pedro Reyes" pattern="[a-zA-Z\s]+" title="Full name can only contain letters and spaces" required>
      <label class="form-label">Username</label>
      <input type="text" name="username" placeholder="e.g. cashier3" required>
      <label class="form-label">Password</label>
      <input type="password" name="password" placeholder="Min 6 characters" required>
      <label class="form-label">Phone Number</label>
      <input type="text" name="phone_number" placeholder="e.g. 09123456789" maxlength="11" pattern="[0-9]{10,11}">
      <label class="form-label">Email Address</label>
      <input type="email" name="email" placeholder="e.g. pedro@example.com">
      <button class="btn btn-gold" style="width:100%" type="submit">Add Cashier</button>
    </form>
  </div>

  <div class="section-card">
    <div class="section-title">All Cashiers (<?= count($cashiers) ?>)</div>
    <table>
      <thead><tr><th>Name</th><th>Username</th><th>Orders</th><th>Revenue</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($cashiers as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['name']) ?></td>
          <td style="color:var(--muted)"><?= htmlspecialchars($c['username']) ?></td>
          <td><?= $c['order_count'] ?></td>
          <td>₱<?= number_format($c['total_revenue'],2) ?></td>
          <td><?= $c['is_active']
              ? '<span class="badge badge-paid">Active</span>'
              : '<span class="badge badge-refunded">Inactive</span>' ?></td>
          <td>
            <form method="POST">
              <input type="hidden" name="action"  value="toggle">
              <input type="hidden" name="user_id" value="<?= $c['id'] ?>">
              <button class="btn btn-outline btn-sm" type="submit">
                <?= $c['is_active'] ? 'Deactivate' : 'Activate' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

</main></div>