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
$receiptOrderId = 0; // triggers receipt modal after pay

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
            $receiptOrderId = $orderId; // show receipt modal

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

        if (empty($ownerPassword)) {
            $err = "Owner authorization is required to void an order.";
        } elseif (empty($reason)) {
            $err = "Please provide a reason for voiding.";
        } else {
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

                        $stmt = $db->prepare("SELECT COALESCE(SUM(quantity * unit_price), 0) FROM order_items WHERE order_id=?");
                        $stmt->execute([$orderId]);
                        $newTotal = (float)$stmt->fetchColumn();

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

// Pre-fetch line items for every order + build receipt data for paid orders
$allLines   = [];
$receiptData = [];
foreach ($orders as $o) {
    $li = $db->prepare("
        SELECT oi.id, oi.quantity, oi.unit_price, m.name
        FROM order_items oi JOIN menu_items m ON m.id = oi.menu_item_id
        WHERE oi.order_id = ?
    ");
    $li->execute([$o['id']]);
    $allLines[$o['id']] = $li->fetchAll();

    if ($o['status'] === 'paid') {
        $receiptData[$o['id']] = [
            'id'         => $o['id'],
            'table'      => $o['table_number'],
            'cashier'    => $o['cashier_name'],
            'total'      => $o['total'],
            'created_at' => $o['created_at'],
            'items'      => $allLines[$o['id']],
        ];
    }
}

include __DIR__ . '/../includes/nav.php';
?>

<style>
@media print {
    body * { visibility: hidden; }
    #receiptPrintArea, #receiptPrintArea * { visibility: visible; }
    #receiptPrintArea {
        position: fixed; top: 0; left: 0;
        width: 80mm;
        padding: 10px;
        font-family: 'Courier New', monospace;
        font-size: 12px;
        color: #000;
        background: #fff;
    }
}
</style>

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
        <td>
          <?php if ($o['status'] === 'open'): ?>
            <a href="new_order.php?order_id=<?= $o['id'] ?>" class="btn btn-gold btn-sm">➕ Add</a>
          <?php endif; ?>
        </td>
        <td style="display:flex;gap:6px;flex-wrap:wrap">
          <?php if ($o['status'] === 'open' && Auth::can('process_payment')): ?>
            <button class="btn btn-gold btn-sm"
                    onclick="openConfirmModal('pay', <?= $o['id'] ?>, <?= $o['table_number'] ?>, '<?= number_format($o['total'], 2) ?>')">
              💳 Pay
            </button>
          <?php endif; ?>
          <?php if ($o['status'] === 'paid'): ?>
            <button class="btn btn-outline btn-sm"
                    onclick="openReceiptModal(<?= $o['id'] ?>)">🧾 Receipt</button>
          <?php endif; ?>
          <?php if ($o['status'] === 'paid' && Auth::can('process_refund')): ?>
            <button class="btn btn-danger btn-sm"
                    onclick="openConfirmModal('refund', <?= $o['id'] ?>, <?= $o['table_number'] ?>, '<?= number_format($o['total'], 2) ?>')">
              ↩ Refund
            </button>
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

<!-- ── Confirm Modal (Pay / Refund) ───────────────────────────────────── -->
<div id="confirmModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;
                               background:rgba(0,0,0,.80);z-index:1000;
                               align-items:center;justify-content:center">
  <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;
              padding:28px;max-width:380px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.6)">

    <div id="confirmIcon" style="font-size:2.5rem;text-align:center;margin-bottom:12px"></div>
    <h3 id="confirmTitle" style="font-family:'Playfair Display',serif;color:var(--cream);
                                  text-align:center;margin-bottom:8px"></h3>
    <p id="confirmMessage" style="color:var(--muted);font-size:.87rem;text-align:center;
                                   line-height:1.6;margin-bottom:24px"></p>

    <!-- Hidden form that gets submitted on confirm -->
    <form id="confirmForm" method="POST">
      <input type="hidden" name="action"   id="confirmAction">
      <input type="hidden" name="order_id" id="confirmOrderId">
      <div style="display:flex;gap:8px">
        <button id="confirmBtn" class="btn" style="flex:1" type="submit"></button>
        <button class="btn btn-outline" style="flex:1" type="button"
                onclick="closeConfirmModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Receipt Modal ──────────────────────────────────────────────────── -->
<div id="receiptModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;
                               background:rgba(0,0,0,.82);z-index:1000;
                               align-items:center;justify-content:center">
  <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;
              padding:28px;max-width:400px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.6)">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
      <h3 style="font-family:'Playfair Display',serif;color:var(--cream)">🧾 Order Receipt</h3>
      <button onclick="closeReceiptModal()"
              style="background:none;border:none;color:var(--muted);font-size:1.4rem;
                     cursor:pointer;line-height:1;padding:0">✕</button>
    </div>

    <!-- Receipt paper area (also used for print) -->
    <div id="receiptPrintArea"
         style="background:#fff;color:#111;border-radius:8px;padding:18px 16px;
                font-family:'Courier New',monospace;font-size:12.5px;line-height:1.65;
                max-height:60vh;overflow-y:auto">

      <div style="text-align:center;margin-bottom:14px">
        <div style="font-size:1.5rem">🍽️</div>
        <div style="font-weight:700;font-size:1.05rem;letter-spacing:2px">KUSINERO</div>
        <div style="font-size:.75rem;color:#666">Restaurant Management System</div>
        <div style="margin:10px 0;border-top:1px dashed #bbb"></div>
      </div>

      <div id="receiptMeta" style="margin-bottom:10px;font-size:.8rem"></div>

      <div style="border-top:1px dashed #bbb;border-bottom:1px dashed #bbb;padding:8px 0;margin:10px 0">
        <div style="display:flex;justify-content:space-between;
                    font-size:.7rem;font-weight:700;color:#888;margin-bottom:6px;letter-spacing:.5px">
          <span>ITEM</span><span>QTY × PRICE = SUBTOTAL</span>
        </div>
        <div id="receiptItems"></div>
      </div>

      <div id="receiptTotal"
           style="display:flex;justify-content:space-between;
                  font-weight:700;font-size:1rem;padding:4px 0 10px"></div>

      <div style="text-align:center;font-size:.72rem;color:#888;
                  border-top:1px dashed #bbb;padding-top:10px;margin-top:4px">
        <div style="font-weight:600">Thank you for dining with us!</div>
        <div>Please come again 😊</div>
        <div style="margin-top:6px;color:#bbb">— Kusinero —</div>
      </div>
    </div>

    <div style="display:flex;gap:8px;margin-top:16px">
      <button class="btn btn-gold" style="flex:1" onclick="printReceipt()">🖨️ Print Receipt</button>
      <button class="btn btn-outline" style="flex:1" onclick="closeReceiptModal()">Close</button>
    </div>
  </div>
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

      <div id="itemsSelection" style="display:none;margin-bottom:16px;max-height:180px;
                                      overflow-y:auto;background:#0e0a06;
                                      border:1px solid var(--border);border-radius:8px;padding:10px"></div>

      <label style="display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:1px;
                    color:var(--muted);margin-bottom:6px">Reason for Void</label>
      <textarea name="void_reason" required
                style="width:100%;padding:10px;background:#110d06;border:1px solid var(--border);
                       color:var(--cream);border-radius:8px;font-family:'DM Sans',sans-serif;
                       font-size:.9rem;margin-bottom:16px;min-height:70px;resize:vertical"
                placeholder="e.g. Customer canceled, item out of stock…"></textarea>

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
const RECEIPT_DATA = <?= json_encode($receiptData) ?>;
const AUTO_RECEIPT = <?= $receiptOrderId ?: 'null' ?>;

// ── Confirm Modal (Pay / Refund) ───────────────────────────────────────
const CONFIRM_CONFIG = {
    pay: {
        icon:    '💳',
        title:   'Confirm Payment',
        message: (table, total) => `Mark Order for <strong>Table ${table}</strong> as paid?<br><span style="color:var(--gold-light);font-size:1.05rem;font-weight:700">₱${total}</span> will be collected.`,
        btnText:  'Yes, Mark as Paid',
        btnStyle: 'background:var(--gold);color:#0e0a06',
    },
    refund: {
        icon:    '↩',
        title:   'Confirm Refund',
        message: (table, total) => `Refund the order for <strong>Table ${table}</strong>?<br><span style="color:#e8937a;font-size:1.05rem;font-weight:700">₱${total}</span> will be refunded.`,
        btnText:  'Yes, Refund Order',
        btnStyle: 'background:rgba(224,92,58,.8);color:#fff;border:none',
    },
};

function openConfirmModal(action, orderId, table, total) {
    const cfg = CONFIRM_CONFIG[action];
    document.getElementById('confirmIcon').textContent    = cfg.icon;
    document.getElementById('confirmTitle').textContent   = cfg.title;
    document.getElementById('confirmMessage').innerHTML   = cfg.message(table, total);
    document.getElementById('confirmAction').value        = action;
    document.getElementById('confirmOrderId').value       = orderId;
    document.getElementById('confirmBtn').textContent     = cfg.btnText;
    document.getElementById('confirmBtn').style.cssText   = cfg.btnStyle + ';flex:1;padding:9px 18px;border-radius:7px;font-family:\'DM Sans\',sans-serif;font-size:.85rem;font-weight:600;cursor:pointer';
    document.getElementById('confirmModal').style.display = 'flex';
}

function closeConfirmModal() {
    document.getElementById('confirmModal').style.display = 'none';
}

document.getElementById('confirmModal').addEventListener('click', e => {
    if (e.target.id === 'confirmModal') closeConfirmModal();
});

// ── Receipt ────────────────────────────────────────────────────────────
function openReceiptModal(orderId) {
    const r = RECEIPT_DATA[orderId];
    if (!r) return;

    const date    = new Date(r.created_at.replace(' ', 'T'));
    const dateStr = date.toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' });
    const timeStr = date.toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit', hour12:true });

    document.getElementById('receiptMeta').innerHTML = `
        <table style="width:100%;font-size:.8rem;border-collapse:collapse">
          <tr><td style="color:#666;width:50%">Order #</td><td><strong>#${r.id}</strong></td></tr>
          <tr><td style="color:#666">Table</td><td><strong>Table ${r.table}</strong></td></tr>
          <tr><td style="color:#666">Cashier</td><td>${r.cashier}</td></tr>
          <tr><td style="color:#666">Date</td><td>${dateStr}</td></tr>
          <tr><td style="color:#666">Time</td><td>${timeStr}</td></tr>
          <tr><td style="color:#666">Status</td><td style="color:green;font-weight:700">✔ PAID</td></tr>
        </table>
    `;

    document.getElementById('receiptItems').innerHTML = r.items.length
        ? r.items.map(item => {
            const sub = (item.quantity * item.unit_price).toFixed(2);
            return `
                <div style="margin-bottom:6px">
                  <div style="font-weight:600">${item.name}</div>
                  <div style="display:flex;justify-content:space-between;
                              font-size:.78rem;color:#555;padding-left:10px">
                    <span>× ${item.quantity}</span>
                    <span>₱${parseFloat(item.unit_price).toFixed(2)} &nbsp;=&nbsp; ₱${sub}</span>
                  </div>
                </div>`;
          }).join('')
        : '<div style="color:#999;font-size:.8rem">No items recorded.</div>';

    document.getElementById('receiptTotal').innerHTML = `
        <span style="letter-spacing:1px">TOTAL AMOUNT</span>
        <span style="font-size:1.1rem">₱${parseFloat(r.total).toFixed(2)}</span>
    `;

    document.getElementById('receiptModal').style.display = 'flex';
}

function closeReceiptModal() {
    document.getElementById('receiptModal').style.display = 'none';
}

function printReceipt() {
    window.print();
}

document.getElementById('receiptModal').addEventListener('click', e => {
    if (e.target.id === 'receiptModal') closeReceiptModal();
});

// Auto-open receipt right after payment
if (AUTO_RECEIPT) {
    document.addEventListener('DOMContentLoaded', () => openReceiptModal(AUTO_RECEIPT));
}

// ── Void ───────────────────────────────────────────────────────────────
function openVoidModal(orderId, items) {
    document.getElementById('voidOrderId').value = orderId;

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

    document.querySelector('input[name="void_type"][value="order"]').checked = true;
    itemsDiv.style.display = 'none';
    document.querySelector('textarea[name="void_reason"]').value = '';
    document.querySelector('input[name="owner_password"]').value = '';
    document.getElementById('voidModal').style.display = 'flex';
}

function closeVoidModal() {
    document.getElementById('voidModal').style.display = 'none';
}

document.querySelectorAll('input[name="void_type"]').forEach(radio => {
    radio.addEventListener('change', () => {
        document.getElementById('itemsSelection').style.display =
            radio.value === 'items' ? 'block' : 'none';
    });
});

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

document.getElementById('voidModal').addEventListener('click', e => {
    if (e.target.id === 'voidModal') closeVoidModal();
});
</script>

</main></div>