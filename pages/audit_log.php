<?php
require_once __DIR__ . '/../auth/Auth.php';
require_once __DIR__ . '/../config/Database.php';
Auth::init();
Auth::requireLogin();
Auth::requirePermission('view_audit_log');

$db = Database::get();

// ── Filters ───────────────────────────────────────────────────────────────
$filterAction = trim($_GET['action'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

// Build WHERE clause
$where  = ['1=1'];
$params = [];

if ($filterAction !== '') {
    $where[]  = 'al.action = ?';
    $params[] = $filterAction;
}

$whereSQL = implode(' AND ', $where);

// Total count for pagination
$countStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs al WHERE $whereSQL");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch page
$logs = $db->prepare("
    SELECT al.*, u.name AS user_display_name
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE $whereSQL
    ORDER BY al.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$logs->execute($params);
$logs = $logs->fetchAll();

// Distinct actions for filter dropdown
$actions = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);

// ── Action meta (icon + badge colour) ────────────────────────────────────
function actionMeta(string $action): array {
    return match(true) {
        $action === 'login_success'       => ['icon' => '✅', 'color' => '#5cb85c', 'bg' => 'rgba(92,184,92,.15)'],
        $action === 'login_failed'        => ['icon' => '🚫', 'color' => '#e05c3a', 'bg' => 'rgba(224,92,58,.15)'],
        $action === 'logout'              => ['icon' => '🚪', 'color' => '#7a6448', 'bg' => 'rgba(122,100,72,.2)'],
        str_contains($action, 'void')     => ['icon' => '⊘',  'color' => '#e8937a', 'bg' => 'rgba(224,92,58,.12)'],
        str_contains($action, 'refund')   => ['icon' => '↩',  'color' => '#e8937a', 'bg' => 'rgba(224,92,58,.12)'],
        str_contains($action, 'paid')     => ['icon' => '💳', 'color' => '#e8b86d', 'bg' => 'rgba(201,147,58,.15)'],
        str_contains($action, 'order')    => ['icon' => '🧾', 'color' => '#e8b86d', 'bg' => 'rgba(201,147,58,.12)'],
        str_contains($action, 'menu')     => ['icon' => '📋', 'color' => '#7dd87d', 'bg' => 'rgba(92,184,92,.12)'],
        str_contains($action, 'cashier')  => ['icon' => '👤', 'color' => '#e8b86d', 'bg' => 'rgba(201,147,58,.12)'],
        default                           => ['icon' => '📝', 'color' => '#f5ead8', 'bg' => 'rgba(245,234,216,.08)'],
    };
}

include __DIR__ . '/../includes/nav.php';
?>

<style>
.filter-bar{display:flex;align-items:flex-end;gap:10px;margin-bottom:20px;
            background:var(--card);border:1px solid var(--border);border-radius:10px;padding:16px;}
.filter-bar label{font-size:.7rem;text-transform:uppercase;letter-spacing:1px;
                  color:var(--muted);display:block;margin-bottom:4px;}
.filter-bar select{margin-bottom:0;width:220px;padding:8px 10px;font-size:.85rem;}
.filter-bar .btn{padding:8px 16px;}

.log-table th{white-space:nowrap;}
.log-table td{vertical-align:middle;}

.action-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;
              font-size:.72rem;font-weight:600;white-space:nowrap;}

.pagination{display:flex;gap:6px;justify-content:center;margin-top:20px;flex-wrap:wrap;}
.pagination a,.pagination span{
    padding:6px 13px;border-radius:7px;font-size:.82rem;text-decoration:none;
    border:1px solid var(--border);color:var(--muted);}
.pagination a:hover{border-color:var(--gold);color:var(--gold);}
.pagination .current{background:var(--gold);color:#0e0a06;border-color:var(--gold);font-weight:700;}

.stats-row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;}
.stat-chip{background:var(--card);border:1px solid var(--border);border-radius:8px;
           padding:10px 18px;font-size:.82rem;color:var(--muted);}
.stat-chip strong{color:var(--cream);font-size:1.1rem;display:block;}
</style>

<div class="page-header">
  <h2>🔍 Audit Log</h2>
  <p>Complete activity trail — all user actions and login attempts</p>
</div>

<?php
$stats = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(action='login_success') AS logins,
        SUM(action='login_failed')  AS failures,
        SUM(action='logout')        AS logouts
    FROM audit_logs
")->fetch();
?>
<div class="stats-row">
  <div class="stat-chip"><strong><?= number_format($stats['total']) ?></strong>Total Events</div>
  <div class="stat-chip"><strong style="color:#7dd87d"><?= $stats['logins'] ?></strong>Successful Logins</div>
  <div class="stat-chip"><strong style="color:#e05c3a"><?= $stats['failures'] ?></strong>Failed Logins</div>
  <div class="stat-chip"><strong style="color:var(--muted)"><?= $stats['logouts'] ?></strong>Logouts</div>
</div>

<!-- Filter Bar — Action only -->
<form method="GET">
  <div class="filter-bar">
    <div>
      <label>Action</label>
      <select name="action" onchange="this.form.submit()">
        <option value="">All Actions</option>
        <?php foreach ($actions as $a): ?>
          <option value="<?= htmlspecialchars($a) ?>"
            <?= $filterAction === $a ? 'selected' : '' ?>>
            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $a))) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($filterAction !== ''): ?>
      <a href="?" class="btn btn-outline">Reset</a>
    <?php endif; ?>
  </div>
</form>

<div class="section-card">
  <div class="section-title" style="display:flex;justify-content:space-between;align-items:center">
    <span>
      Events
      <?= $filterAction !== ''
          ? '— filtered by <strong style="color:var(--gold-light)">' . htmlspecialchars(ucwords(str_replace('_', ' ', $filterAction))) . '</strong>'
          : '' ?>
      (<?= number_format($totalRows) ?> found)
    </span>
    <span style="font-size:.75rem;color:var(--muted);font-family:'DM Sans',sans-serif">
      Page <?= $page ?> of <?= $totalPages ?>
    </span>
  </div>

  <?php if (empty($logs)): ?>
    <p style="color:var(--muted);font-size:.85rem">No log entries found for the selected filter.</p>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table class="log-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Date & Time</th>
          <th>Action</th>
          <th>User</th>
          <th>Role</th>
          <th>Details</th>
          <th>IP Address</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($logs as $log):
          $meta = actionMeta($log['action']);
          $displayName = $log['user_display_name']
              ? htmlspecialchars($log['user_display_name'])
              : '<span style="color:var(--muted);font-style:italic">—</span>';
      ?>
        <tr>
          <td style="color:var(--muted);font-size:.75rem"><?= $log['id'] ?></td>
          <td style="white-space:nowrap;font-size:.8rem;color:var(--muted)">
            <?= date('M j, Y', strtotime($log['created_at'])) ?><br>
            <span style="color:var(--cream)"><?= date('h:i:s A', strtotime($log['created_at'])) ?></span>
          </td>
          <td>
            <span class="action-badge"
                  style="color:<?= $meta['color'] ?>;background:<?= $meta['bg'] ?>;
                         border:1px solid <?= $meta['color'] ?>33">
              <?= $meta['icon'] ?>
              <?= htmlspecialchars(ucwords(str_replace('_', ' ', $log['action']))) ?>
            </span>
          </td>
          <td>
            <?= $displayName ?><br>
            <span style="font-size:.75rem;color:var(--muted)">
              @<?= htmlspecialchars($log['username']) ?>
            </span>
          </td>
          <td>
            <span class="badge badge-<?= $log['role'] === 'owner' ? 'owner' : ($log['role'] === 'cashier' ? 'cashier' : 'open') ?>">
              <?= htmlspecialchars(ucfirst($log['role'])) ?>
            </span>
          </td>
          <td style="font-size:.82rem;max-width:320px">
            <?= htmlspecialchars($log['details']) ?>
            <?php if ($log['target_type'] && $log['target_id']): ?>
              <span style="color:var(--muted);font-size:.75rem">
                [<?= htmlspecialchars($log['target_type']) ?> #<?= $log['target_id'] ?>]
              </span>
            <?php endif; ?>
          </td>
          <td style="font-size:.78rem;color:var(--muted);white-space:nowrap">
            <?= htmlspecialchars($log['ip_address'] ?? '') ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1):
      $baseQuery = $filterAction ? '&action=' . urlencode($filterAction) : '';
  ?>
  <div class="pagination">
    <?php if ($page > 1): ?>
      <a href="?page=<?= $page - 1 ?><?= $baseQuery ?>">← Prev</a>
    <?php endif; ?>

    <?php
    $start = max(1, $page - 2);
    $end   = min($totalPages, $page + 2);
    if ($start > 1) echo '<span>…</span>';
    for ($i = $start; $i <= $end; $i++):
    ?>
      <?php if ($i === $page): ?>
        <span class="current"><?= $i ?></span>
      <?php else: ?>
        <a href="?page=<?= $i ?><?= $baseQuery ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    <?php if ($end < $totalPages) echo '<span>…</span>'; ?>

    <?php if ($page < $totalPages): ?>
      <a href="?page=<?= $page + 1 ?><?= $baseQuery ?>">Next →</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

</main></div>