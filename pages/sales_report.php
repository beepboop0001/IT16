<?php
require_once __DIR__ . '/../auth/Auth.php';
require_once __DIR__ . '/../config/Database.php';
Auth::init();
Auth::requireLogin();
Auth::requirePermission('view_sales_report');

$db = Database::get();

$totalRevenue   = $db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='paid'")->fetchColumn();
$totalOrders    = $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$paidOrders     = $db->query("SELECT COUNT(*) FROM orders WHERE status='paid'")->fetchColumn();
$refundedOrders = $db->query("SELECT COUNT(*) FROM orders WHERE status='refunded'")->fetchColumn();
$refundAmount   = $db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='refunded'")->fetchColumn();

$byCashier = $db->query("
    SELECT u.name, COUNT(o.id) AS orders,
           SUM(CASE WHEN o.status='paid' THEN o.total ELSE 0 END) AS revenue,
           SUM(CASE WHEN o.status='refunded' THEN 1 ELSE 0 END) AS refunds
    FROM users u
    LEFT JOIN orders o ON o.cashier_id = u.id
    WHERE u.role_id = (SELECT id FROM roles WHERE name='cashier')
    GROUP BY u.id ORDER BY revenue DESC
")->fetchAll();

$topItems = $db->query("
    SELECT m.name, m.category, SUM(oi.quantity) AS qty,
           SUM(oi.quantity * oi.unit_price) AS revenue
    FROM order_items oi
    JOIN menu_items m ON m.id = oi.menu_item_id
    JOIN orders o ON o.id = oi.order_id
    WHERE o.status = 'paid'
    GROUP BY m.id ORDER BY revenue DESC LIMIT 8
")->fetchAll();

include __DIR__ . '/../includes/nav.php';
?>

<div class="page-header">
  <h2>📈 Sales Report</h2>
  <p>Revenue breakdown and performance overview</p>
</div>

<div class="grid-4" style="margin-bottom:24px">
  <div class="card"><div class="card-title">Total Revenue</div>
    <div class="card-value">₱<?= number_format($totalRevenue,2) ?></div>
    <div class="card-sub">From <?= $paidOrders ?> paid orders</div></div>
  <div class="card"><div class="card-title">Total Orders</div>
    <div class="card-value"><?= $totalOrders ?></div>
    <div class="card-sub">All statuses</div></div>
  <div class="card"><div class="card-title">Refunds Issued</div>
    <div class="card-value" style="color:var(--red)"><?= $refundedOrders ?></div>
    <div class="card-sub">₱<?= number_format($refundAmount,2) ?> refunded</div></div>
  <div class="card"><div class="card-title">Avg Order Value</div>
    <div class="card-value">₱<?= $paidOrders ? number_format($totalRevenue/$paidOrders,2) : '0.00' ?></div>
    <div class="card-sub">Per paid order</div></div>
</div>

<div class="grid-2">
  <div class="section-card">
    <div class="section-title">Revenue by Cashier</div>
    <table>
      <thead><tr><th>Cashier</th><th>Orders</th><th>Revenue</th><th>Refunds</th></tr></thead>
      <tbody>
      <?php foreach ($byCashier as $row): ?>
        <tr>
          <td><?= htmlspecialchars($row['name']) ?></td>
          <td><?= $row['orders'] ?></td>
          <td style="color:var(--gold-light);font-weight:600">₱<?= number_format($row['revenue'],2) ?></td>
          <td style="color:<?= $row['refunds'] > 0 ? 'var(--red)' : 'var(--muted)' ?>">
            <?= $row['refunds'] ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="section-card">
    <div class="section-title">Top Selling Items</div>
    <table>
      <thead><tr><th>Item</th><th>Category</th><th>Qty Sold</th><th>Revenue</th></tr></thead>
      <tbody>
      <?php foreach ($topItems as $i): ?>
        <tr>
          <td><?= htmlspecialchars($i['name']) ?></td>
          <td style="color:var(--muted);font-size:.78rem"><?= htmlspecialchars($i['category']) ?></td>
          <td><?= $i['qty'] ?></td>
          <td style="color:var(--gold-light)">₱<?= number_format($i['revenue'],2) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

</main></div>
