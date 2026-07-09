<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$db     = db();
$action = $_GET['action'] ?? 'list';

$employees_all = $db->query("SELECT id, employee_no, full_name, department, base_salary FROM employees WHERE active=TRUE ORDER BY full_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'save_payroll') {
        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO payroll (period_label, period_start, period_end, status, created_by) VALUES (?,?,?,'draft',?)");
        $stmt->execute([$_POST['period_label'], $_POST['period_start'], $_POST['period_end'], auth()['id']]);
        $payroll_id = $db->lastInsertId('payroll_id_seq');
        $total_gross = 0; $total_net = 0;
        foreach ($_POST['items'] ?? [] as $item) {
            if (!$item['employee_id']) continue;
            $gross = (float)$item['gross_salary'];
            $ded   = (float)$item['deductions'];
            $net   = $gross - $ded;
            $db->prepare("INSERT INTO payroll_items (payroll_id, employee_id, gross_salary, deductions, net_salary, notes) VALUES (?,?,?,?,?,?)")
               ->execute([$payroll_id, $item['employee_id'], $gross, $ded, $net, $item['notes'] ?? null]);
            $total_gross += $gross; $total_net += $net;
        }
        $db->prepare("UPDATE payroll SET total_gross=?, total_net=? WHERE id=?")->execute([$total_gross, $total_net, $payroll_id]);
        $db->commit();
        flash('Payroll saved as draft.');
        header('Location: /modules/payroll.php'); exit;
    }

    if ($post_action === 'approve') {
        $id = (int)$_POST['id'];
        $db->prepare("UPDATE payroll SET status='approved' WHERE id=?")->execute([$id]);
        flash('Payroll approved.'); header('Location: /modules/payroll.php'); exit;
    }
    if ($post_action === 'pay') {
        $db->beginTransaction();
        $id = (int)$_POST['id'];
        $db->prepare("UPDATE payroll SET status='paid' WHERE id=?")->execute([$id]);
        // Auto-create journal entry
        $payroll = $db->prepare("SELECT * FROM payroll WHERE id=?"); $payroll->execute([$id]); $p = $payroll->fetch();
        $entry_no = next_entry_no();
        $db->prepare("INSERT INTO journal_entries (entry_no, entry_date, description, type, status, created_by) VALUES (?,CURRENT_DATE,?,'payroll','posted',?)")
           ->execute([$entry_no, 'Payroll - ' . $p['period_label'], auth()['id']]);
        $je_id = $db->lastInsertId('journal_entries_id_seq');
        // Dr Payroll Expense, Cr Salaries Payable
        $db->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) SELECT ?, id, ?, 0 FROM accounts WHERE code='6000'")->execute([$je_id, $p['total_gross']]);
        $db->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) SELECT ?, id, 0, ? FROM accounts WHERE code='2100'")->execute([$je_id, $p['total_net']]);
        $db->commit();
        flash('Payroll marked as paid. Journal entry ' . $entry_no . ' created.');
        header('Location: /modules/payroll.php'); exit;
    }
}

if ($action === 'list') {
    $payrolls = $db->query("
        SELECT p.*, u.full_name AS creator
        FROM payroll p LEFT JOIN users u ON u.id = p.created_by
        ORDER BY p.period_start DESC LIMIT 50
    ")->fetchAll();
    render_header('Payroll', 'payroll');
?>
<div class="page-header">
  <div>
    <div class="page-title">Payroll</div>
    <div class="page-sub">Manage employee payroll periods</div>
  </div>
  <a href="?action=new" class="btn btn-primary">
    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    New Payroll Run
  </a>
</div>
<div class="card">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Period</th><th>From</th><th>To</th><th class="num">Gross</th><th class="num">Net</th><th>Status</th><th>Created By</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($payrolls as $p): ?>
        <tr>
          <td class="fw-bold"><?= e($p['period_label']) ?></td>
          <td><?= date(DATE_FMT, strtotime($p['period_start'])) ?></td>
          <td><?= date(DATE_FMT, strtotime($p['period_end'])) ?></td>
          <td class="num"><?= money($p['total_gross']) ?></td>
          <td class="num fw-bold td-credit"><?= money($p['total_net']) ?></td>
          <td><?php $sc=['draft'=>'pill-amber','approved'=>'pill-blue','paid'=>'pill-green']; ?>
            <span class="pill <?= $sc[$p['status']] ?>"><?= ucfirst($p['status']) ?></span></td>
          <td><?= e($p['creator'] ?? '—') ?></td>
          <td>
            <div class="flex-gap">
              <a href="?action=view&id=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">View</a>
              <?php if ($p['status']==='draft'): ?>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="approve"/><input type="hidden" name="id" value="<?= $p['id'] ?>"/><button class="btn btn-success btn-sm">Approve</button></form>
              <?php elseif ($p['status']==='approved'): ?>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="pay"/><input type="hidden" name="id" value="<?= $p['id'] ?>"/><button class="btn btn-primary btn-sm" data-confirm="Mark as paid and create journal entry?">Pay</button></form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); }

if ($action === 'new') {
    render_header('New Payroll Run', 'payroll');
?>
<div class="page-header">
  <div class="page-title">New Payroll Run</div>
  <a href="/modules/payroll.php" class="btn btn-ghost">← Back</a>
</div>
<form method="POST">
  <input type="hidden" name="action" value="save_payroll"/>
  <div class="card mb-6">
    <div class="card-title">Payroll Period</div>
    <div class="form-grid form-grid-3">
      <div class="form-group"><label>Period Label *</label><input name="period_label" placeholder="e.g. May 2026" required/></div>
      <div class="form-group"><label>From *</label><input type="date" name="period_start" required/></div>
      <div class="form-group"><label>To *</label><input type="date" name="period_end" required/></div>
    </div>
  </div>
  <div class="card mb-6">
    <div class="card-title">Employee Payroll</div>
    <div class="tbl-wrap">
      <table class="tbl" style="font-size:13px;">
        <thead><tr><th>Employee</th><th class="num">Gross Salary</th><th class="num">Deductions</th><th class="num">Net Salary</th><th>Notes</th></tr></thead>
        <tbody>
          <?php foreach ($employees_all as $i => $emp): ?>
          <tr>
            <td>
              <input type="hidden" name="items[<?= $i ?>][employee_id]" value="<?= $emp['id'] ?>"/>
              <strong style="color:var(--text-1);"><?= e($emp['full_name']) ?></strong>
              <div class="text-muted"><?= e($emp['department'] ?? '') ?></div>
            </td>
            <td><input type="number" name="items[<?= $i ?>][gross_salary]" step="0.01" min="0" value="<?= $emp['base_salary'] ?>" style="text-align:right; background:var(--bg-input); border:1px solid var(--border-mid); border-radius:6px; padding:5px 8px; color:var(--text-1); width:120px;"/></td>
            <td><input type="number" name="items[<?= $i ?>][deductions]" step="0.01" min="0" value="0" style="text-align:right; background:var(--bg-input); border:1px solid var(--border-mid); border-radius:6px; padding:5px 8px; color:var(--text-1); width:100px;"/></td>
            <td class="num fw-bold"><span class="net-display"><?= number_format($emp['base_salary'], 2) ?></span></td>
            <td><input type="text" name="items[<?= $i ?>][notes]" placeholder="Optional…" style="background:var(--bg-input); border:1px solid var(--border-mid); border-radius:6px; padding:5px 8px; color:var(--text-1); width:100%;"/></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="totals-row" style="margin-top:10px;">
      <span>Total Gross: <strong id="payroll-total-gross">0.00</strong></span>
      <span>Total Net: <strong id="payroll-total-net">0.00</strong></span>
    </div>
  </div>
  <div class="flex-gap">
    <button type="submit" class="btn btn-primary">Save Payroll</button>
    <a href="/modules/payroll.php" class="btn btn-ghost">Cancel</a>
  </div>
</form>
<script>
// init totals
document.querySelectorAll('[name$="[gross_salary]"]').forEach(i => i.dispatchEvent(new Event('input')));
</script>
<?php render_footer(); }

if ($action === 'view') {
    $id = (int)$_GET['id'];
    $st = $db->prepare("SELECT p.*, u.full_name AS creator FROM payroll p LEFT JOIN users u ON u.id=p.created_by WHERE p.id=?");
    $st->execute([$id]); $payroll = $st->fetch();
    $ist = $db->prepare("SELECT pi.*, e.full_name, e.employee_no, e.department, e.position FROM payroll_items pi JOIN employees e ON e.id=pi.employee_id WHERE pi.payroll_id=? ORDER BY e.full_name");
    $ist->execute([$id]); $items = $ist->fetchAll();
    render_header('Payroll ' . $payroll['period_label'], 'payroll');
?>
<div class="page-header">
  <div>
    <div class="page-title"><?= e($payroll['period_label']) ?></div>
    <div class="page-sub"><?= date(DATE_FMT, strtotime($payroll['period_start'])) ?> – <?= date(DATE_FMT, strtotime($payroll['period_end'])) ?></div>
  </div>
  <div class="flex-gap">
    <?php $sc=['draft'=>'pill-amber','approved'=>'pill-blue','paid'=>'pill-green']; ?>
    <span class="pill <?= $sc[$payroll['status']] ?>" style="font-size:13px;"><?= ucfirst($payroll['status']) ?></span>
    <?php if ($payroll['status']==='draft'): ?>
    <form method="POST"><input type="hidden" name="action" value="approve"/><input type="hidden" name="id" value="<?= $id ?>"/><button class="btn btn-success">Approve</button></form>
    <?php elseif ($payroll['status']==='approved'): ?>
    <form method="POST"><input type="hidden" name="action" value="pay"/><input type="hidden" name="id" value="<?= $id ?>"/><button class="btn btn-primary" data-confirm="Pay and create journal entry?">Mark as Paid</button></form>
    <?php endif; ?>
    <a href="/modules/payroll.php" class="btn btn-ghost">← Back</a>
  </div>
</div>
<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr); margin-bottom:1.4rem;">
  <div class="kpi"><div class="kpi-label">Employees</div><div class="kpi-value"><?= count($items) ?></div></div>
  <div class="kpi amber"><div class="kpi-label">Total Gross</div><div class="kpi-value"><?= money($payroll['total_gross']) ?></div></div>
  <div class="kpi green"><div class="kpi-label">Total Net</div><div class="kpi-value"><?= money($payroll['total_net']) ?></div></div>
</div>
<div class="card">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Emp No.</th><th>Name</th><th>Department</th><th>Position</th><th class="num">Gross</th><th class="num">Deductions</th><th class="num">Net</th><th>Notes</th></tr></thead>
      <tbody>
        <?php foreach ($items as $item): ?>
        <tr>
          <td><?= e($item['employee_no']) ?></td>
          <td class="fw-bold"><?= e($item['full_name']) ?></td>
          <td><?= e($item['department'] ?? '—') ?></td>
          <td><?= e($item['position'] ?? '—') ?></td>
          <td class="num"><?= money($item['gross_salary']) ?></td>
          <td class="num td-debit"><?= money($item['deductions']) ?></td>
          <td class="num fw-bold td-credit"><?= money($item['net_salary']) ?></td>
          <td><?= e($item['notes'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); }
