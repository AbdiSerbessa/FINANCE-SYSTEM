<?php
// includes/layout.php  — call render_header() then render_footer()
function render_header(string $title = '', string $active = ''): void {
    $user = auth();
    $flash = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= e($title ?: APP_NAME) ?> — <?= APP_NAME ?></title>
  <link rel="stylesheet" href="/assets/css/app.css"/>
</head>
<body>

<!-- ═══ TOPBAR ═══ -->
<nav id="topbar">
  <a href="/index.php" class="tb-brand">
   <div style="display: flex; align-items: center; min-width: max-content;">
    <img src="/assets/image/logo.jpg" alt="Logo" style="height: 50px; width: auto; max-width: 120px; border-radius: 40px; object-fit: contain; margin-right: 10px; display: inline-block;">
</div>
    <span class="tb-name">Mugher Cement Factory <span>FMS</span></span>
  </a>

  <ul class="tb-nav">
    <li><a href="/index.php" <?= $active==='dashboard'?'class="tb-active"':'' ?>>
      <svg viewBox="0 0 24 24"><path d="M3 12L12 4l9 8"/><path d="M5 10v10h5v-6h4v6h5V10"/></svg>Home
    </a></li>
    <li><a href="/about.php" <?= $active==='about'?'class="tb-active"':'' ?>>
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v.01M12 12v4"/></svg>About
    </a></li>
    <div class="tb-sep"></div>
    <?php if (logged_in()): ?>
    <li class="tb-user-info">
      <div class="tb-avatar"><?= strtoupper(substr($user['full_name'] ?? 'U', 0, 2)) ?></div>
      <span><?= e($user['full_name'] ?? '') ?></span>
      <span class="tb-role"><?= e($user['role'] ?? '') ?></span>
    </li>
    <li><a href="/logout.php" class="tb-btn-outline">
      <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Logout
    </a></li>
    <?php else: ?>
    <li><a href="/login.php" class="tb-btn-solid">
      <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Login
    </a></li>
    <?php endif; ?>
  </ul>
</nav>

<!-- ═══ LAYOUT ═══ -->
<div class="layout">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sb-section">
      <a href="/index.php" class="sb-link <?= $active==='dashboard'?'active':'' ?>">
        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Dashboard
      </a>
    </div>

    <div class="sb-section">
      <div class="sb-label">Accounting</div>
      <a href="/modules/ledger.php" class="sb-link <?= $active==='ledger'?'active':'' ?>">
        <svg viewBox="0 0 24 24"><path d="M4 6h16M4 10h16M4 14h10M4 18h6"/></svg>General Ledger
      </a>
      <a href="/modules/journal.php" class="sb-link <?= $active==='journal'?'active':'' ?>">
        <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>Journal Voucher
      </a>
      <a href="/modules/vouchers.php?type=PV" class="sb-link <?= $active==='pv'?'active':'' ?>">
        <svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Payment Voucher
      </a>
      <a href="/modules/vouchers.php?type=RV" class="sb-link <?= $active==='rv'?'active':'' ?>">
        <svg viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"/><circle cx="19" cy="6" r="2"/></svg>Receipt Voucher
      </a>
      <a href="/modules/accounts.php" class="sb-link <?= $active==='accounts'?'active':'' ?>">
        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Chart of Accounts
      </a>
    </div>

    <div class="sb-section">
      <div class="sb-label">Operations</div>
      <a href="/modules/inventory.php" class="sb-link <?= $active==='inventory'?'active':'' ?>">
        <svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg>Inventory
      </a>
      <a href="/modules/purchase_orders.php" class="sb-link <?= $active==='po'?'active':'' ?>">
        <svg viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>Purchase Orders
      </a>
      <a href="/modules/payroll.php" class="sb-link <?= $active==='payroll'?'active':'' ?>">
        <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Payroll
      </a>
      <a href="/modules/employees.php" class="sb-link <?= $active==='employees'?'active':'' ?>">
        <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Employees
      </a>
      <a href="/modules/suppliers.php" class="sb-link <?= $active==='suppliers'?'active':'' ?>">
        <svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>Suppliers
      </a>
      <a href="/modules/customers.php" class="sb-link <?= $active==='customers'?'active':'' ?>">
        <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Customers
      </a>
    </div>

    <div class="sb-section">
      <div class="sb-label">Reports</div>
      <a href="/modules/reports.php?type=trial_balance" class="sb-link <?= $active==='trial'?'active':'' ?>">
        <svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Trial Balance
      </a>
      <a href="/modules/reports.php?type=pnl" class="sb-link <?= $active==='pnl'?'active':'' ?>">
        <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>Profit &amp; Loss
      </a>
      <a href="/modules/reports.php?type=balance_sheet" class="sb-link <?= $active==='bs'?'active':'' ?>">
        <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>Balance Sheet
      </a>
      <a href="/modules/reports.php?type=ledger_card" class="sb-link <?= $active==='ledger_card'?'active':'' ?>">
        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>Ledger Card
      </a>
      <a href="/modules/reports.php?type=subsidiary" class="sb-link <?= $active==='subsidiary'?'active':'' ?>">
        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="18" rx="1"/><rect x="14" y="3" width="7" height="18" rx="1"/></svg>Subsidiary Ledger
      </a>
    </div>

    <?php if (($user['role'] ?? '') === 'admin'): ?>
    <div class="sb-section">
      <div class="sb-label">Administration</div>
      <a href="/modules/users.php" class="sb-link <?= $active==='users'?'active':'' ?>">
        <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>Users
      </a>
    </div>
    <?php endif; ?>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="main">
    <?php if ($flash): ?>
    <div class="flash flash-<?= e($flash['type']) ?>">
      <?= e($flash['msg']) ?>
      <button onclick="this.parentElement.remove()">✕</button>
    </div>
    <?php endif; ?>
<?php
}

function render_footer(): void {
?>
  </main>
</div><!-- /.layout -->

<script src="/assets/js/app.js"></script>
</body>
</html>
<?php
}
