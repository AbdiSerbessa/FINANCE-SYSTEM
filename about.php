<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
render_header('About', 'about');
?>

<div class="about-hero">
  <h1>Mugher Cement Factory</h1>
  <p>Mugher Cement Enterprise is an Ethiopian state-owned cement manufacturer located on the Mugher River, 920km west of Addis Ababa, Ethiopia.</p>
</div>

<div class="grid-3 mb-6">
  <div class="feature-card">
    <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg></div>
    <h3>Double-Entry Bookkeeping</h3>
    <p>Every transaction is recorded as a balanced journal entry with full audit trail — draft, post, or void with approval tracking.</p>
  </div>
  <div class="feature-card">
    <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg></div>
    <h3>Inventory Control</h3>
    <p>Track raw materials, work-in-progress, and finished goods with automatic stock movements and reorder alerts.</p>
  </div>
  <div class="feature-card">
    <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></div>
    <h3>Purchase Orders</h3>
    <p>Create, approve, and receive supplier orders — inventory and costs update automatically on receipt.</p>
  </div>
  <div class="feature-card">
    <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
    <h3>Payroll Processing</h3>
    <p>Run payroll periods for all employees, calculate net pay, and auto-generate the corresponding journal entry on payment.</p>
  </div>
  <div class="feature-card">
    <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
    <h3>Financial Reports</h3>
    <p>Generate Trial Balance, Profit &amp; Loss, Balance Sheet, and per-account Ledger Cards for any date range.</p>
  </div>
  <div class="feature-card">
    <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
    <h3>Role-Based Access</h3>
    <p>Admin, Accountant, and Staff roles control who can post entries, approve payroll, and manage system users.</p>
  </div>
</div>

<div class="card" style="text-align:center; padding:2rem;">
  <p class="text-muted">Factory Finance v<?= APP_VERSION ?> — Built with PHP &amp; PostgreSQL</p>
</div>

<?php render_footer(); ?>
