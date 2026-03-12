<?php
// nav.php — include after Auth::init() + Auth::requireLogin()
require_once __DIR__ . '/../auth/Auth.php';

$u    = Auth::user();
$page = basename($_SERVER['PHP_SELF'], '.php');

$ownerNav = [
    'dashboard'     => ['icon'=>'📊','label'=>'Dashboard'],
    'orders'        => ['icon'=>'🧾','label'=>'All Orders'],
    'menu'          => ['icon'=>'📋','label'=>'Menu'],
    'cashiers'      => ['icon'=>'👥','label'=>'Cashiers'],
    'sales_report'  => ['icon'=>'📈','label'=>'Sales Report'],
    'audit_log'     => ['icon'=>'🔍','label'=>'Audit Log'],
];
$cashierNav = [
    'dashboard'     => ['icon'=>'📊','label'=>'Dashboard'],
    'new_order'     => ['icon'=>'➕','label'=>'New Order'],
    'my_orders'     => ['icon'=>'🧾','label'=>'My Orders'],
];
$nav = Auth::isOwner() ? $ownerNav : $cashierNav;
?>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0e0a06;--surface:#15100a;--card:#1e1509;--card2:#251c0c;
  --gold:#c9933a;--gold-light:#e8b86d;--cream:#f5ead8;
  --muted:#7a6448;--border:#332310;--red:#e05c3a;--green:#5cb85c;
}
body{background:var(--bg);color:var(--cream);font-family:'DM Sans',sans-serif;
     min-height:100vh;display:flex;flex-direction:column;}

.layout{display:flex;min-height:100vh;}
.sidebar{width:220px;flex-shrink:0;background:var(--surface);border-right:1px solid var(--border);
         display:flex;flex-direction:column;position:fixed;height:100vh;z-index:10;}
.sidebar-brand{padding:24px 20px 16px;border-bottom:1px solid var(--border);}
.sidebar-brand .icon{font-size:1.6rem;}
.sidebar-brand h1{font-family:'Playfair Display',serif;color:var(--gold-light);font-size:1.2rem;
                   line-height:1.2;margin-top:4px;}
.sidebar-brand p{font-size:.7rem;color:var(--muted);letter-spacing:1px;text-transform:uppercase;}
nav{flex:1;padding:12px 0;}
nav a{display:flex;align-items:center;gap:10px;padding:10px 20px;color:var(--muted);
      text-decoration:none;font-size:.88rem;transition:all .15s;border-left:3px solid transparent;}
nav a:hover{color:var(--cream);background:rgba(201,147,58,.06);}
nav a.active{color:var(--gold-light);background:rgba(201,147,58,.1);border-left-color:var(--gold);}
nav a .nav-icon{font-size:1rem;width:20px;text-align:center;}
.sidebar-footer{padding:16px 20px;border-top:1px solid var(--border);}
.user-info .name{font-size:.88rem;font-weight:500;color:var(--cream);}
.user-info .role{font-size:.72rem;color:var(--gold);letter-spacing:.5px;text-transform:uppercase;}
.logout-btn{display:block;margin-top:10px;padding:7px 12px;background:transparent;
            border:1px solid var(--border);color:var(--muted);border-radius:6px;
            font-size:.78rem;text-align:center;text-decoration:none;cursor:pointer;
            transition:all .2s;font-family:'DM Sans',sans-serif;width:100%;}
.logout-btn:hover{border-color:var(--red);color:var(--red);}

.main{margin-left:220px;flex:1;padding:28px 30px;}
.page-header{margin-bottom:24px;}
.page-header h2{font-family:'Playfair Display',serif;font-size:1.6rem;color:var(--cream);}
.page-header p{color:var(--muted);font-size:.85rem;margin-top:3px;}

.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px;}
.card-title{font-size:.72rem;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:8px;}
.card-value{font-family:'Playfair Display',serif;font-size:2rem;color:var(--cream);}
.card-sub{font-size:.78rem;color:var(--muted);margin-top:3px;}

.grid-4{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;}
.grid-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin-bottom:24px;}
.section-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:22px;}
.section-title{font-family:'Playfair Display',serif;font-size:1.1rem;color:var(--cream);
               margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--border);}

table{width:100%;border-collapse:collapse;font-size:.85rem;}
th{text-align:left;color:var(--muted);font-size:.7rem;text-transform:uppercase;letter-spacing:.8px;
   padding:8px 12px;border-bottom:1px solid var(--border);}
td{padding:10px 12px;border-bottom:1px solid rgba(51,35,16,.5);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:rgba(255,255,255,.015);}

.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:600;}
.badge-owner   {background:rgba(201,147,58,.18);color:var(--gold-light);border:1px solid rgba(201,147,58,.35);}
.badge-cashier {background:rgba(92,184,92,.15);color:#7dd87d;border:1px solid rgba(92,184,92,.3);}
.badge-paid    {background:rgba(92,184,92,.15);color:#7dd87d;border:1px solid rgba(92,184,92,.3);}
.badge-open    {background:rgba(201,147,58,.15);color:var(--gold);border:1px solid rgba(201,147,58,.3);}
.badge-refunded{background:rgba(224,92,58,.12);color:#e8937a;border:1px solid rgba(224,92,58,.3);}
.badge-unknown {background:rgba(122,100,72,.2);color:var(--muted);border:1px solid rgba(122,100,72,.3);}

.btn{padding:9px 18px;border:none;border-radius:7px;font-family:'DM Sans',sans-serif;
     font-size:.85rem;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none;display:inline-block;}
.btn-gold   {background:var(--gold);color:#0e0a06;}
.btn-gold:hover{background:var(--gold-light);}
.btn-outline{background:transparent;border:1px solid var(--border);color:var(--muted);}
.btn-outline:hover{border-color:var(--gold);color:var(--gold-light);}
.btn-danger {background:rgba(224,92,58,.15);color:#e8937a;border:1px solid rgba(224,92,58,.3);}
.btn-danger:hover{background:rgba(224,92,58,.25);}
.btn-sm{padding:5px 12px;font-size:.78rem;}

.alert{padding:10px 14px;border-radius:7px;font-size:.85rem;margin-bottom:16px;}
.alert-error  {background:rgba(224,92,58,.12);border:1px solid rgba(224,92,58,.3);color:#e8937a;}
.alert-success{background:rgba(92,184,92,.1);border:1px solid rgba(92,184,92,.3);color:#7dd87d;}

input,select,textarea{width:100%;padding:10px 13px;background:#0e0a06;color:var(--cream);
  border:1px solid var(--border);border-radius:7px;font-size:.9rem;
  font-family:'DM Sans',sans-serif;margin-bottom:13px;}
input:focus,select:focus{outline:none;border-color:var(--gold);}
.form-label{display:block;font-size:.72rem;letter-spacing:1px;text-transform:uppercase;
            color:var(--muted);margin-bottom:5px;}
</style>

<div class="layout">
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="icon">🍽️</div>
    <h1>Kusinero</h1>
    <p><?= htmlspecialchars($u['role_label']) ?></p>
  </div>
  <nav>
    <?php foreach ($nav as $href => $item): ?>
      <a href="<?= $href ?>.php" class="<?= $page === $href ? 'active' : '' ?>">
        <span class="nav-icon"><?= $item['icon'] ?></span>
        <?= $item['label'] ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <div class="user-info">
      <div class="name"><?= htmlspecialchars($u['name']) ?></div>
      <div class="role"><?= htmlspecialchars($u['role_label']) ?></div>
    </div>
    <form method="POST" action="/logout.php">
      <button class="logout-btn" type="submit">← Sign Out</button>
    </form>
  </div>
</aside>
<main class="main">