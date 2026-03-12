<?php
require_once __DIR__ . '/../auth/Auth.php';
require_once __DIR__ . '/../auth/AuditLog.php';
require_once __DIR__ . '/../config/Database.php';
Auth::init();
Auth::requireLogin();
Auth::requirePermission('take_order');

$db  = Database::get();
$msg = $err = '';
$existingOrderId = (int)($_GET['order_id'] ?? 0);

// Validate existing order if provided
if ($existingOrderId > 0) {
    $existing = $db->prepare("SELECT * FROM orders WHERE id=? AND status='open' AND cashier_id=?");
    $existing->execute([$existingOrderId, Auth::user()['id']]);
    $existing = $existing->fetch();
    if (!$existing) {
        $err = "Cannot add to this order. Order not found or already paid/refunded.";
        $existingOrderId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $items = $_POST['items'] ?? [];
    $items = array_filter($items, fn($q) => (int)$q > 0);

    if (empty($items)) {
        $err = "Please add at least one item.";
    } else {
        $db->beginTransaction();
        try {
            if ($existingOrderId > 0) {
                // Adding items to existing order
                $orderId = $existingOrderId;
                $stmt = $db->prepare("SELECT total FROM orders WHERE id=?");
                $stmt->execute([$orderId]);
                $currentTotal = (float)$stmt->fetchColumn();
                $addedTotal = 0;

                foreach ($items as $menuId => $qty) {
                    $menuId = (int)$menuId;
                    $qty    = (int)$qty;
                    $priceStmt = $db->prepare("SELECT price FROM menu_items WHERE id=? AND is_available=1");
                    $priceStmt->execute([$menuId]);
                    $price = $priceStmt->fetchColumn();

                    if ($price === false) {
                        throw new RuntimeException("Menu item ID {$menuId} is unavailable or does not exist.");
                    }
                    $price = (float)$price;

                    $db->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price) VALUES (?,?,?,?)")
                       ->execute([$orderId, $menuId, $qty, $price]);
                    $addedTotal += $price * $qty;
                }

                $db->prepare("UPDATE orders SET total=? WHERE id=?")
                   ->execute([$currentTotal + $addedTotal, $orderId]);
                $db->commit();
                $msg = "Items added to Order #$orderId! New total: ₱" . number_format($currentTotal + $addedTotal, 2);
                $user = Auth::user();
                AuditLog::record('order_items_added',
                                 "Added items to Order #$orderId for table {$existing['table_number']} (+₱" . number_format($addedTotal, 2) . ")",
                                 'order', $orderId,
                                 $user['id'], $user['username'], $user['role_name']);

            } else {
                // Creating new order
                $table = (int)($_POST['table_number'] ?? 0);
                if ($table < 1) {
                    $err = "Please enter a valid table number.";
                } else {
                    $stmt = $db->prepare("INSERT INTO orders (cashier_id, table_number, total) VALUES (?,?,0)");
                    $stmt->execute([Auth::user()['id'], $table]);
                    $orderId = $db->lastInsertId();
                    $total   = 0;

                    foreach ($items as $menuId => $qty) {
                        $menuId = (int)$menuId;
                        $qty    = (int)$qty;
                        $priceStmt = $db->prepare("SELECT price FROM menu_items WHERE id=? AND is_available=1");
                        $priceStmt->execute([$menuId]);
                        $price = $priceStmt->fetchColumn();

                        if ($price === false) {
                            throw new RuntimeException("Menu item ID {$menuId} is unavailable or does not exist.");
                        }
                        $price = (float)$price;

                        $db->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price) VALUES (?,?,?,?)")
                           ->execute([$orderId, $menuId, $qty, $price]);
                        $total += $price * $qty;
                    }

                    $db->prepare("UPDATE orders SET total=? WHERE id=?")->execute([$total, $orderId]);
                    $db->commit();
                    $msg = "Order #$orderId created! Total: ₱" . number_format($total, 2);
                    $user = Auth::user();
                    AuditLog::record('order_created',
                                     "Created order #$orderId for table {$table} with " . count($items) . " item(s) (₱" . number_format($total, 2) . ")",
                                     'order', $orderId,
                                     $user['id'], $user['username'], $user['role_name']);
                }
            }
        } catch (RuntimeException $e) {
            $db->rollBack();
            // Safe user-facing message; real reason logged
            error_log('[new_order] Order processing error: ' . $e->getMessage());
            $err = $e->getMessage(); // RuntimeException messages are intentionally user-safe here
        } catch (Exception $e) {
            $db->rollBack();
            error_log('[new_order] Unexpected error: ' . $e->getMessage());
            $err = "Failed to process order. Please try again or contact support.";
        }
    }
}

$menuItems = $db->query("SELECT * FROM menu_items WHERE is_available=1 ORDER BY category,name")->fetchAll();
$grouped = [];
foreach ($menuItems as $item) $grouped[$item['category']][] = $item;

include __DIR__ . '/../includes/nav.php';
?>

<div class="page-header">
  <h2><?= $existingOrderId ? '➕ Add Items' : '➕ New Order' ?></h2>
  <p><?= $existingOrderId ? 'Add more items to Order #' . $existingOrderId : 'Select items and assign to a table' ?></p>
</div>

<?php if ($msg): ?><div class="alert alert-success">✔ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error">⚠ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<form method="POST">
<div class="grid-2">
  <div>
    <?php foreach ($grouped as $cat => $catItems): ?>
    <div class="section-card" style="margin-bottom:16px">
      <div class="section-title"><?= htmlspecialchars($cat) ?></div>
      <?php foreach ($catItems as $item): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;
                  padding:8px 0;border-bottom:1px solid var(--border)">
        <div>
          <div style="font-size:.9rem"><?= htmlspecialchars($item['name']) ?></div>
          <div style="font-size:.78rem;color:var(--gold)">₱<?= number_format($item['price'],2) ?></div>
        </div>
        <input type="number" name="items[<?= $item['id'] ?>]" min="0" max="20" value="0"
               style="width:64px;text-align:center;margin:0"
               oninput="updateTotal()">
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div>
    <div class="section-card" style="position:sticky;top:20px">
      <div class="section-title">Order Summary</div>
      <?php if ($existingOrderId === 0): ?>
      <label class="form-label">Table Number</label>
      <input type="number" name="table_number" min="1" max="50" placeholder="e.g. 5" required style="margin-bottom:18px">
      <?php endif; ?>
      <div id="order-summary" style="margin-bottom:16px;min-height:40px;font-size:.85rem;color:var(--muted)">
        No items selected yet.
      </div>
      <div style="border-top:1px solid var(--border);padding-top:14px;margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;font-family:'Playfair Display',serif;font-size:1.3rem">
          <span>Total</span>
          <span id="total-display" style="color:var(--gold-light)">₱0.00</span>
        </div>
      </div>
      <button class="btn btn-gold" style="width:100%" type="submit">Place Order</button>
    </div>
  </div>
</div>
</form>

<script>
const prices = <?= json_encode(array_column($menuItems, 'price', 'id')) ?>;
const names  = <?= json_encode(array_column($menuItems, 'name', 'id')) ?>;

function updateTotal() {
    let total = 0, lines = [];
    document.querySelectorAll('input[name^="items["]').forEach(input => {
        const qty = parseInt(input.value) || 0;
        if (qty > 0) {
            const id  = input.name.match(/\[(\d+)\]/)[1];
            const sub = qty * parseFloat(prices[id]);
            total += sub;
            lines.push(`${names[id]} × ${qty} = ₱${sub.toFixed(2)}`);
        }
    });
    document.getElementById('order-summary').innerHTML =
        lines.length ? lines.map(l=>`<div style="padding:3px 0;border-bottom:1px solid var(--border)">${l}</div>`).join('') : 'No items selected yet.';
    document.getElementById('total-display').textContent = '₱' + total.toFixed(2);
}
</script>

</main></div>