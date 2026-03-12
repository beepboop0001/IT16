<?php
require_once __DIR__ . '/../auth/Auth.php';
require_once __DIR__ . '/../auth/AuditLog.php';
require_once __DIR__ . '/../config/Database.php';
Auth::init();
Auth::requireLogin();

$isAllOrders = (basename($_SERVER['PHP_SELF']) === 'orders.php');
if ($isAllOrders) Auth::requirePermission('view_all_orders');
else              Auth::requirePermission('view_own_orders');

$db  = Database::get();
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']   ?? '';
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($action === 'pay' && Auth::can('process_payment')) {
        try {
            $orderStmt = $db->prepare("SELECT * FROM orders WHERE id=?");
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();

            $db->prepare("UPDATE orders SET status='paid' WHERE id=?")->execute([$orderId]);
            $msg = "Order #$orderId marked as paid.";

            $user = Auth::user();
            AuditLog::record('order_paid',
                             "Marked Order #$orderId (Table {$order['table_number']}, ₱" . number_format($order['total'], 2) . ") as paid.",
                             'order', $orderId,
                             $user['id'], $user['username'], $user['role_name']);
        } catch (Exception $e) {
            error_log('[my_orders] Pay action failed for order #' . $orderId . ': ' . $e->getMessage());
            $err = "Failed to mark order as paid. Please try again.";
        }

    } elseif ($action === 'refund' && Auth::can('process_refund')) {
        try {
            $orderStmt = $db->prepare("SELECT * FROM orders WHERE id=?");
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();

            $db->prepare("UPDATE orders SET status='refunded' WHERE id=?")->execute([$orderId]);
            $msg = "Order #$orderId refunded.";

            $user = Auth::user();
            AuditLog::record('order_refunded',
                             "Refunded Order #$orderId (Table {$order['table_number']}, ₱" . number_format($order['total'], 2) . ").",
                             'order', $orderId,
                             $user['id'], $user['username'], $user['role_name']);
        } catch (Exception $e) {
            error_log('[my_orders] Refund action failed for order #' . $orderId . ': ' . $e->getMessage());
            $err = "Failed to process refund. Please try again.";
        }

    } elseif ($action === 'void' && Auth::can('process_payment')) {
        $voidType      = $_POST['void_type']      ?? 'order';
        $reason        = trim($_POST['void_reason'] ?? '');
        $ownerPassword = $_POST['owner_password']  ?? '';

        // ── Step 1: Verify owner password ─────────────────────────────────
        if (empty($ownerPassword)) {
            $err = "Owner authorization is required to void an order.";
        } elseif (empty($reason)) {
            $err = "Please provide a reason for voiding.";
        } else {
            // Fetch any active owner account and verify password
            $ownerStmt = $db->prepare("
                SELECT u.password FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE r.name = 'owner' AND u.is_active = 1
                LIMIT 1
            ");
            $ownerStmt->execute();
            $owner = $ownerStmt->fetch();

            if (!$owner || !password_verify($ownerPassword, $owner['password'])) {
                $err = "Incorrect owner password. Void was not processed.";
                AuditLog::record(
                    'void_authorization_failed',
                    "Cashier attempted to void Order #$orderId but provided an incorrect owner password.",
                    'order', $orderId,
                    Auth::user()['id'], Auth::user()['username'], Auth::user()['role_name']
                );
            } else {
                // ── Step 2: Process the void ───────────────────────────────
                $db->beginTransaction();
                try {
                    if ($voidType === 'order') {
                        $stmt = $db->prepare("SELECT total, table_number FROM orders WHERE id=?");
                        $stmt->execute([$orderId]);
                        $order = $stmt->fetch();

                        if (!$order) throw new RuntimeException("Order #$orderId not found.");

                        $voidAmount = $order['total'];

                        $db->prepare("INSERT INTO system_logs (action, order_id, staff_id, reason, amount_voided) VALUES (?,?,?,?,?)")
                           ->execute(['void_order', $orderId, Auth::user()['id'], $reason, $voidAmount]);

                        $db->prepare("DELETE FROM order_items WHERE order_id=?")->execute([$orderId]);
                        $db->prepare("UPDATE orders SET total=0, status='void' WHERE id=?")->execute([$orderId]);

                        $db->commit();
                        $msg = "Order #$orderId voided successfully. Amount: ₱" . number_format($voidAmount, 2);

                        $user = Auth::user();
                        AuditLog::record('order_voided',
                                         "Voided entire Order #$orderId (Table {$order['table_number']}). Amount: ₱" . number_format($voidAmount, 2) . ". Reason: $reason",
                                         'order', $orderId,
                                         $user['id'], $user['username'], $user['role_name']);

                    } else {
                        // Void specific items
                        $rawIds  = $_POST['void_items'] ?? [];
                        $itemIds = array_filter(array_map('intval', is_array($rawIds) ? $rawIds : [$rawIds]));

                        if (empty($itemIds)) throw new RuntimeException("Please select at least one item to void.");

                        $totalVoided = 0;
                        foreach ($itemIds as $itemId) {
                            $stmt = $db->prepare("SELECT quantity, unit_price FROM order_items WHERE id=? AND order_id=?");
                            $stmt->execute([$itemId, $orderId]);
                            $item = $stmt->fetch();

                            if (!$item) throw new RuntimeException("Order item ID $itemId not found in order #$orderId.");

                            $itemAmount   = $item['quantity'] * $item['unit_price'];
                            $totalVoided += $itemAmount;

                            $db->prepare("INSERT INTO system_logs (action, order_id, order_item_id, staff_id, reason, amount_voided) VALUES (?,?,?,?,?,?)")
                               ->execute(['void_item', $orderId, $itemId, Auth::user()['id'], $reason, $itemAmount]);

                            $db->prepare("DELETE FROM order_items WHERE id=?")->execute([$itemId]);
                        }

                        // Recalculate order total
                        $stmt = $db->prepare("SELECT COALESCE(SUM(quantity * unit_price), 0) FROM order_items WHERE order_id=?");
                        $stmt->execute([$orderId]);
                        $newTotal = (float)$stmt->fetchColumn();

                        // Only mark as void if ALL items have been removed
                        $remainingCount = $db->prepare("SELECT COUNT(*) FROM order_items WHERE order_id=?");
                        $remainingCount->execute([$orderId]);
                        $newStatus = (int)$remainingCount->fetchColumn() === 0 ? 'void' : 'open';

                        $db->prepare("UPDATE orders SET total=?, status=? WHERE id=?")->execute([$newTotal, $newStatus, $orderId]);
                        $db->commit();
                        $msg = "Items voided from Order #$orderId. Amount voided: ₱" . number_format($totalVoided, 2);

                        $user = Auth::user();
                        AuditLog::record('order_items_voided',
                                         "Voided " . count($itemIds) . " item(s) from Order #$orderId. Amount: ₱" . number_format($totalVoided, 2) . ". Reason: $reason",
                                         'order', $orderId,
                                         $user['id'], $user['username'], $user['role_name']);
                    }

                } catch (RuntimeException $e) {
                    $db->rollBack();
                    error_log('[my_orders] Void error (order #' . $orderId . '): ' . $e->getMessage());
                    $err = $e->getMessage();
                } catch (Exception $e) {
                    $db->rollBack();
                    error_log('[my_orders] Unexpected void error (order #' . $orderId . '): ' . $e->getMessage());
                    $err = "Failed to void order. Please try again or contact support.";
                }
            }
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
        <th>Add On</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($orders as $o):
        $lineItems = $db->prepare("
            SELECT oi.id, oi.quantity, m.name, oi.unit_price
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
          <?= implode(', ', array_map(fn($l) => $l['name'] . '×' . $l['quantity'], $lines)) ?>
        </td>
        <td style="font-weight:600">₱<?= number_format($o['total'], 2) ?></td>
        <td><span class="badge badge-<?= $o['status'] ?>"><?= ucfirst($o['status']) ?></span></td>
        <td style="font-size:.78rem;color:var(--muted)"><?= date('M j, h:i A', strtotime($o['created_at'])) ?></td>
        <td>
          <?php if ($o['status'] === 'open'): ?>
            <a href="new_order.php?order_id=<?= $o['id'] ?>" class="btn btn-gold btn-sm">➕ Add</a>
          <?php endif; ?>
        </td>
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
          <?php if ($o['status'] === 'open' && Auth::can('process_payment')): ?>
            <button class="btn btn-danger btn-sm"
                    onclick="openVoidModal(<?= $o['id'] ?>, <?= htmlspecialchars(json_encode(array_values($lines))) ?>)">
              ⊘ Void
            </button>
          <?php endif; ?>
          <?php if ($o['status'] === 'void'): ?>
            <span style="color:var(--muted);font-size:.75rem;padding:6px 12px;
                         background:rgba(122,100,72,.3);border-radius:6px">⊘ Voided</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- ── Void Modal ─────────────────────────────────────────────────────── -->
<div id="voidModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;
                            background:rgba(0,0,0,.75);z-index:1000;
                            align-items:center;justify-content:center">
  <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;
              padding:28px;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.6)">

    <h3 style="color:var(--cream);margin-bottom:4px;font-family:'Playfair Display',serif">⊘ Void Order</h3>
    <p style="color:var(--muted);font-size:.82rem;margin-bottom:20px">
      Owner authorization is required to void an order.
    </p>

    <form id="voidForm" method="POST">
      <input type="hidden" name="action"   value="void">
      <input type="hidden" name="order_id" id="voidOrderId">

      <!-- Void type -->
      <label style="display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:1px;
                    color:var(--muted);margin-bottom:8px">Void Type</label>
      <div style="display:flex;gap:12px;margin-bottom:18px">
        <label style="cursor:pointer;color:var(--cream);font-size:.9rem">
          <input type="radio" name="void_type" value="order" checked style="margin-right:6px">
          Entire Order
        </label>
        <label style="cursor:pointer;color:var(--cream);font-size:.9rem">
          <input type="radio" name="void_type" value="items" style="margin-right:6px">
          Specific Items
        </label>
      </div>

      <!-- Items checklist (shown only when "Specific Items" is selected) -->
      <div id="itemsSelection" style="display:none;margin-bottom:16px;max-height:180px;
                                      overflow-y:auto;background:#0e0a06;
                                      border:1px solid var(--border);border-radius:8px;padding:10px"></div>

      <!-- Reason -->
      <label style="display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:1px;
                    color:var(--muted);margin-bottom:6px">Reason for Void</label>
      <textarea name="void_reason" required
                style="width:100%;padding:10px;background:#110d06;border:1px solid var(--border);
                       color:var(--cream);border-radius:8px;font-family:'DM Sans',sans-serif;
                       font-size:.9rem;margin-bottom:16px;min-height:70px;resize:vertical"
                placeholder="e.g. Customer canceled, item out of stock…"></textarea>

      <!-- Owner password -->
      <div style="background:rgba(201,147,58,.06);border:1px solid rgba(201,147,58,.25);
                  border-radius:8px;padding:14px;margin-bottom:18px">
        <label style="display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:1px;
                      color:var(--gold);margin-bottom:6px">🔑 Owner Password</label>
        <input type="password" name="owner_password" required autocomplete="off"
               placeholder="Enter owner password to authorize"
               style="margin-bottom:0;background:#0e0a06;border-color:rgba(201,147,58,.3)">
      </div>

      <div style="display:flex;gap:8px">
        <button class="btn btn-danger" style="flex:1" type="submit">Confirm Void</button>
        <button class="btn btn-outline" style="flex:1" type="button"
                onclick="closeVoidModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openVoidModal(orderId, items) {
    document.getElementById('voidOrderId').value = orderId;

    // Populate items checklist
    const itemsDiv = document.getElementById('itemsSelection');
    itemsDiv.innerHTML = items.map(item => `
        <label style="display:block;padding:7px 4px;border-bottom:1px solid var(--border);
                      cursor:pointer;color:var(--cream);font-size:.85rem">
          <input type="checkbox" name="void_items" value="${item.id}" style="margin-right:8px">
          ${item.name} × ${item.quantity}
          <span style="color:var(--gold);margin-left:4px">
            ₱${(item.quantity * item.unit_price).toFixed(2)}
          </span>
        </label>
    `).join('');

    // Reset form state
    document.querySelector('input[name="void_type"][value="order"]').checked = true;
    itemsDiv.style.display = 'none';
    document.querySelector('textarea[name="void_reason"]').value = '';
    document.querySelector('input[name="owner_password"]').value = '';

    document.getElementById('voidModal').style.display = 'flex';
}

function closeVoidModal() {
    document.getElementById('voidModal').style.display = 'none';
}

// Toggle items list visibility
document.querySelectorAll('input[name="void_type"]').forEach(radio => {
    radio.addEventListener('change', () => {
        document.getElementById('itemsSelection').style.display =
            radio.value === 'items' ? 'block' : 'none';
    });
});

// Form validation before submit
document.getElementById('voidForm').addEventListener('submit', e => {
    const voidType     = document.querySelector('input[name="void_type"]:checked').value;
    const checkedItems = document.querySelectorAll('input[name="void_items"]:checked');
    const reason       = document.querySelector('textarea[name="void_reason"]').value.trim();
    const ownerPwd     = document.querySelector('input[name="owner_password"]').value.trim();

    if (voidType === 'items' && checkedItems.length === 0) {
        e.preventDefault();
        alert('Please select at least one item to void.');
        return;
    }
    if (!reason) {
        e.preventDefault();
        alert('Please provide a reason for voiding.');
        return;
    }
    if (!ownerPwd) {
        e.preventDefault();
        alert('Owner password is required to authorize this void.');
    }
});

// Close modal when clicking backdrop
document.getElementById('voidModal').addEventListener('click', e => {
    if (e.target.id === 'voidModal') closeVoidModal();
});
</script>

</main></div>