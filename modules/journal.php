<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$db     = db();
$action = $_GET['action'] ?? 'list';

// ── Fetch accounts, suppliers, customers for dropdowns ────
$accounts  = $db->query("SELECT id, code, name FROM accounts WHERE active=TRUE ORDER BY code")->fetchAll();
$suppliers = $db->query("SELECT id, name FROM suppliers WHERE active=TRUE ORDER BY name")->fetchAll();
$customers = $db->query("SELECT id, name FROM customers WHERE active=TRUE ORDER BY name")->fetchAll();

// ── POST: Save new entry ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        try {
            $db->beginTransaction();
            $entry_no = next_entry_no();
            $stmt = $db->prepare("
                INSERT INTO journal_entries (entry_no, entry_date, description, reference, type, status, created_by)
                VALUES (?, ?, ?, ?, 'manual', 'draft', ?)
            ");
            $stmt->execute([
                $entry_no,
                $_POST['entry_date'],
                $_POST['description'],
                $_POST['reference'] ?? null,
                auth()['id'],
            ]);
            $entry_id = $db->lastInsertId('journal_entries_id_seq');
            $lines = $_POST['lines'] ?? [];
            $total_d = 0; $total_c = 0;
            foreach ($lines as $line) {
                $d = (float)($line['debit']  ?? 0);
                $c = (float)($line['credit'] ?? 0);
                if (!$line['account_id'] && $d == 0 && $c == 0) continue;
                $db->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit, memo, supplier_id, customer_id) VALUES (?,?,?,?,?,?,?)")
                   ->execute([
                       $entry_id, $line['account_id'], $d, $c, $line['memo'] ?? null,
                       $line['supplier_id'] ?: null, $line['customer_id'] ?: null
                   ]);
                $total_d += $d; $total_c += $c;
            }
            if (abs($total_d - $total_c) > 0.01) {
                $db->rollBack();
                flash('Entry is not balanced. Debits must equal Credits.', 'error');
            } else {
                $db->commit();
                flash("Journal entry {$entry_no} saved as draft.");
                header('Location: /modules/journal.php'); exit;
            }
        } catch (Exception $e) {
            $db->rollBack();
            flash('Error: ' . $e->getMessage(), 'error');
        }
    }

    if ($action === 'post') {
        $id = (int)$_POST['id'];
        $db->prepare("UPDATE journal_entries SET status='posted', approved_by=? WHERE id=?")
           ->execute([auth()['id'], $id]);
        flash('Entry posted successfully.', 'success');
        header('Location: /modules/journal.php'); exit;
    }

    if ($action === 'void') {
        $id = (int)$_POST['id'];
        $db->prepare("UPDATE journal_entries SET status='void' WHERE id=?")->execute([$id]);
        flash('Entry voided.', 'warning');
        header('Location: /modules/journal.php'); exit;
    }
}

// ── List view ─────────────────────────────────────────────
if ($action === 'list') {
    $status_filter = $_GET['status'] ?? '';
    $where = $status_filter ? "WHERE je.status = " . $db->quote($status_filter) : '';
    $entries = $db->query("
        SELECT je.*, u.full_name AS creator,
               COALESCE(SUM(jl.debit),0) AS total
        FROM journal_entries je
        LEFT JOIN users u ON u.id = je.created_by
        LEFT JOIN journal_lines jl ON jl.entry_id = je.id
        $where
        GROUP BY je.id, u.full_name
        ORDER BY je.created_at DESC
        LIMIT 100
    ")->fetchAll();

    render_header('Journal Entries', 'journal');
?>
<div class="page-header">
  <div>
    <div class="page-title">Journal Entries</div>
    <div class="page-sub">Record and manage double-entry transactions</div>
  </div>
  <div class="flex-gap">
    <a href="?action=list&status=draft"  class="btn btn-ghost btn-sm">Draft</a>
    <a href="?action=list&status=posted" class="btn btn-ghost btn-sm">Posted</a>
    <a href="?action=list"               class="btn btn-ghost btn-sm">All</a>
    <a href="?action=new" class="btn btn-primary">
      <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      New Entry
    </a>
  </div>
</div>

<div class="card">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr><th>Entry No.</th><th>Date</th><th>Description</th><th>Reference</th><th>Created By</th><th class="num">Debit Total</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($entries as $e): ?>
        <tr>
          <td style="color:var(--blue-l); font-weight:600;"><?= e($e['entry_no']) ?></td>
          <td><?= date(DATE_FMT, strtotime($e['entry_date'])) ?></td>
          <td><?= e($e['description']) ?></td>
          <td><?= e($e['reference'] ?? '—') ?></td>
          <td><?= e($e['creator'] ?? '—') ?></td>
          <td class="num"><?= money($e['total']) ?></td>
          <td><?php $sc=['draft'=>'pill-amber','posted'=>'pill-green','void'=>'pill-red']; ?>
            <span class="pill <?= $sc[$e['status']] ?>"><?= ucfirst($e['status']) ?></span>
          </td>
          <td>
            <div class="flex-gap">
              <a href="?action=view&id=<?= $e['id'] ?>" class="btn btn-ghost btn-sm">View</a>
              <?php if ($e['status'] === 'draft'): ?>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="post"/>
                <input type="hidden" name="id" value="<?= $e['id'] ?>"/>
                <button class="btn btn-success btn-sm">Post</button>
              </form>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="void"/>
                <input type="hidden" name="id" value="<?= $e['id'] ?>"/>
                <button class="btn btn-danger btn-sm" data-confirm="Void this entry?">Void</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($entries)): ?>
        <tr><td colspan="8" style="text-align:center; padding:2rem;" class="text-muted">No entries found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); }

// ── New entry form ─────────────────────────────────────────
if ($action === 'new') {
    render_header('New Journal Entry', 'journal');
?>
<div class="page-header">
  <div>
    <div class="page-title">New Journal Entry</div>
    <div class="page-sub">Double-entry — debits must equal credits</div>
  </div>
  <a href="/modules/journal.php" class="btn btn-ghost">← Back</a>
</div>

<form method="POST" action="/modules/journal.php">
  <input type="hidden" name="action" value="save"/>
  <div class="card mb-6">
    <div class="card-title">Entry Details</div>
    <div class="form-grid form-grid-3">
      <div class="form-group">
        <label>Entry Date *</label>
        <input type="date" name="entry_date" value="<?= date('Y-m-d') ?>" required/>
      </div>
      <div class="form-group">
        <label>Reference</label>
        <input type="text" name="reference" placeholder="Invoice no., PO no., etc."/>
      </div>
      <div class="form-group" style="grid-column: span 1;">
        <label>Description *</label>
        <input type="text" name="description" placeholder="Purpose of this entry" required/>
      </div>
    </div>
  </div>

  <div class="card mb-6">
    <div class="flex-between mb-4">
      <div class="card-title">Journal Lines</div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="addLine(accounts, suppliersList, customersList)">+ Add Line</button>
    </div>
    <div class="tbl-wrap">
      <table class="lines-table">
        <thead>
          <tr><th style="width:26%">Account</th><th style="width:14%">Debit</th><th style="width:14%">Credit</th><th style="width:14%">Supplier</th><th style="width:14%">Customer</th><th>Memo</th><th style="width:50px"></th></tr>
        </thead>
        <tbody id="je-lines-body"></tbody>
      </table>
    </div>
    <div class="totals-row" style="margin-top:8px;">
      <span>Total Debit: <strong id="total-debit">0.00</strong></span>
      <span>Total Credit: <strong id="total-credit">0.00</strong></span>
      <span id="balance-indicator" class="pill pill-red">⚠ Not balanced</span>
    </div>
    <p class="text-muted" style="margin-top:8px;">
      Tag a line to a Supplier or Customer (e.g. when debiting/crediting Accounts Payable or Accounts Receivable) to keep their subsidiary ledger accurate. One entry can touch as many different accounts as needed, as long as total debits equal total credits.
    </p>
  </div>

  <div class="flex-gap">
    <button type="submit" class="btn btn-primary">Save as Draft</button>
    <a href="/modules/journal.php" class="btn btn-ghost">Cancel</a>
  </div>
</form>

<script>
const accounts = <?= json_encode($accounts) ?>;
const suppliersList = <?= json_encode($suppliers) ?>;
const customersList = <?= json_encode($customers) ?>;
// Add 2 default lines
addLine(accounts, suppliersList, customersList);
addLine(accounts, suppliersList, customersList);
</script>
<?php render_footer(); }

// ── View single entry ──────────────────────────────────────
if ($action === 'view') {
    $id = (int)$_GET['id'];
    $entry = $db->prepare("SELECT je.*, u.full_name AS creator, ap.full_name AS approver
        FROM journal_entries je
        LEFT JOIN users u  ON u.id  = je.created_by
        LEFT JOIN users ap ON ap.id = je.approved_by
        WHERE je.id = ?")->execute([$id]) ? null : null;
    $stmt = $db->prepare("SELECT je.*, u.full_name AS creator, ap.full_name AS approver
        FROM journal_entries je
        LEFT JOIN users u  ON u.id  = je.created_by
        LEFT JOIN users ap ON ap.id = je.approved_by
        WHERE je.id = ?");
    $stmt->execute([$id]);
    $entry = $stmt->fetch();
    if (!$entry) { flash('Entry not found.', 'error'); header('Location: /modules/journal.php'); exit; }

    $lines_stmt = $db->prepare("
        SELECT jl.*, a.code, a.name AS account_name, s.name AS supplier_name, c.name AS customer_name
        FROM journal_lines jl
        JOIN accounts a ON a.id = jl.account_id
        LEFT JOIN suppliers s ON s.id = jl.supplier_id
        LEFT JOIN customers c ON c.id = jl.customer_id
        WHERE jl.entry_id = ?
        ORDER BY jl.id
    ");
    $lines_stmt->execute([$id]);
    $lines = $lines_stmt->fetchAll();
    $total_d = array_sum(array_column($lines, 'debit'));
    $total_c = array_sum(array_column($lines, 'credit'));

    render_header('View Entry ' . $entry['entry_no'], 'journal');
?>
<div class="page-header">
  <div>
    <div class="page-title"><?= e($entry['entry_no']) ?></div>
    <div class="page-sub"><?= e($entry['description']) ?></div>
  </div>
  <div class="flex-gap">
    <?php $sc=['draft'=>'pill-amber','posted'=>'pill-green','void'=>'pill-red']; ?>
    <span class="pill <?= $sc[$entry['status']] ?>" style="font-size:13px;"><?= ucfirst($entry['status']) ?></span>
    <?php if ($entry['status'] === 'draft'): ?>
    <form method="POST"><input type="hidden" name="action" value="post"/><input type="hidden" name="id" value="<?= $id ?>"/><button class="btn btn-success">Post Entry</button></form>
    <form method="POST"><input type="hidden" name="action" value="void"/><input type="hidden" name="id" value="<?= $id ?>"/><button class="btn btn-danger" data-confirm="Void this entry?">Void</button></form>
    <?php endif; ?>
    <a href="/modules/journal.php" class="btn btn-ghost">← Back</a>
  </div>
</div>

<div class="grid-2 mb-6">
  <div class="card">
    <div class="card-title">Entry Information</div>
    <table style="width:100%; font-size:13px;">
      <tr><td class="text-muted" style="padding:5px 0; width:40%;">Entry No.</td><td class="fw-bold"><?= e($entry['entry_no']) ?></td></tr>
      <tr><td class="text-muted" style="padding:5px 0;">Date</td><td><?= date(DATE_FMT, strtotime($entry['entry_date'])) ?></td></tr>
      <tr><td class="text-muted" style="padding:5px 0;">Reference</td><td><?= e($entry['reference'] ?? '—') ?></td></tr>
      <tr><td class="text-muted" style="padding:5px 0;">Type</td><td><?= ucfirst($entry['type']) ?></td></tr>
      <tr><td class="text-muted" style="padding:5px 0;">Created By</td><td><?= e($entry['creator'] ?? '—') ?></td></tr>
      <?php if ($entry['approver']): ?>
      <tr><td class="text-muted" style="padding:5px 0;">Approved By</td><td><?= e($entry['approver']) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>
  <div class="card">
    <div class="card-title">Totals</div>
    <div style="display:flex; gap:2rem; align-items:center; flex-wrap:wrap;">
      <div><div class="text-muted">Total Debit</div><div style="font-size:22px; font-weight:700;"><?= money($total_d) ?></div></div>
      <div><div class="text-muted">Total Credit</div><div style="font-size:22px; font-weight:700;"><?= money($total_c) ?></div></div>
      <div><?php if (abs($total_d-$total_c)<0.01): ?><span class="pill pill-green">✓ Balanced</span><?php else: ?><span class="pill pill-red">✗ Out of Balance</span><?php endif; ?></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-title">Journal Lines</div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Account Code</th><th>Account Name</th><th class="num">Debit</th><th class="num">Credit</th><th>Party</th><th>Memo</th></tr></thead>
      <tbody>
        <?php foreach ($lines as $line): ?>
        <tr>
          <td><?= e($line['code']) ?></td>
          <td><?= e($line['account_name']) ?></td>
          <td class="num td-debit"><?= $line['debit']  > 0 ? money($line['debit'])  : '—' ?></td>
          <td class="num td-credit"><?= $line['credit'] > 0 ? money($line['credit']) : '—' ?></td>
          <td>
            <?php if ($line['supplier_name']): ?><span class="pill pill-blue"><?= e($line['supplier_name']) ?></span>
            <?php elseif ($line['customer_name']): ?><span class="pill pill-purple"><?= e($line['customer_name']) ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><?= e($line['memo'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="border-top:1px solid var(--border);">
          <td colspan="2" class="fw-bold">Total</td>
          <td class="num td-debit fw-bold"><?= money($total_d) ?></td>
          <td class="num td-credit fw-bold"><?= money($total_c) ?></td>
          <td></td>
          <td></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); }
