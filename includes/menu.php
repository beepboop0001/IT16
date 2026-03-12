<?php
require_once __DIR__ . '/../auth/Auth.php';
require_once __DIR__ . '/../auth/AuditLog.php';
require_once __DIR__ . '/../config/Database.php';
Auth::init();
Auth::requireLogin();
Auth::requirePermission('manage_menu');

$db  = Database::get();
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name     = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $price    = floatval($_POST['price'] ?? 0);
        if ($name && $category && $price > 0) {
            $stmt = $db->prepare("INSERT INTO menu_items (name,category,price) VALUES (?,?,?)");
            $stmt->execute([$name, $category, $price]);
            $newId = (int)$db->lastInsertId();
            $msg = "Item \"$name\" added successfully.";
            AuditLog::record('menu_item_added', "Added menu item \"$name\" (₱{$price}) in category \"{$category}\".",
                             'menu_item', $newId);
        } else {
            $err = "Please fill all fields correctly.";
        }

    } elseif ($action === 'toggle') {
        $id = (int)$_POST['item_id'];
        $item = $db->prepare("SELECT name, is_available FROM menu_items WHERE id=?");
        $item->execute([$id]); $item = $item->fetch();
        $db->prepare("UPDATE menu_items SET is_available = NOT is_available WHERE id=?")->execute([$id]);
        $newState = $item['is_available'] ? 'hidden' : 'available';
        $msg = "Item availability updated.";
        AuditLog::record('menu_item_toggled', "Menu item \"{$item['name']}\" set to {$newState}.",
                         'menu_item', $id);

    } elseif ($action === 'delete') {
        $id = (int)$_POST['item_id'];
        $item = $db->prepare("SELECT name FROM menu_items WHERE id=?");
        $item->execute([$id]); $item = $item->fetch();
        $db->prepare("DELETE FROM menu_items WHERE id=?")->execute([$id]);
        $msg = "Item deleted.";
        AuditLog::record('menu_item_deleted', "Deleted menu item \"{$item['name']}\".", 'menu_item', $id);
    }
}

$categories = ['Main Course','Noodles','Side','Dessert','Drinks','Snacks','Beverages'];
$items = $db->query("SELECT * FROM menu_items ORDER BY category, name")->fetchAll();
$grouped = [];
foreach ($items as $item) $grouped[$item['category']][] = $item;

include __DIR__ . '/nav.php';
?>

<div class="page-header">
  <h2>📋 Menu Management</h2>
  <p>Add, remove, or toggle availability of menu items</p>
</div>

<?php if ($msg): ?><div class="alert alert-success">✔ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error">⚠ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="grid-2">
  <div class="section-card" style="max-width:380px">
    <div class="section-title">Add New Item</div>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <label class="form-label">Item Name</label>
      <input type="text" name="name" placeholder="e.g. Beef Caldereta" required>
      <label class="form-label">Category</label>
      <select name="category">
        <?php foreach ($categories as $c): ?>
          <option><?= $c ?></option>
        <?php endforeach; ?>
      </select>
      <label class="form-label">Price (₱)</label>
      <input type="number" name="price" min="1" step="0.50" placeholder="0.00" required>
      <button class="btn btn-gold" style="width:100%" type="submit">Add to Menu</button>
    </form>
  </div>

  <div class="section-card">
    <div class="section-title">Current Menu (<?= count($items) ?> items)</div>
    <?php if (empty($grouped)): ?>
      <p style="color:var(--muted)">No items yet.</p>
    <?php endif; ?>
    <?php foreach ($grouped as $cat => $catItems): ?>
      <div style="margin-bottom:18px">
        <div style="font-size:.7rem;letter-spacing:1.5px;text-transform:uppercase;
                    color:var(--gold);margin-bottom:8px"><?= htmlspecialchars($cat) ?></div>
        <table>
          <thead><tr><th>Name</th><th>Price</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($catItems as $item): ?>
            <tr>
              <td><?= htmlspecialchars($item['name']) ?></td>
              <td>₱<?= number_format($item['price'],2) ?></td>
              <td>
                <?= $item['is_available']
                    ? '<span class="badge badge-paid">Available</span>'
                    : '<span class="badge badge-refunded">Hidden</span>' ?>
              </td>
              <td style="display:flex;gap:6px">
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                  <button class="btn btn-outline btn-sm" type="submit">
                    <?= $item['is_available'] ? 'Hide' : 'Show' ?>
                  </button>
                </form>
                <form method="POST" style="display:inline"
                      onsubmit="return confirm('Delete this item?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Del</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>
  </div>
</div>

</main></div>