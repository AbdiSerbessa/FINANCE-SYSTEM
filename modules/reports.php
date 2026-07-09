<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$db   = db();
$type = $_GET['type'] ?? 'trial_balance';

$from = $_GET['from'] ?? date('Y-01-01');
$to   = $_GET['to']   ?? date('Y-m-d');

// ── Shared filter form ─────────────────────────────────────
function date_filter(string $from, string $to, string $type): void { ?>
<form method="GET" class="flex-gap mb-6">
  <input type="hidden" name="type" value="<?= e($type) ?>"/>
  <div class="form-group" style="flex-direction:row; align-items:center; gap:8px;">
    <label style="white-space:nowrap; font-size:11px; color:var(--text-3);">From</label>
    <input type="date" name="from" value="<?= e($from) ?>" style="width:auto;"/>
  </div>
  <div class="form-group" style="flex-direction:row; align-items:center; gap:8px;">
    <label style="white-space:nowrap; font-size:11px; color:var(--text-3);">To</label>
    <input type="date" name="to" value="<?= e($to) ?>" style="width:auto;"/>
  </div>
  <button type="submit" class="btn btn-primary btn-sm">Apply</button>
</form>
<?php }

// ══════════════════════════════════════════════════════════
//  TRIAL BALANCE
// ══════════════════════════════════════════════════════════
if ($type === 'trial_balance') {
    $rows = $db->query("
        SELECT a.code, a.name, at.name AS type,
               COALESCE(SUM(jl.debit),0)  AS total_debit,
               COALESCE(SUM(jl.credit),0) AS total_credit
        FROM accounts a
        JOIN account_types at ON at.id = a.account_type_id
        LEFT JOIN journal_lines jl ON jl.account_id = a.id
        LEFT JOIN journal_entries je ON je.id = jl.entry_id AND je.status='posted'
            AND je.entry_date BETWEEN " . $db->quote($from) . " AND " . $db->quote($to) . "
        WHERE a.active = TRUE
        GROUP BY a.id, a.code, a.name, at.name
        HAVING COALESCE(SUM(jl.debit),0) > 0 OR COALESCE(SUM(jl.credit),0) > 0
        ORDER BY a.code
    ")->fetchAll();

    $tot_d = array_sum(array_column($rows, 'total_debit'));
    $tot_c = array_sum(array_column($rows, 'total_credit'));
    $balanced = abs($tot_d - $tot_c) < 0.01;

    render_header('Trial Balance', 'trial');
?>
<div class="page-header">
  <div class="page-title">Trial Balance</div>
  <div class="flex-gap">
    <a href="?type=pnl&from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-ghost btn-sm">P&amp;L</a>
    <a href="?type=balance_sheet&from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-ghost btn-sm">Balance Sheet</a>
    <a href="?type=ledger_card" class="btn btn-ghost btn-sm">Ledger Card</a>
  </div>
</div>
<?php date_filter($from, $to, $type); ?>

<div class="card mb-4" style="border-top:3px solid <?= $balanced ? 'var(--green)' : 'var(--red)' ?>;">
  <div class="flex-between">
    <div style="display:flex; gap:2rem;">
      <div><div class="text-muted">Total Debits</div><div style="font-size:20px; font-weight:700;"><?= money($tot_d) ?></div></div>
      <div><div class="text-muted">Total Credits</div><div style="font-size:20px; font-weight:700;"><?= money($tot_c) ?></div></div>
    </div>
    <span class="pill <?= $balanced ? 'pill-green' : 'pill-red' ?>" style="font-size:13px;">
      <?= $balanced ? '✓ Balanced' : '✗ Out of Balance by ' . money(abs($tot_d - $tot_c)) ?>
    </span>
  </div>
</div>

<div class="card">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Code</th><th>Account Name</th><th>Type</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
      <tbody>
        <?php $cur_type = ''; foreach ($rows as $row): ?>
        <?php if ($row['type'] !== $cur_type): $cur_type = $row['type']; ?>
        <tr><td colspan="5" style="background:rgba(255,255,255,0.03); font-size:11px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:0.08em; padding:6px 12px;"><?= e($cur_type) ?></td></tr>
        <?php endif; ?>
        <tr>
          <td><?= e($row['code']) ?></td>
          <td><?= e($row['name']) ?></td>
          <td><?= e($row['type']) ?></td>
          <td class="num td-debit"><?= $row['total_debit']  > 0 ? money($row['total_debit'])  : '—' ?></td>
          <td class="num td-credit"><?= $row['total_credit'] > 0 ? money($row['total_credit']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="border-top:2px solid var(--border); font-weight:700;">
          <td colspan="3" class="fw-bold">TOTAL</td>
          <td class="num td-debit fw-bold"><?= money($tot_d) ?></td>
          <td class="num td-credit fw-bold"><?= money($tot_c) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); }

// ══════════════════════════════════════════════════════════
//  PROFIT & LOSS
// ══════════════════════════════════════════════════════════
if ($type === 'pnl') {
    $revenue_rows = $db->query("
        SELECT a.code, a.name,
               COALESCE(SUM(jl.credit - jl.debit), 0) AS amount
        FROM accounts a
        JOIN account_types at ON at.id = a.account_type_id AND at.name = 'Revenue'
        LEFT JOIN journal_lines jl ON jl.account_id = a.id
        LEFT JOIN journal_entries je ON je.id = jl.entry_id AND je.status='posted'
            AND je.entry_date BETWEEN {$db->quote($from)} AND {$db->quote($to)}
        GROUP BY a.id, a.code, a.name ORDER BY a.code
    ")->fetchAll();

    $expense_rows = $db->query("
        SELECT a.code, a.name,
               COALESCE(SUM(jl.debit - jl.credit), 0) AS amount
        FROM accounts a
        JOIN account_types at ON at.id = a.account_type_id AND at.name = 'Expense'
        LEFT JOIN journal_lines jl ON jl.account_id = a.id
        LEFT JOIN journal_entries je ON je.id = jl.entry_id AND je.status='posted'
            AND je.entry_date BETWEEN {$db->quote($from)} AND {$db->quote($to)}
        GROUP BY a.id, a.code, a.name ORDER BY a.code
    ")->fetchAll();

    $total_revenue  = array_sum(array_column($revenue_rows, 'amount'));
    $total_expenses = array_sum(array_column($expense_rows, 'amount'));
    $net = $total_revenue - $total_expenses;

    render_header('Profit & Loss', 'pnl');
?>
<div class="page-header">
  <div class="page-title">Profit &amp; Loss Statement</div>
  <div class="flex-gap">
    <a href="?type=trial_balance&from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-ghost btn-sm">Trial Balance</a>
    <a href="?type=balance_sheet&from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-ghost btn-sm">Balance Sheet</a>
  </div>
</div>
<?php date_filter($from, $to, $type); ?>

<div class="card" style="max-width:700px;">
  <div style="font-size:13px; color:var(--text-3); margin-bottom:1.4rem;">
    Period: <?= date(DATE_FMT, strtotime($from)) ?> – <?= date(DATE_FMT, strtotime($to)) ?>
  </div>

  <!-- Revenue -->
  <div style="margin-bottom:1.4rem;">
    <div style="font-size:11px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:0.08em; margin-bottom:8px; padding-bottom:6px; border-bottom:1px solid var(--border);">Revenue</div>
    <?php foreach ($revenue_rows as $r): ?>
    <div class="flex-between" style="padding:6px 0; font-size:13px;">
      <span style="color:var(--text-2);"><?= e($r['code']) ?> &nbsp; <?= e($r['name']) ?></span>
      <span class="td-credit"><?= money($r['amount']) ?></span>
    </div>
    <?php endforeach; ?>
    <div class="flex-between" style="padding:8px 0; margin-top:4px; border-top:1px solid var(--border); font-weight:700;">
      <span>Total Revenue</span><span class="td-credit"><?= money($total_revenue) ?></span>
    </div>
  </div>

  <!-- Expenses -->
  <div style="margin-bottom:1.4rem;">
    <div style="font-size:11px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:0.08em; margin-bottom:8px; padding-bottom:6px; border-bottom:1px solid var(--border);">Expenses</div>
    <?php foreach ($expense_rows as $r): ?>
    <div class="flex-between" style="padding:6px 0; font-size:13px;">
      <span style="color:var(--text-2);"><?= e($r['code']) ?> &nbsp; <?= e($r['name']) ?></span>
      <span class="td-debit"><?= money($r['amount']) ?></span>
    </div>
    <?php endforeach; ?>
    <div class="flex-between" style="padding:8px 0; margin-top:4px; border-top:1px solid var(--border); font-weight:700;">
      <span>Total Expenses</span><span class="td-debit"><?= money($total_expenses) ?></span>
    </div>
  </div>

  <!-- Net -->
  <div style="padding:14px 16px; border-radius:10px; background:<?= $net >= 0 ? 'var(--green-dim)' : 'var(--red-dim)' ?>; border:0.5px solid <?= $net >= 0 ? 'rgba(34,197,94,0.25)' : 'rgba(239,68,68,0.25)' ?>;">
    <div class="flex-between">
      <span style="font-size:15px; font-weight:700;"><?= $net >= 0 ? 'NET PROFIT' : 'NET LOSS' ?></span>
      <span style="font-size:20px; font-weight:800; color:<?= $net >= 0 ? 'var(--green)' : 'var(--red)' ?>;"><?= money(abs($net)) ?></span>
    </div>
  </div>
</div>
<?php render_footer(); }

// ══════════════════════════════════════════════════════════
//  BALANCE SHEET
// ══════════════════════════════════════════════════════════
if ($type === 'balance_sheet') {
    function get_type_totals(PDO $db, string $type_name, string $to): array {
        return $db->query("
            SELECT a.code, a.name, at.normal_balance,
                   COALESCE(SUM(jl.debit),0) AS total_debit,
                   COALESCE(SUM(jl.credit),0) AS total_credit
            FROM accounts a
            JOIN account_types at ON at.id = a.account_type_id AND at.name = {$db->quote($type_name)}
            LEFT JOIN journal_lines jl ON jl.account_id = a.id
            LEFT JOIN journal_entries je ON je.id = jl.entry_id AND je.status='posted'
                AND je.entry_date <= {$db->quote($to)}
            GROUP BY a.id, a.code, a.name, at.normal_balance
            ORDER BY a.code
        ")->fetchAll();
    }
    $assets      = get_type_totals($db, 'Asset',     $to);
    $liabilities = get_type_totals($db, 'Liability', $to);
    $equity_rows = get_type_totals($db, 'Equity',    $to);

    $balance_val = fn($r) => $r['normal_balance']==='debit'
        ? $r['total_debit'] - $r['total_credit']
        : $r['total_credit'] - $r['total_debit'];

    $total_assets      = array_sum(array_map($balance_val, $assets));
    $total_liabilities = array_sum(array_map($balance_val, $liabilities));
    $total_equity      = array_sum(array_map($balance_val, $equity_rows));

    render_header('Balance Sheet', 'bs');
?>
<div class="page-header">
  <div class="page-title">Balance Sheet</div>
  <div class="flex-gap">
    <a href="?type=trial_balance&from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-ghost btn-sm">Trial Balance</a>
    <a href="?type=pnl&from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-ghost btn-sm">P&amp;L</a>
  </div>
</div>
<div class="form-group flex-gap mb-6">
  <label style="font-size:11px; color:var(--text-3);">As of:</label>
  <form method="GET" class="flex-gap">
    <input type="hidden" name="type" value="balance_sheet"/>
    <input type="hidden" name="from" value="<?= e($from) ?>"/>
    <input type="date" name="to" value="<?= e($to) ?>" style="width:auto;"/>
    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
  </form>
</div>

<div class="grid-2">
  <!-- ASSETS -->
  <div class="card">
    <div style="font-size:13px; font-weight:700; color:var(--blue-l); text-transform:uppercase; letter-spacing:0.08em; margin-bottom:1rem; padding-bottom:8px; border-bottom:1px solid var(--border);">Assets</div>
    <?php foreach ($assets as $r): $v = $balance_val($r); if ($v == 0) continue; ?>
    <div class="flex-between" style="padding:6px 0; font-size:13px;">
      <span style="color:var(--text-2);"><?= e($r['code']) ?> – <?= e($r['name']) ?></span>
      <span class="fw-bold"><?= money($v) ?></span>
    </div>
    <?php endforeach; ?>
    <div class="flex-between" style="padding:10px 0; margin-top:6px; border-top:2px solid var(--border);">
      <span class="fw-bold">Total Assets</span>
      <span class="fw-bold" style="font-size:16px; color:var(--blue-l);"><?= money($total_assets) ?></span>
    </div>
  </div>

  <!-- LIABILITIES + EQUITY -->
  <div>
    <div class="card" style="margin-bottom:14px;">
      <div style="font-size:13px; font-weight:700; color:var(--red); text-transform:uppercase; letter-spacing:0.08em; margin-bottom:1rem; padding-bottom:8px; border-bottom:1px solid var(--border);">Liabilities</div>
      <?php foreach ($liabilities as $r): $v = $balance_val($r); if ($v == 0) continue; ?>
      <div class="flex-between" style="padding:6px 0; font-size:13px;">
        <span style="color:var(--text-2);"><?= e($r['code']) ?> – <?= e($r['name']) ?></span>
        <span class="fw-bold"><?= money($v) ?></span>
      </div>
      <?php endforeach; ?>
      <div class="flex-between" style="padding:10px 0; margin-top:6px; border-top:2px solid var(--border);">
        <span class="fw-bold">Total Liabilities</span>
        <span class="fw-bold" style="font-size:16px; color:var(--red);"><?= money($total_liabilities) ?></span>
      </div>
    </div>

    <div class="card">
      <div style="font-size:13px; font-weight:700; color:var(--green); text-transform:uppercase; letter-spacing:0.08em; margin-bottom:1rem; padding-bottom:8px; border-bottom:1px solid var(--border);">Equity</div>
      <?php foreach ($equity_rows as $r): $v = $balance_val($r); if ($v == 0) continue; ?>
      <div class="flex-between" style="padding:6px 0; font-size:13px;">
        <span style="color:var(--text-2);"><?= e($r['code']) ?> – <?= e($r['name']) ?></span>
        <span class="fw-bold"><?= money($v) ?></span>
      </div>
      <?php endforeach; ?>
      <div class="flex-between" style="padding:10px 0; margin-top:6px; border-top:2px solid var(--border);">
        <span class="fw-bold">Total Equity</span>
        <span class="fw-bold" style="font-size:16px; color:var(--green);"><?= money($total_equity) ?></span>
      </div>
    </div>

    <div style="padding:12px 16px; border-radius:10px; margin-top:14px; background:<?= abs($total_assets-$total_liabilities-$total_equity)<0.01?'var(--green-dim)':'var(--red-dim)' ?>; border:0.5px solid <?= abs($total_assets-$total_liabilities-$total_equity)<0.01?'rgba(34,197,94,0.25)':'rgba(239,68,68,0.25)' ?>;">
      <div class="flex-between">
        <span class="fw-bold">Liabilities + Equity</span>
        <span class="fw-bold" style="font-size:16px;"><?= money($total_liabilities + $total_equity) ?></span>
      </div>
      <div style="font-size:12px; margin-top:4px; color:<?= abs($total_assets-$total_liabilities-$total_equity)<0.01?'var(--green)':'var(--red)' ?>;">
        <?= abs($total_assets-$total_liabilities-$total_equity)<0.01 ? '✓ Balance sheet balances' : '✗ Does not balance' ?>
      </div>
    </div>
  </div>
</div>
<?php render_footer(); }

// ══════════════════════════════════════════════════════════
//  LEDGER CARD
// ══════════════════════════════════════════════════════════
if ($type === 'ledger_card') {
    $accounts_all = $db->query("SELECT id, code, name FROM accounts WHERE active=TRUE ORDER BY code")->fetchAll();
    $account_id   = (int)($_GET['account_id'] ?? 0);
    $lines        = [];
    $running_bal  = 0;

    if ($account_id) {
        $lines = $db->prepare("
            SELECT je.entry_no, je.entry_date, je.description,
                   jl.debit, jl.credit, jl.memo
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.entry_id
            WHERE jl.account_id = ? AND je.status = 'posted'
              AND je.entry_date BETWEEN ? AND ?
            ORDER BY je.entry_date, je.id
        ");
        $lines->execute([$account_id, $from, $to]);
        $lines = $lines->fetchAll();
    }

    render_header('Ledger Card', 'ledger_card');
?>
<div class="page-header">
  <div class="page-title">Ledger Card</div>
</div>
<form method="GET" class="flex-gap mb-6">
  <input type="hidden" name="type" value="ledger_card"/>
  <div class="form-group" style="flex-direction:row; align-items:center; gap:8px;">
    <label style="white-space:nowrap; font-size:11px; color:var(--text-3);">Account</label>
    <select name="account_id" style="width:auto; min-width:260px;">
      <option value="">— Select Account —</option>
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
  <button type="submit" class="btn btn-primary btn-sm">Show</button>
</form>

<?php if ($account_id && !empty($lines)): ?>
<div class="card">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Date</th><th>Entry No.</th><th>Description</th><th>Memo</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
      <tbody>
        <?php foreach ($lines as $line):
          $running_bal += $line['debit'] - $line['credit'];
        ?>
        <tr>
          <td><?= date(DATE_FMT, strtotime($line['entry_date'])) ?></td>
          <td style="color:var(--blue-l);"><?= e($line['entry_no']) ?></td>
          <td><?= e($line['description']) ?></td>
          <td><?= e($line['memo'] ?? '—') ?></td>
          <td class="num td-debit"><?= $line['debit']  > 0 ? money($line['debit'])  : '—' ?></td>
          <td class="num td-credit"><?= $line['credit'] > 0 ? money($line['credit']) : '—' ?></td>
          <td class="num fw-bold <?= $running_bal >= 0 ? '' : 'td-debit' ?>"><?= money(abs($running_bal)) ?> <?= $running_bal >= 0 ? 'Dr' : 'Cr' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php elseif ($account_id): ?>
<div class="card"><p class="text-muted" style="text-align:center; padding:2rem;">No transactions found for this account in the selected period.</p></div>
<?php endif; ?>
<?php render_footer(); }

// ══════════════════════════════════════════════════════════
//  SUBSIDIARY LEDGER — AP / AR balance and statement by party
// ══════════════════════════════════════════════════════════
if ($type === 'subsidiary') {
    $party_type = $_GET['party_type'] ?? 'supplier';   // supplier (AP) | customer (AR)
    $party_id   = (int)($_GET['party_id'] ?? 0);
    $is_supplier = $party_type === 'supplier';

    // List of all parties with running balance (AP for suppliers, AR for customers)
    if ($is_supplier) {
        $parties = $db->query("
            SELECT s.id, s.name,
                   COALESCE((
                     SELECT SUM(jl.credit - jl.debit)
                     FROM journal_lines jl
                     JOIN journal_entries je ON je.id = jl.entry_id
                     WHERE jl.supplier_id = s.id AND je.status = 'posted'
                   ), 0) AS balance
            FROM suppliers s WHERE s.active = TRUE ORDER BY s.name
        ")->fetchAll();
    } else {
        $parties = $db->query("
            SELECT c.id, c.name,
                   COALESCE((
                     SELECT SUM(jl.debit - jl.credit)
                     FROM journal_lines jl
                     JOIN journal_entries je ON je.id = jl.entry_id
                     WHERE jl.customer_id = c.id AND je.status = 'posted'
                   ), 0) AS balance
            FROM customers c WHERE c.active = TRUE ORDER BY c.name
        ")->fetchAll();
    }

    $total_balance = array_sum(array_column($parties, 'balance'));

    // Statement for one selected party
    $statement = [];
    $party_name = '';
    if ($party_id) {
        $col = $is_supplier ? 'supplier_id' : 'customer_id';
        $name_st = $db->prepare("SELECT name FROM " . ($is_supplier ? 'suppliers' : 'customers') . " WHERE id = ?");
        $name_st->execute([$party_id]);
        $party_name = $name_st->fetchColumn() ?: '';

        $stmt = $db->prepare("
            SELECT je.entry_no, je.entry_date, je.description, jl.debit, jl.credit, jl.memo
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.entry_id
            WHERE jl.$col = ? AND je.status = 'posted'
              AND je.entry_date BETWEEN ? AND ?
            ORDER BY je.entry_date, je.id
        ");
        $stmt->execute([$party_id, $from, $to]);
        $statement = $stmt->fetchAll();
    }

    render_header('Subsidiary Ledger', 'subsidiary');
?>
<div class="page-header">
  <div>
    <div class="page-title">Subsidiary Ledger</div>
    <div class="page-sub">Accounts Payable / Receivable broken out by individual supplier or customer</div>
  </div>
  <div class="flex-gap">
    <a href="?type=subsidiary&party_type=supplier" class="btn btn-ghost btn-sm <?= $is_supplier?'tb-active':'' ?>">Suppliers (AP)</a>
    <a href="?type=subsidiary&party_type=customer" class="btn btn-ghost btn-sm <?= !$is_supplier?'tb-active':'' ?>">Customers (AR)</a>
  </div>
</div>

<div class="grid-2">
  <!-- Party list with balances -->
  <div class="card">
    <div class="card-title"><?= $is_supplier ? 'Accounts Payable by Supplier' : 'Accounts Receivable by Customer' ?></div>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead><tr><th><?= $is_supplier ? 'Supplier' : 'Customer' ?></th><th class="num">Balance</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($parties as $p): ?>
          <tr <?= $p['id']==$party_id ? 'style="background:rgba(59,130,246,0.07);"' : '' ?>>
            <td class="fw-bold"><?= e($p['name']) ?></td>
            <td class="num <?= $p['balance'] != 0 ? ($is_supplier ? 'td-credit' : 'td-debit') : '' ?>"><?= money(abs($p['balance'])) ?></td>
            <td><a href="?type=subsidiary&party_type=<?= $party_type ?>&party_id=<?= $p['id'] ?>&from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-ghost btn-sm">Statement</a></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($parties)): ?>
          <tr><td colspan="3" class="text-muted" style="text-align:center; padding:1.5rem;">No <?= $is_supplier?'suppliers':'customers' ?> yet.</td></tr>
          <?php endif; ?>
        </tbody>
        <?php if (!empty($parties)): ?>
        <tfoot>
          <tr style="border-top:2px solid var(--border);">
            <td class="fw-bold">Total <?= $is_supplier ? 'Payable' : 'Receivable' ?></td>
            <td class="num fw-bold" style="font-size:15px;"><?= money(abs($total_balance)) ?></td>
            <td></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <!-- Statement for selected party -->
  <div class="card">
    <div class="card-title">
      <?= $party_id ? 'Statement — ' . e($party_name) : 'Select a party to view their statement' ?>
    </div>
    <?php if ($party_id): ?>
    <form method="GET" class="flex-gap mb-4">
      <input type="hidden" name="type" value="subsidiary"/>
      <input type="hidden" name="party_type" value="<?= e($party_type) ?>"/>
      <input type="hidden" name="party_id" value="<?= $party_id ?>"/>
      <input type="date" name="from" value="<?= e($from) ?>" style="width:auto;"/>
      <input type="date" name="to" value="<?= e($to) ?>" style="width:auto;"/>
      <button type="submit" class="btn btn-primary btn-sm">Apply</button>
    </form>
    <?php if (!empty($statement)): ?>
    <?php $running = 0; ?>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead><tr><th>Date</th><th>Entry No.</th><th>Description</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
        <tbody>
          <?php foreach ($statement as $line): $running += $line['debit'] - $line['credit']; ?>
          <tr>
            <td><?= date(DATE_FMT, strtotime($line['entry_date'])) ?></td>
            <td style="color:var(--blue-l);"><?= e($line['entry_no']) ?></td>
            <td><?= e($line['description']) ?></td>
            <td class="num td-debit"><?= $line['debit']  > 0 ? money($line['debit'])  : '—' ?></td>
            <td class="num td-credit"><?= $line['credit'] > 0 ? money($line['credit']) : '—' ?></td>
            <td class="num fw-bold"><?= money(abs($running)) ?> <?= $running >= 0 ? 'Dr' : 'Cr' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <p class="text-muted" style="padding:1.5rem 0; text-align:center;">No posted transactions for this party in the selected period.</p>
    <?php endif; ?>
    <?php else: ?>
    <p class="text-muted" style="padding:1.5rem 0; text-align:center;">Click "Statement" next to any <?= $is_supplier?'supplier':'customer' ?> to see their transaction history.</p>
    <?php endif; ?>
  </div>
</div>
<?php render_footer(); }

