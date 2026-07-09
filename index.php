<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$db = db();

// ── KPI queries ────────────────────────────────────────────
$cash = $db->query("
  SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS bal
  FROM journal_lines jl
  JOIN accounts a ON a.id = jl.account_id
  JOIN journal_entries je ON je.id = jl.entry_id
  WHERE a.code IN ('1000','1010') AND je.status = 'posted'
")->fetchColumn();

$revenue = $db->query("
  SELECT COALESCE(SUM(jl.credit - jl.debit), 0) AS bal
  FROM journal_lines jl
  JOIN accounts a ON a.id = jl.account_id
  JOIN account_types at ON at.id = a.account_type_id
  JOIN journal_entries je ON je.id = jl.entry_id
  WHERE at.name = 'Revenue' AND je.status = 'posted'
  AND DATE_TRUNC('month', je.entry_date) = DATE_TRUNC('month', CURRENT_DATE)
")->fetchColumn();

$expenses = $db->query("
  SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS bal
  FROM journal_lines jl
  JOIN accounts a ON a.id = jl.account_id
  JOIN account_types at ON at.id = a.account_type_id
  JOIN journal_entries je ON je.id = jl.entry_id
  WHERE at.name = 'Expense' AND je.status = 'posted'
  AND DATE_TRUNC('month', je.entry_date) = DATE_TRUNC('month', CURRENT_DATE)
")->fetchColumn();

$net_profit = $revenue - $expenses;

$pending_approvals = $db->query("SELECT COUNT(*) FROM journal_entries WHERE status = 'draft'")->fetchColumn();
$pending_po        = $db->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'approved'")->fetchColumn();
$low_stock         = $db->query("SELECT COUNT(*) FROM inventory WHERE qty_on_hand <= reorder_level AND active = TRUE")->fetchColumn();

// ── Trial balance ──────────────────────────────────────────
$trial = $db->query("
  SELECT
    COALESCE(SUM(jl.debit),0)  AS total_debits,
    COALESCE(SUM(jl.credit),0) AS total_credits
  FROM journal_lines jl
  JOIN journal_entries je ON je.id = jl.entry_id
  WHERE je.status = 'posted'
")->fetch();

// ── Recent journal entries ─────────────────────────────────
$recent_je = $db->query("
  SELECT je.entry_no, je.entry_date, je.description, je.status,
         u.full_name AS created_by,
         COALESCE(SUM(jl.debit),0) AS total
  FROM journal_entries je
  LEFT JOIN users u ON u.id = je.created_by
  LEFT JOIN journal_lines jl ON jl.entry_id = je.id
  GROUP BY je.id, je.entry_no, je.entry_date, je.description, je.status, u.full_name
  ORDER BY je.created_at DESC LIMIT 6
")->fetchAll();

// ── Monthly revenue chart (last 6 months) ─────────────────
$monthly = $db->query("
  SELECT TO_CHAR(je.entry_date, 'Mon') AS mon,
         DATE_TRUNC('month', je.entry_date) AS period,
         COALESCE(SUM(CASE WHEN at.name='Revenue' THEN jl.credit - jl.debit ELSE 0 END),0) AS revenue,
         COALESCE(SUM(CASE WHEN at.name='Expense' THEN jl.debit - jl.credit ELSE 0 END),0) AS expenses
  FROM journal_entries je
  JOIN journal_lines jl ON jl.entry_id = je.id
  JOIN accounts a ON a.id = jl.account_id
  JOIN account_types at ON at.id = a.account_type_id
  WHERE je.status = 'posted'
    AND je.entry_date >= CURRENT_DATE - INTERVAL '6 months'
  GROUP BY DATE_TRUNC('month', je.entry_date), TO_CHAR(je.entry_date, 'Mon')
  ORDER BY period
")->fetchAll();

$chart_labels   = array_column($monthly, 'mon');
$chart_revenue  = array_column($monthly, 'revenue');
$chart_expenses = array_column($monthly, 'expenses');

// ── Cost breakdown by account type ────────────────────────
$cost_breakdown = $db->query("
  SELECT a.name AS account_name,
         COALESCE(SUM(jl.debit - jl.credit), 0) AS total
  FROM journal_lines jl
  JOIN accounts a ON a.id = jl.account_id
  JOIN account_types at ON at.id = a.account_type_id
  JOIN journal_entries je ON je.id = jl.entry_id
  WHERE at.name = 'Expense' AND je.status = 'posted'
    AND DATE_TRUNC('month', je.entry_date) = DATE_TRUNC('month', CURRENT_DATE)
  GROUP BY a.name
  ORDER BY total DESC LIMIT 5
")->fetchAll();

render_header('Dashboard', 'dashboard');
?>

<!-- Hero -->
<div class="hero">
  <div class="badge-role">
    <?= e(auth()['role']) ?>
  </div>
  <h2>Welcome, <?= e(auth()['full_name']) ?>!</h2>
  <p>Finance Management — <?= date('l, d F Y') ?></p>
  <?php if ($pending_approvals > 0): ?>
  <div style="margin-top:12px;">
    <span class="alert-pending">⚠ <?= $pending_approvals ?> journal entries pending approval</span>
  </div>
  <?php endif; ?>
</div>

<!-- KPIs -->
<div class="kpi-grid">
  <div class="kpi">
    <div class="kpi-label">Cash on Hand</div>
    <div class="kpi-value"><?= money($cash) ?></div>
    <div class="kpi-delta">Accounts 1000 + 1010</div>
  </div>
  <div class="kpi <?= $revenue > 0 ? 'green' : '' ?>">
    <div class="kpi-label">Revenue (This Month)</div>
    <div class="kpi-value"><?= money($revenue) ?></div>
    <div class="kpi-delta up">Current month</div>
  </div>
  <div class="kpi amber">
    <div class="kpi-label">Expenses (This Month)</div>
    <div class="kpi-value"><?= money($expenses) ?></div>
    <div class="kpi-delta">Current month</div>
  </div>
  <div class="kpi <?= $net_profit >= 0 ? 'green' : 'red' ?>">
    <div class="kpi-label">Net Profit (This Month)</div>
    <div class="kpi-value"><?= money($net_profit) ?></div>
    <div class="kpi-delta <?= $net_profit >= 0 ? 'up' : 'down' ?>"><?= $net_profit >= 0 ? '▲ Profitable' : '▼ Loss' ?></div>
  </div>
</div>

<!-- Alerts row -->
<div class="grid-3 mb-6">
  <div class="card" style="border-top:3px solid var(--red);">
    <div class="card-title">Pending Approvals</div>
    <div style="font-size:28px; font-weight:700; color:var(--red);"><?= $pending_approvals ?></div>
    <div class="text-muted">Draft journal entries</div>
    <div style="margin-top:10px;">
      <a href="/modules/journal.php" class="btn btn-ghost btn-sm">View All →</a>
    </div>
  </div>
  <div class="card" style="border-top:3px solid var(--amber);">
    <div class="card-title">Purchase Orders</div>
    <div style="font-size:28px; font-weight:700; color:var(--amber);"><?= $pending_po ?></div>
    <div class="text-muted">Awaiting receipt</div>
    <div style="margin-top:10px;">
      <a href="/modules/purchase_orders.php" class="btn btn-ghost btn-sm">View All →</a>
    </div>
  </div>
  <div class="card" style="border-top:3px solid var(--purple);">
    <div class="card-title">Low Stock Items</div>
    <div style="font-size:28px; font-weight:700; color:var(--purple);"><?= $low_stock ?></div>
    <div class="text-muted">Below reorder level</div>
    <div style="margin-top:10px;">
      <a href="/modules/inventory.php" class="btn btn-ghost btn-sm">View All →</a>
    </div>
  </div>
</div>

<!-- Trial Balance -->
<?php $diff = abs($trial['total_debits'] - $trial['total_credits']); $balanced = $diff < 0.01; ?>
<div class="card mb-6">
  <div class="flex-between">
    <div class="card-title">
      <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
      Trial Balance Status
    </div>
    <a href="/modules/reports.php?type=trial_balance" class="btn btn-ghost btn-sm">Full Report →</a>
  </div>
  <div style="display:flex; gap:2rem; flex-wrap:wrap; align-items:center;">
    <div>
      <div class="text-muted">Total Debits</div>
      <div class="fw-bold" style="font-size:18px;"><?= money($trial['total_debits']) ?></div>
    </div>
    <div>
      <div class="text-muted">Total Credits</div>
      <div class="fw-bold" style="font-size:18px;"><?= money($trial['total_credits']) ?></div>
    </div>
    <div>
      <?php if ($balanced): ?>
        <span class="pill pill-green">✓ Balanced</span>
      <?php else: ?>
        <span class="pill pill-red">✗ Out of Balance by <?= money($diff) ?></span>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Charts + cost breakdown -->
<div class="grid-2 mb-6">
  <div class="card">
    <div class="card-title">Revenue vs Expenses (Last 6 Months)</div>
    <div class="chart-wrap h-220">
      <canvas id="revenueChart"></canvas>
    </div>
  </div>
  <div class="card">
    <div class="card-title">Top Expense Accounts (This Month)</div>
    <?php if (empty($cost_breakdown)): ?>
      <p class="text-muted" style="padding-top:1rem;">No expense data this month.</p>
    <?php else: ?>
      <?php
        $max = max(array_column($cost_breakdown, 'total')) ?: 1;
        $colors = ['#3b82f6','#a78bfa','#f59e0b','#22c55e','#f87171'];
      ?>
      <div style="display:flex; flex-direction:column; gap:14px; padding-top:6px;">
        <?php foreach ($cost_breakdown as $i => $row): ?>
        <div>
          <div class="flex-between" style="margin-bottom:5px;">
            <span style="font-size:13px; color:var(--text-2);"><?= e($row['account_name']) ?></span>
            <span style="font-size:12px; color:var(--text-3);"><?= money($row['total']) ?></span>
          </div>
          <div style="height:5px; background:#0d1120; border-radius:3px; overflow:hidden;">
            <div style="height:100%; width:<?= round($row['total']/$max*100) ?>%; background:<?= $colors[$i % count($colors)] ?>; border-radius:3px;"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Recent journal entries -->
<div class="card">
  <div class="flex-between mb-4">
    <div class="card-title">Recent Journal Entries</div>
    <a href="/modules/journal.php" class="btn btn-ghost btn-sm">View All →</a>
  </div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th>Entry No.</th><th>Date</th><th>Description</th>
          <th>Created By</th><th class="num">Total</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($recent_je)): ?>
        <tr><td colspan="6" class="text-muted" style="text-align:center; padding:2rem;">No entries yet. <a href="/modules/journal.php?action=new" style="color:var(--blue-l);">Create one →</a></td></tr>
        <?php else: ?>
        <?php foreach ($recent_je as $je): ?>
        <tr>
          <td><a href="/modules/journal.php?action=view&id=<?= $je['entry_no'] ?>" style="color:var(--blue-l);"><?= e($je['entry_no']) ?></a></td>
          <td><?= date(DATE_FMT, strtotime($je['entry_date'])) ?></td>
          <td><?= e($je['description']) ?></td>
          <td><?= e($je['created_by'] ?? '—') ?></td>
          <td class="num"><?= money($je['total']) ?></td>
          <td>
            <?php $sc = ['draft'=>'pill-amber','posted'=>'pill-green','void'=>'pill-red']; ?>
            <span class="pill <?= $sc[$je['status']] ?? 'pill-blue' ?>"><?= ucfirst($je['status']) ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<script>
const labels   = <?= json_encode($chart_labels   ?: ['Jan','Feb','Mar','Apr','May','Jun']) ?>;
const revenue  = <?= json_encode(array_map('floatval', $chart_revenue  ?: [0,0,0,0,0,0])) ?>;
const expenses = <?= json_encode(array_map('floatval', $chart_expenses ?: [0,0,0,0,0,0])) ?>;

new Chart(document.getElementById('revenueChart'), {
  type: 'bar',
  data: {
    labels,
    datasets: [
      { label: 'Revenue',  data: revenue,  backgroundColor: 'rgba(59,130,246,0.7)',  borderRadius: 4 },
      { label: 'Expenses', data: expenses, backgroundColor: 'rgba(245,158,11,0.7)',  borderRadius: 4 }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { labels: { color: '#64748b', font: { size: 12 } } } },
    scales: {
      x: { ticks: { color: '#475569' }, grid: { color: 'rgba(255,255,255,0.04)' }, border: { color: '#1e293b' } },
      y: { ticks: { color: '#475569', callback: v => '$' + v.toLocaleString() }, grid: { color: 'rgba(255,255,255,0.04)' }, border: { color: '#1e293b' } }
    }
  }
});
</script>

<?php render_footer(); ?>
