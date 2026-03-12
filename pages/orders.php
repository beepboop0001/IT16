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
        $db->prepare("UPDATE orders SET status='refunded' WHERE id=?")->execute([$orderId]);
        $msg = "Order #$orderId refunded.";
        $user = Auth::user();
        AuditLog::record('order_refunded',
                         "Refunded Order #$orderId (Table {$order['table_number']}, ₱" . number_format($order['total'], 2) . ").",
                         'order', $orderId,
                         $user['id'], $user['username'], $user['role_name']);
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
        $lineItems = $db->prepare("
            SELECT oi.quantity, m.name, oi.unit_price
            FROM order_items oi JOIN menu_items m ON m.id = oi.menu_item_id
            WHERE oi.order_id = ?
        ");
        $lineItems->execute([$o['id']]);
        $lines = $lineItems->fetchAll();
    ?>
      <tr>
        <td style="color:var(--muted)">#<?= $o['id'] ?></td>
        <td>Table <?= $o['table_number'] ?></td>
        <?php if ($isAllOrders): ?><td><?= htmlspecialchars($o['cashier_name']) ?></td><?php endif; ?>
        <td style="font-size:.78rem;color:var(--muted)">
          <?= implode(', ', array_map(fn($l)=>$l['name'].'×'.$l['quantity'], $lines)) ?>
        </td>
        <td style="font-weight:600">₱<?= number_format($o['total'],2) ?></td>
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
            <form method="POST" onsubmit="return confirm('Refund this order?')">
              <input type="hidden" name="action"   value="refund">
              <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
              <button class="btn btn-danger btn-sm">↩ Refund</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

</main></div>
