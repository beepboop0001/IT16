<?php
require_once __DIR__ . '/../auth/Auth.php';
require_once __DIR__ . '/../config/Database.php';
Auth::init();
Auth::requireLogin();

$db = Database::get();
$u  = Auth::user();

if (Auth::isOwner()) {
    // Owner stats
    $totalRevenue = $db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='paid'")->fetchColumn();
    $totalOrders  = $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $openOrders   = $db->query("SELECT COUNT(*) FROM orders WHERE status='open'")->fetchColumn();
    $totalItems   = $db->query("SELECT COUNT(*) FROM menu_items WHERE is_available=1")->fetchColumn();
    $recentOrders = $db->query("
        SELECT o.*, u.name AS cashier_name
        FROM orders o JOIN users u ON u.id = o.cashier_id
        ORDER BY o.created_at DESC LIMIT 6
    ")->fetchAll();
    $topItems = $db->query("
        SELECT m.name, SUM(oi.quantity) AS qty, SUM(oi.quantity * oi.unit_price) AS revenue
        FROM order_items oi JOIN menu_items m ON m.id = oi.menu_item_id
        GROUP BY m.id ORDER BY qty DESC LIMIT 5
    ")->fetchAll();
} else {
    // Cashier stats
    $myOrders  = $db->prepare("SELECT COUNT(*) FROM orders WHERE cashier_id=?");
    $myOrders->execute([$u['id']]); $myOrders = $myOrders->fetchColumn();
    $myRevenue = $db->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE cashier_id=? AND status='paid'");
    $myRevenue->execute([$u['id']]); $myRevenue = $myRevenue->fetchColumn();
    $openCount = $db->prepare("SELECT COUNT(*) FROM orders WHERE cashier_id=? AND status='open'");
    $openCount->execute([$u['id']]); $openCount = $openCount->fetchColumn();
    $recentMine = $db->prepare("SELECT * FROM orders WHERE cashier_id=? ORDER BY created_at DESC LIMIT 5");
    $recentMine->execute([$u['id']]); $recentMine = $recentMine->fetchAll();
}

include __DIR__ . '/../includes/nav.php';
?>

<div class="page-header">
  <h2><?= Auth::isOwner() ? '📊 Owner Dashboard' : '📊 My Dashboard' ?></h2>
  <p><?= date('l, F j, Y') ?> — Welcome back, <?= htmlspecialchars($u['name']) ?>!</p>
</div>

<?php if (Auth::isOwner()): ?>

<div class="grid-4">
  <div class="card">
    <div class="card-title">Total Revenue</div>
    <div class="card-value">₱<?= number_format($totalRevenue, 2) ?></div>
    <div class="card-sub">From paid orders</div>
  </div>
  <div class="card">
    <div class="card-title">Total Orders</div>
    <div class="card-value"><?= $totalOrders ?></div>
    <div class="card-sub">All time</div>
  </div>
  <div class="card">
    <div class="card-title">Open Orders</div>
    <div class="card-value" style="color:var(--gold)"><?= $openOrders ?></div>
    <div class="card-sub">Awaiting payment</div>
  </div>
  <div class="card">
    <div class="card-title">Menu Items</div>
    <div class="card-value"><?= $totalItems ?></div>
    <div class="card-sub">Currently available</div>
  </div>
</div>

<div class="grid-2">
  <div class="section-card">
    <div class="section-title">Recent Orders</div>
    <table>
      <thead><tr><th>#</th><th>Table</th><th>Cashier</th><th>Total</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($recentOrders as $o): ?>
        <tr>
          <td style="color:var(--muted)">#<?= $o['id'] ?></td>
          <td>Table <?= $o['table_number'] ?></td>
          <td><?= htmlspecialchars($o['cashier_name']) ?></td>
          <td>₱<?= number_format($o['total'],2) ?></td>
          <td><span class="badge badge-<?= $o['status'] ?>"><?= ucfirst($o['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="section-card">
    <div class="section-title">Top Selling Items</div>
    <table>
      <thead><tr><th>Item</th><th>Qty Sold</th><th>Revenue</th></tr></thead>
      <tbody>
      <?php foreach ($topItems as $i): ?>
        <tr>
          <td><?= htmlspecialchars($i['name']) ?></td>
          <td><?= $i['qty'] ?></td>
          <td>₱<?= number_format($i['revenue'],2) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: /* CASHIER DASHBOARD */ ?>

<div class="grid-4">
  <div class="card">
    <div class="card-title">My Orders Today</div>
    <div class="card-value"><?= $myOrders ?></div>
    <div class="card-sub">Total handled</div>
  </div>
  <div class="card">
    <div class="card-title">Revenue Collected</div>
    <div class="card-value">₱<?= number_format($myRevenue,2) ?></div>
    <div class="card-sub">Paid orders</div>
  </div>
  <div class="card">
    <div class="card-title">Open Orders</div>
    <div class="card-value" style="color:var(--gold)"><?= $openCount ?></div>
    <div class="card-sub">Unpaid</div>
  </div>
</div>

<div class="section-card" style="max-width:600px">
  <div class="section-title">My Recent Orders</div>
  <?php if (empty($recentMine)): ?>
    <p style="color:var(--muted);font-size:.85rem">No orders yet. <a href="new_order.php" style="color:var(--gold)">Create one →</a></p>
  <?php else: ?>
    <table>
      <thead><tr><th>#</th><th>Table</th><th>Total</th><th>Status</th><th>Time</th></tr></thead>
      <tbody>
      <?php foreach ($recentMine as $o): ?>
        <tr>
          <td style="color:var(--muted)">#<?= $o['id'] ?></td>
          <td>Table <?= $o['table_number'] ?></td>
          <td>₱<?= number_format($o['total'],2) ?></td>
          <td><span class="badge badge-<?= $o['status'] ?>"><?= ucfirst($o['status']) ?></span></td>
          <td style="color:var(--muted);font-size:.78rem"><?= date('h:i A', strtotime($o['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <div style="margin-top:14px">
    <a href="new_order.php" class="btn btn-gold">➕ New Order</a>
  </div>
</div>

<?php endif; ?>

</main></div>
