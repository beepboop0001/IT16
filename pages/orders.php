<?php
require_once __DIR__ . '/../auth/Auth.php';
require_once __DIR__ . '/../auth/AuditLog.php';
require_once __DIR__ . '/../config/Database.php';
Auth::init();
Auth::requireLogin();

// Determine which page we're on and enforce permissions
$isAllOrders = (basename($_SERVER['PHP_SELF']) === 'orders.php');
if ($isAllOrders) Auth::requirePermission('view_all_orders');
else              Auth::requirePermission('view_own_orders');

$db  = Database::get();
$msg = $err = '';

// Handle pay / refund actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']   ?? '';
    $orderId = (int)($_POST['order_id'] ?? 0);

    $orderStmt = $db->prepare("SELECT o.*, u.name AS cashier_name FROM orders o JOIN users u ON u.id = o.cashier_id WHERE o.id=?");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch();

    if ($action === 'pay' && Auth::can('process_payment')) {
        $db->prepare("UPDATE orders SET status='paid' WHERE id=?")->execute([$orderId]);
        $msg = "Order #$orderId marked as paid.";
        $user = Auth::user();
        AuditLog::record('order_paid',
                         "Marked Order #$orderId (Table {$order['table_number']}, ₱" . number_format($order['total'], 2) . ") as paid.",
                         'order', $orderId,
                         $user['id'], $user['username'], $user['role_name']);

    } elseif ($action === 'refund' && Auth::can('process_refund')) {
        $reason = trim($_POST['refund_reason'] ?? '');

        if (empty($reason)) {
            $err = "A reason is required to process a refund.";
        } else {
            $db->prepare("UPDATE orders SET status='refunded' WHERE id=?")->execute([$orderId]);
            $msg = "Order #$orderId refunded.";
            $user = Auth::user();
            AuditLog::record('order_refunded',
                             "Refunded Order #$orderId (Table {$order['table_number']}, ₱" . number_format($order['total'], 2) . "). Reason: $reason",
                             'order', $orderId,
                             $user['id'], $user['username'], $user['role_name']);
        }
    }
}

// Fetch orders
if ($isAllOrders) {
    $orders = $db->query("
        SELECT o.*, u.name AS cashier_name
        FROM orders o JOIN users u ON u.id = o.cashier_id
        ORDER BY o.created_at DESC
    ")->fetchAll();
    $pageTitle = '🧾 All Orders';
    $pageDesc  = 'Orders from all cashiers';
} else {
    $stmt = $db->prepare("
        SELECT o.*, u.name AS cashier_name
        FROM orders o JOIN users u ON u.id = o.cashier_id
        WHERE o.cashier_id = ? ORDER BY o.created_at DESC
    ");
    $stmt->execute([Auth::user()['id']]);
    $orders    = $stmt->fetchAll();
    $pageTitle = '🧾 My Orders';
    $pageDesc  = 'Orders you have created';
}

// Pre-fetch line items for every order
$allLines = [];
foreach ($orders as $o) {
    $li = $db->prepare("
        SELECT oi.quantity, m.name, oi.unit_price
        FROM order_items oi JOIN menu_items m ON m.id = oi.menu_item_id
        WHERE oi.order_id = ?
    ");
    $li->execute([$o['id']]);
    $allLines[$o['id']] = $li->fetchAll();
}

include __DIR__ . '/../includes/nav.php';
?>

<div class="page-header">
  <h2><?= $pageTitle ?></h2>
  <p><?= $pageDesc ?> — <?= count($orders) ?> total</p>
</div>

<?php if ($msg): ?><div class="alert alert-success">✔ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error">⚠ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="section-card">
  <?php if (empty($orders)): ?>
    <p style="color:var(--muted);font-size:.85rem">No orders found.</p>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Table</th>
        <?php if ($isAllOrders): ?><th>Cashier</th><?php endif; ?>
        <th>Items</th>
        <th>Total</th>
        <th>Status</th>
        <th>Time</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($orders as $o):
        $lines = $allLines[$o['id']] ?? [];
    ?>
      <tr>
        <td style="color:var(--muted)">#<?= $o['id'] ?></td>
        <td>Table <?= $o['table_number'] ?></td>
        <?php if ($isAllOrders): ?><td><?= htmlspecialchars($o['cashier_name']) ?></td><?php endif; ?>
        <td style="font-size:.78rem;color:var(--muted)">
          <?= implode(', ', array_map(fn($l) => $l['name'] . '×' . $l['quantity'], $lines)) ?>
        </td>
        <td style="font-weight:600">₱<?= number_format($o['total'], 2) ?></td>
        <td><span class="badge badge-<?= $o['status'] ?>"><?= ucfirst($o['status']) ?></span></td>
        <td style="font-size:.78rem;color:var(--muted)"><?= date('M j, h:i A', strtotime($o['created_at'])) ?></td>
        <td style="display:flex;gap:6px;flex-wrap:wrap">
          <?php if ($o['status'] === 'open' && Auth::can('process_payment')): ?>
            <form method="POST">
              <input type="hidden" name="action"   value="pay">
              <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
              <button class="btn btn-gold btn-sm">💳 Pay</button>
            </form>
          <?php endif; ?>
          <?php if ($o['status'] === 'paid' && Auth::can('process_refund')): ?>
            <button class="btn btn-danger btn-sm"
                    onclick="openRefundModal(
                      <?= $o['id'] ?>,
                      <?= $o['table_number'] ?>,
                      '<?= number_format($o['total'], 2) ?>',
                      '<?= htmlspecialchars($o['cashier_name'], ENT_QUOTES) ?>'
                    )">
              ↩ Refund
            </button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- ── Refund Confirmation Modal ─────────────────────────────────────── -->
<div id="refundModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;
                              background:rgba(0,0,0,.80);z-index:1000;
                              align-items:center;justify-content:center">
  <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;
              padding:28px;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.6)">

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
      <h3 style="font-family:'Playfair Display',serif;color:var(--cream)">↩ Confirm Refund</h3>
      <button onclick="closeRefundModal()"
              style="background:none;border:none;color:var(--muted);font-size:1.4rem;
                     cursor:pointer;line-height:1;padding:0">✕</button>
    </div>

    <!-- Order summary -->
    <div id="refundSummary"
         style="background:rgba(224,92,58,.07);border:1px solid rgba(224,92,58,.25);
                border-radius:8px;padding:14px;margin-bottom:18px;font-size:.85rem;line-height:1.8">
    </div>

    <!-- Refund form -->
    <form id="refundForm" method="POST">
      <input type="hidden" name="action"   value="refund">
      <input type="hidden" name="order_id" id="refundOrderId">

      <label style="display:block;font-size:.72rem;text-transform:uppercase;
                    letter-spacing:1px;color:var(--muted);margin-bottom:6px">
        Reason for Refund <span style="color:#e8937a">*</span>
      </label>
      <textarea name="refund_reason" id="refundReason" required
                style="width:100%;padding:10px 13px;background:#0e0a06;
                       border:1px solid var(--border);color:var(--cream);
                       border-radius:8px;font-family:'DM Sans',sans-serif;
                       font-size:.9rem;margin-bottom:18px;min-height:90px;
                       resize:vertical;transition:border .2s"
                placeholder="e.g. Customer changed mind, Wrong order delivered, Item unavailable…"
                onfocus="this.style.borderColor='var(--gold)'"
                onblur="this.style.borderColor='var(--border)'"></textarea>

      <div style="display:flex;gap:8px">
        <button class="btn btn-danger" style="flex:1" type="submit">↩ Confirm Refund</button>
        <button class="btn btn-outline" style="flex:1" type="button"
                onclick="closeRefundModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openRefundModal(orderId, table, total, cashier) {
    document.getElementById('refundOrderId').value = orderId;
    document.getElementById('refundReason').value  = '';

    document.getElementById('refundSummary').innerHTML = `
        <table style="width:100%;border-collapse:collapse">
          <tr>
            <td style="color:var(--muted);width:45%">Order</td>
            <td><strong>#${orderId}</strong></td>
          </tr>
          <tr>
            <td style="color:var(--muted)">Table</td>
            <td><strong>Table ${table}</strong></td>
          </tr>
          <tr>
            <td style="color:var(--muted)">Cashier</td>
            <td>${cashier}</td>
          </tr>
          <tr>
            <td style="color:var(--muted)">Amount</td>
            <td><strong style="color:#e8937a;font-size:1rem">₱${total}</strong></td>
          </tr>
        </table>
    `;

    document.getElementById('refundModal').style.display = 'flex';

    // Focus the textarea after modal opens
    setTimeout(() => document.getElementById('refundReason').focus(), 100);
}

function closeRefundModal() {
    document.getElementById('refundModal').style.display = 'none';
}

// Validate reason before submit
document.getElementById('refundForm').addEventListener('submit', e => {
    const reason = document.getElementById('refundReason').value.trim();
    if (!reason) {
        e.preventDefault();
        document.getElementById('refundReason').style.borderColor = '#e05c3a';
        document.getElementById('refundReason').focus();
    }
});

// Close on backdrop click
document.getElementById('refundModal').addEventListener('click', e => {
    if (e.target.id === 'refundModal') closeRefundModal();
});
</script>

</main></div>