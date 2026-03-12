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

        // ── Server-side validation ────────────────────────────────────────
        if (!$name || !$category || $price <= 0) {
            $err = "Please fill all fields correctly.";
        } elseif (!preg_match('/^[a-zA-Z\s\-]+$/', $name)) {
            $err = "Item name must contain letters only (no numbers or special characters).";
        } else {
            $stmt = $db->prepare("INSERT INTO menu_items (name,category,price) VALUES (?,?,?)");
            $stmt->execute([$name, $category, $price]);
            $newId = (int)$db->lastInsertId();
            $msg = "Item \"$name\" added successfully.";
            AuditLog::record('menu_item_added',
                             "Added menu item \"$name\" (₱{$price}) in category \"{$category}\".",
                             'menu_item', $newId);
        }

    } elseif ($action === 'toggle') {
        $id = (int)$_POST['item_id'];
        $item = $db->prepare("SELECT name, is_available FROM menu_items WHERE id=?");
        $item->execute([$id]); $item = $item->fetch();
        $db->prepare("UPDATE menu_items SET is_available = NOT is_available WHERE id=?")->execute([$id]);
        $newState = $item['is_available'] ? 'hidden' : 'available';
        $msg = "Item availability updated.";
        AuditLog::record('menu_item_toggled',
                         "Menu item \"{$item['name']}\" set to {$newState}.",
                         'menu_item', $id);

    } elseif ($action === 'delete') {
        $id = (int)$_POST['item_id'];
        $item = $db->prepare("SELECT name FROM menu_items WHERE id=?");
        $item->execute([$id]); $item = $item->fetch();
        $db->prepare("DELETE FROM menu_items WHERE id=?")->execute([$id]);
        $msg = "Item deleted.";
        AuditLog::record('menu_item_deleted',
                         "Deleted menu item \"{$item['name']}\".",
                         'menu_item', $id);
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

  <!-- ── Add New Item Form ────────────────────────────────────────────── -->
  <div class="section-card" style="max-width:380px">
    <div class="section-title">Add New Item</div>

    <label class="form-label">Item Name</label>
    <input type="text" id="addName" placeholder="e.g. Beef Caldereta"
           oninput="validateItemName(this)"
           style="margin-bottom:4px">
    <div id="nameError"
         style="display:none;color:#e8937a;font-size:.75rem;margin-bottom:10px">
      ⚠ Item name must contain letters only (no numbers or special characters).
    </div>

    <label class="form-label">Category</label>
    <select id="addCategory">
      <?php foreach ($categories as $c): ?>
        <option><?= $c ?></option>
      <?php endforeach; ?>
    </select>

    <label class="form-label">Price (₱)</label>
    <input type="number" id="addPrice" min="1" step="0.50" placeholder="0.00">

    <button class="btn btn-gold" style="width:100%;margin-top:4px"
            type="button" onclick="openAddModal()">
      Add to Menu
    </button>
  </div>

  <!-- ── Current Menu ─────────────────────────────────────────────────── -->
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

<!-- ── Add Menu Item Confirmation Modal ──────────────────────────────── -->
<div id="addMenuModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;
                               background:rgba(0,0,0,.80);z-index:1000;
                               align-items:center;justify-content:center">
  <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;
              padding:28px;max-width:400px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.6)">

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
      <h3 style="font-family:'Playfair Display',serif;color:var(--cream)">📋 Confirm Add Item</h3>
      <button onclick="closeAddModal()"
              style="background:none;border:none;color:var(--muted);font-size:1.4rem;
                     cursor:pointer;line-height:1;padding:0">✕</button>
    </div>

    <!-- Item preview -->
    <div style="background:rgba(201,147,58,.07);border:1px solid rgba(201,147,58,.25);
                border-radius:8px;padding:14px;margin-bottom:20px;font-size:.87rem;line-height:1.9">
      <table style="width:100%;border-collapse:collapse">
        <tr>
          <td style="color:var(--muted);width:40%">Item Name</td>
          <td><strong id="previewName" style="color:var(--cream)"></strong></td>
        </tr>
        <tr>
          <td style="color:var(--muted)">Category</td>
          <td id="previewCategory" style="color:var(--cream)"></td>
        </tr>
        <tr>
          <td style="color:var(--muted)">Price</td>
          <td><strong id="previewPrice" style="color:var(--gold-light);font-size:1rem"></strong></td>
        </tr>
      </table>
    </div>

    <!-- Hidden form submitted on confirm -->
    <form id="addMenuForm" method="POST">
      <input type="hidden" name="action"   value="add">
      <input type="hidden" name="name"     id="hiddenName">
      <input type="hidden" name="category" id="hiddenCategory">
      <input type="hidden" name="price"    id="hiddenPrice">

      <div style="display:flex;gap:8px">
        <button class="btn btn-gold" style="flex:1" type="submit">✔ Add to Menu</button>
        <button class="btn btn-outline" style="flex:1" type="button"
                onclick="closeAddModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Live validation: letters, spaces, hyphens only ────────────────────
function validateItemName(input) {
    const valid = /^[a-zA-Z\s\-]*$/.test(input.value);
    const errDiv = document.getElementById('nameError');
    if (!valid) {
        // Strip invalid characters in real time
        input.value = input.value.replace(/[^a-zA-Z\s\-]/g, '');
        errDiv.style.display = 'block';
        input.style.borderColor = '#e05c3a';
    } else {
        errDiv.style.display = 'none';
        input.style.borderColor = input.value.trim() ? 'var(--gold)' : 'var(--border)';
    }
}

// ── Open add confirmation modal ───────────────────────────────────────
function openAddModal() {
    const name     = document.getElementById('addName').value.trim();
    const category = document.getElementById('addCategory').value;
    const price    = parseFloat(document.getElementById('addPrice').value);

    // Validate before opening modal
    if (!name) {
        document.getElementById('addName').focus();
        document.getElementById('addName').style.borderColor = '#e05c3a';
        return;
    }
    if (!/^[a-zA-Z\s\-]+$/.test(name)) {
        document.getElementById('nameError').style.display = 'block';
        document.getElementById('addName').style.borderColor = '#e05c3a';
        document.getElementById('addName').focus();
        return;
    }
    if (!price || price <= 0) {
        document.getElementById('addPrice').focus();
        document.getElementById('addPrice').style.borderColor = '#e05c3a';
        return;
    }

    // Populate preview
    document.getElementById('previewName').textContent     = name;
    document.getElementById('previewCategory').textContent = category;
    document.getElementById('previewPrice').textContent    = '₱' + price.toFixed(2);

    // Populate hidden form fields
    document.getElementById('hiddenName').value     = name;
    document.getElementById('hiddenCategory').value = category;
    document.getElementById('hiddenPrice').value    = price;

    document.getElementById('addMenuModal').style.display = 'flex';
}

function closeAddModal() {
    document.getElementById('addMenuModal').style.display = 'none';
    // Reset border colors
    document.getElementById('addPrice').style.borderColor = 'var(--border)';
}

// Close on backdrop click
document.getElementById('addMenuModal').addEventListener('click', e => {
    if (e.target.id === 'addMenuModal') closeAddModal();
});
</script>

</main></div>