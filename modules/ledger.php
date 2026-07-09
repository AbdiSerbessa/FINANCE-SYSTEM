<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$db = db();

// ── Filters ─────────────────────────────────────────────────
$account_id = (int)($_GET['account_id'] ?? 0);
$from       = $_GET['from'] ?? date('Y-01-01');
$to         = $_GET['to']   ?? date('Y-m-d');

$accounts_all = $db->query("
    SELECT a.id, a.code, a.name, at.name AS type_name, at.normal_balance
    FROM accounts a
    JOIN account_types at ON at.id = a.account_type_id
    WHERE a.active = TRUE
    ORDER BY a.code
")->fetchAll();

// ── Summary: balance of every account as of "to" date ────────
$summary = $db->query("
    SELECT a.id, a.code, a.name, at.name AS type_name, at.normal_balance,
           COALESCE(SUM(jl.debit),0)  AS total_debit,
           COALESCE(SUM(jl.credit),0) AS total_credit
    FROM accounts a
    JOIN account_types at ON at.id = a.account_type_id
    LEFT JOIN journal_lines jl ON jl.account_id = a.id
    LEFT JOIN journal_entries je ON je.id = jl.entry_id
        AND je.status = 'posted'
        AND je.entry_date BETWEEN " . $db->quote($from) . " AND " . $db->quote($to) . "
    WHERE a.active = TRUE
    GROUP BY a.id, a.code, a.name, at.name, at.normal_balance
    ORDER BY a.code
")->fetchAll();

function acct_balance(array $row): float {
    return $row['normal_balance'] === 'debit'
        ? $row['total_debit'] - $row['total_credit']
        : $row['total_credit'] - $row['total_debit'];
}

// ── Detail: full transaction list for one selected account ──
$lines = [];
$selected_account = null;
if ($account_id) {
    foreach ($accounts_all as $a) {
        if ($a['id'] == $account_id) { $selected_account = $a; break; }
    }
    $stmt = $db->prepare("
        SELECT je.id, je.entry_no, je.entry_date, je.description, je.type, je.reference,
               jl.debit, jl.credit, jl.memo,
               s.name AS supplier_name, c.name AS customer_name
        FROM journal_lines jl
        JOIN journal_entries je ON je.id = jl.entry_id
        LEFT JOIN suppliers s ON s.id = jl.supplier_id
        LEFT JOIN customers c ON c.id = jl.customer_id
        WHERE jl.account_id = ? AND je.status = 'posted'
          AND je.entry_date BETWEEN ? AND ?
        ORDER BY je.entry_date, je.id
    ");
    $stmt->execute([$account_id, $from, $to]);
    $lines = $stmt->fetchAll();
}

render_header('General Ledger', 'ledger');
?>
<div class="page-header">
  <div>
    <div class="page-title">General Ledger</div>
    <div class="page-sub">Complete record of every posted transaction, organized by account</div>
  </div>
  <div class="flex-gap">
    <a href="/modules/reports.php?type=trial_balance" class="btn btn-ghost btn-sm">Trial Balance</a>
    <a href="/modules/reports.php?type=subsidiary" class="btn btn-ghost btn-sm">Subsidiary Ledger</a>
  </div>
</div>

<!-- Filter bar -->
<form method="GET" class="flex-gap mb-6">
  <div class="form-group" style="flex-direction:row; align-items:center; gap:8px;">
    <label style="white-space:nowrap; font-size:11px; color:var(--text-3);">Account</label>
    <select name="account_id" style="width:auto; min-width:280px;">
      <option value="">— All Accounts (Summary) —</option>
      <?php foreach ($accounts_all as $a): ?>
      <option value="<?= $a['id'] ?>" <?= $account_id==$a['id']?'selected':'' ?>><?= e($a['code']) ?> — <?= e($a['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group" style="flex-direction:row; align-items:center; gap:8px;">
    <label style="white-space:nowrap; font-size:11px; color:var(--text-3);">From</label>
    <input type="date" name="from" value="<?= e($from) ?>" style="width:auto;"/>
  </div>
  <div class="form-group" style="flex-direction:row; align-items:center; gap:8px;">
    <label style="white-space:nowrap; font-size:11px; color:var(--text-3);">To</label>
    <input type="date" name="to" value="<?= e($to) ?>" style="width:auto;"/>
  </div>
  <button type="submit" class="btn btn-primary btn-sm">View</button>
  <?php if ($account_id): ?>
  <a href="/modules/ledger.php?from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-ghost btn-sm">← Back to Summary</a>
  <?php endif; ?>
</form>

<?php if (!$account_id): ?>

  <!-- ═══════════════ SUMMARY VIEW — ALL ACCOUNTS ═══════════════ -->
  <?php
    $total_debit_all  = array_sum(array_column($summary, 'total_debit'));
    $total_credit_all = array_sum(array_column($summary, 'total_credit'));
  ?>
  <div class="kpi-grid" style="grid-template-columns:repeat(3,1fr); margin-bottom:1.4rem;">
    <div class="kpi">
      <div class="kpi-label">Accounts with Activity</div>
      <div class="kpi-value"><?= count(array_filter($summary, fn($r) => $r['total_debit'] > 0 || $r['total_credit'] > 0)) ?></div>
    </div>
    <div class="kpi">
      <div class="kpi-label">Total Debits Posted</div>
      <div class="kpi-value"><?= money($total_debit_all) ?></div>
    </div>
    <div class="kpi">
      <div class="kpi-label">Total Credits Posted</div>
      <div class="kpi-value"><?= money($total_credit_all) ?></div>
    </div>
  </div>

  <div class="card">
    <div class="card-title">All Accounts — Period Activity &amp; Balance</div>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr><th>Code</th><th>Account Name</th><th>Type</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th><th></th></tr>
        </thead>
        <tbody>
          <?php $cur_type = ''; foreach ($summary as $row): ?>
          <?php if ($row['type_name'] !== $cur_type): $cur_type = $row['type_name']; ?>
          <tr><td colspan="7" style="background:rgba(255,255,255,0.03); font-size:11px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:0.08em; padding:6px 12px;"><?= e($cur_type) ?></td></tr>
          <?php endif; ?>
          <?php $bal = acct_balance($row); ?>
          <tr>
            <td class="fw-bold"><?= e($row['code']) ?></td>
            <td><?= e($row['name']) ?></td>
            <td><span class="pill pill-blue"><?= e($row['type_name']) ?></span></td>
            <td class="num td-debit"><?= $row['total_debit']  > 0 ? money($row['total_debit'])  : '—' ?></td>
            <td class="num td-credit"><?= $row['total_credit'] > 0 ? money($row['total_credit']) : '—' ?></td>
            <td class="num fw-bold"><?= $bal != 0 ? money(abs($bal)) . ' ' . ($row['normal_balance']==='debit' ? ($bal>=0?'Dr':'Cr') : ($bal>=0?'Cr':'Dr')) : '—' ?></td>
            <td><a href="?account_id=<?= $row['id'] ?>&from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-ghost btn-sm">View Ledger →</a></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($summary)): ?>
          <tr><td colspan="7" class="text-muted" style="text-align:center; padding:2rem;">No active accounts found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php else: ?>

  <!-- ═══════════════ DETAIL VIEW — ONE ACCOUNT ═══════════════ -->
  <div class="card mb-6" style="border-top:3px solid var(--blue);">
    <div class="flex-between">
      <div>
        <div class="text-muted">Account</div>
        <div style="font-size:20px; font-weight:700;"><?= e($selected_account['code']) ?> — <?= e($selected_account['name']) ?></div>
        <div class="text-muted" style="margin-top:4px;">
          <span class="pill pill-blue"><?= e($selected_account['type_name']) ?></span>
          &nbsp; Normal Balance: <?= ucfirst($selected_account['normal_balance']) ?>
        </div>
      </div>
      <?php
        $total_d = array_sum(array_column($lines, 'debit'));
        $total_c = array_sum(array_column($lines, 'credit'));
        $closing = $selected_account['normal_balance'] === 'debit' ? $total_d - $total_c : $total_c - $total_d;
      ?>
      <div style="text-align:right;">
        <div class="text-muted">Closing Balance</div>
        <div style="font-size:24px; font-weight:800; color:<?= $closing >= 0 ? 'var(--green)' : 'var(--red)' ?>;">
          <?= money(abs($closing)) ?> <?= $selected_account['normal_balance']==='debit' ? ($closing>=0?'Dr':'Cr') : ($closing>=0?'Cr':'Dr') ?>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-title">Transaction History — <?= date(DATE_FMT, strtotime($from)) ?> to <?= date(DATE_FMT, strtotime($to)) ?></div>
    <?php if (empty($lines)): ?>
      <p class="text-muted" style="text-align:center; padding:2.5rem;">No posted transactions for this account in the selected period.</p>
    <?php else: ?>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr><th>Date</th><th>Entry No.</th><th>Description</th><th>Party</th><th>Memo</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr>
        </thead>
        <tbody>
          <?php $running = 0; foreach ($lines as $line):
            $running += $line['debit'] - $line['credit'];
            // Display running balance in the account's natural direction
            $disp = $selected_account['normal_balance'] === 'debit' ? $running : -$running;
          ?>
          <tr>
            <td><?= date(DATE_FMT, strtotime($line['entry_date'])) ?></td>
            <td><a href="/modules/journal.php?action=view&id=<?= $line['id'] ?>" style="color:var(--blue-l);"><?= e($line['entry_no']) ?></a></td>
            <td><?= e($line['description']) ?></td>
            <td>
              <?php if ($line['supplier_name']): ?><span class="pill pill-blue"><?= e($line['supplier_name']) ?></span>
              <?php elseif ($line['customer_name']): ?><span class="pill pill-purple"><?= e($line['customer_name']) ?></span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= e($line['memo'] ?? '—') ?></td>
            <td class="num td-debit"><?= $line['debit']  > 0 ? money($line['debit'])  : '—' ?></td>
            <td class="num td-credit"><?= $line['credit'] > 0 ? money($line['credit']) : '—' ?></td>
            <td class="num fw-bold"><?= money(abs($disp)) ?> <?= $disp >= 0 ? 'Dr' : 'Cr' ?></td>
          </tr>
          <?php endforeach; ?>
          <tr style="border-top:2px solid var(--border);">
            <td colspan="5" class="fw-bold">Period Totals</td>
            <td class="num td-debit fw-bold"><?= money($total_d) ?></td>
            <td class="num td-credit fw-bold"><?= money($total_c) ?></td>
            <td class="num fw-bold"><?= money(abs($closing)) ?> <?= $selected_account['normal_balance']==='debit' ? ($closing>=0?'Dr':'Cr') : ($closing>=0?'Cr':'Dr') ?></td>
          </tr>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php render_footer(); ?>
