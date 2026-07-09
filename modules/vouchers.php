<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$db   = db();
$type = $_GET['type'] ?? 'PV';           // PV = Payment Voucher (cash out) | RV = Receipt Voucher (cash in)
$type = in_array($type, ['PV','RV']) ? $type : 'PV';
$action = $_GET['action'] ?? 'list';

$is_pv = $type === 'PV';
$label = $is_pv ? 'Payment Voucher' : 'Receipt Voucher';
$active_key = $is_pv ? 'pv' : 'rv';

// Cash/Bank style accounts to pay from or receive into
$cash_accounts = $db->query("SELECT id, code, name FROM accounts WHERE code IN ('1000','1010') AND active=TRUE ORDER BY code")->fetchAll();
// All accounts for the split lines (expense/AP for PV, revenue/AR for RV, but allow any)
$all_accounts  = $db->query("SELECT id, code, name FROM accounts WHERE active=TRUE ORDER BY code")->fetchAll();
$suppliers     = $db->query("SELECT id, name FROM suppliers WHERE active=TRUE ORDER BY name")->fetchAll();
$customers     = $db->query("SELECT id, name FROM customers WHERE active=TRUE ORDER BY name")->fetchAll();

// ════════════════════════════════════════════════════════════
//  POST — Save / Post / Void
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';
    $v_type = $_POST['voucher_type'] ?? 'PV';

    if ($post_action === 'save') {
        try {
            $db->beginTransaction();
            $voucher_no = next_voucher_no($v_type);

            // Sum all split lines — this is the total cash movement
            $lines = $_POST['lines'] ?? [];
            $total = 0;
            foreach ($lines as $l) {
                if (!$l['account_id']) continue;
                $total += (float)($l['amount'] ?? 0);
            }
            if ($total <= 0) {
                throw new Exception('Voucher must have at least one line with an amount greater than zero.');
            }

            $stmt = $db->prepare("
                INSERT INTO vouchers (voucher_no, voucher_type, voucher_date, paid_through, payee_payer, total_amount, description, status, created_by)
                VALUES (?,?,?,?,?,?,?,'draft',?)
            ");
            $stmt->execute([
                $voucher_no, $v_type, $_POST['voucher_date'], $_POST['paid_through'],
                $_POST['payee_payer'] ?? null, $total, $_POST['description'] ?? null, auth()['id']
            ]);
            $voucher_id = $db->lastInsertId('vouchers_id_seq');

            foreach ($lines as $l) {
                if (!$l['account_id']) continue;
                $amt = (float)($l['amount'] ?? 0);
                if ($amt <= 0) continue;
                $db->prepare("INSERT INTO voucher_lines (voucher_id, account_id, supplier_id, customer_id, amount, memo) VALUES (?,?,?,?,?,?)")
                   ->execute([
                       $voucher_id,
                       $l['account_id'],
                       $l['supplier_id'] ?: null,
                       $l['customer_id'] ?: null,
                       $amt,
                       $l['memo'] ?? null
                   ]);
            }

            $db->commit();
            flash("$v_type {$voucher_no} saved as draft.");
            header("Location: /modules/vouchers.php?type=$v_type"); exit;
        } catch (Exception $e) {
            $db->rollBack();
            flash('Error: ' . $e->getMessage(), 'error');
            header("Location: /modules/vouchers.php?type=$v_type&action=new"); exit;
        }
    }

    if ($post_action === 'post_voucher') {
        $id = (int)$_POST['id'];
        $db->beginTransaction();
        $vst = $db->prepare("SELECT * FROM vouchers WHERE id=?"); $vst->execute([$id]); $v = $vst->fetch();
        $lst = $db->prepare("SELECT * FROM voucher_lines WHERE voucher_id=?"); $lst->execute([$id]); $lines = $lst->fetchAll();

        // Build the journal entry:
        //   PV (cash out): each split line is a DEBIT (expense/AP/etc), the cash/bank account is the CREDIT
        //   RV (cash in):  each split line is a CREDIT (revenue/AR/etc), the cash/bank account is the DEBIT
        $entry_no = next_entry_no();
        $desc = ($v['voucher_type']==='PV' ? 'Payment Voucher ' : 'Receipt Voucher ') . $v['voucher_no'] . ($v['description'] ? ' — ' . $v['description'] : '');
        $db->prepare("INSERT INTO journal_entries (entry_no, entry_date, description, reference, type, status, created_by, approved_by) VALUES (?,?,?,?,?,'posted',?,?)")
           ->execute([$entry_no, $v['voucher_date'], $desc, $v['voucher_no'], strtolower($v['voucher_type'])==='pv'?'payment':'receipt', $v['created_by'], auth()['id']]);
        $je_id = $db->lastInsertId('journal_entries_id_seq');

        foreach ($lines as $l) {
            if ($v['voucher_type'] === 'PV') {
                // Debit the expense/AP/etc account, tagged to party if given
                $db->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit, memo, supplier_id, customer_id) VALUES (?,?,?,0,?,?,?)")
                   ->execute([$je_id, $l['account_id'], $l['amount'], $l['memo'], $l['supplier_id'], $l['customer_id']]);
            } else {
                // Credit the revenue/AR/etc account, tagged to party if given
                $db->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit, memo, supplier_id, customer_id) VALUES (?,?,0,?,?,?,?)")
                   ->execute([$je_id, $l['account_id'], $l['amount'], $l['memo'], $l['supplier_id'], $l['customer_id']]);
            }
        }
        // Single line for the cash/bank side = total amount
        if ($v['voucher_type'] === 'PV') {
            $db->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit, memo) VALUES (?,?,0,?,?)")
               ->execute([$je_id, $v['paid_through'], $v['total_amount'], 'Per ' . $v['voucher_no']]);
        } else {
            $db->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit, memo) VALUES (?,?,?,0,?)")
               ->execute([$je_id, $v['paid_through'], $v['total_amount'], 'Per ' . $v['voucher_no']]);
        }

        $db->prepare("UPDATE vouchers SET status='posted', approved_by=?, journal_entry_id=? WHERE id=?")
           ->execute([auth()['id'], $je_id, $id]);
        $db->commit();
        flash($v['voucher_type'] . ' posted. Journal entry ' . $entry_no . ' created.');
        header("Location: /modules/vouchers.php?type={$v['voucher_type']}"); exit;
    }

    if ($post_action === 'void_voucher') {
        $id = (int)$_POST['id'];
        $vst = $db->prepare("SELECT * FROM vouchers WHERE id=?"); $vst->execute([$id]); $v = $vst->fetch();
        $db->beginTransaction();
        $db->prepare("UPDATE vouchers SET status='void' WHERE id=?")->execute([$id]);
        if ($v['journal_entry_id']) {
            $db->prepare("UPDATE journal_entries SET status='void' WHERE id=?")->execute([$v['journal_entry_id']]);
        }
        $db->commit();
        flash('Voucher voided.', 'warning');
        header("Location: /modules/vouchers.php?type={$v['voucher_type']}"); exit;
    }
}

// ════════════════════════════════════════════════════════════
//  LIST
// ════════════════════════════════════════════════════════════
if ($action === 'list') {
    $stmt = $db->prepare("
        SELECT v.*, a.name AS paid_through_name, u.full_name AS creator
        FROM vouchers v
        JOIN accounts a ON a.id = v.paid_through
        LEFT JOIN users u ON u.id = v.created_by
        WHERE v.voucher_type = ?
        ORDER BY v.created_at DESC LIMIT 100
    ");
    $stmt->execute([$type]);
    $vouchers = $stmt->fetchAll();

    render_header($label . 's', $active_key);
?>
<div class="page-header">
  <div>
    <div class="page-title"><?= e($label) ?>s</div>
    <div class="page-sub"><?= $is_pv ? 'Record cash and bank payments out — splittable across multiple accounts or parties' : 'Record cash and bank receipts in — splittable across multiple accounts or parties' ?></div>
  </div>
  <div class="flex-gap">
    <a href="?type=PV" class="btn btn-ghost btn-sm <?= $is_pv?'tb-active':'' ?>">Payment Vouchers</a>
    <a href="?type=RV" class="btn btn-ghost btn-sm <?= !$is_pv?'tb-active':'' ?>">Receipt Vouchers</a>
    <a href="?type=<?= $type ?>&action=new" class="btn btn-primary">
      <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      New <?= e($label) ?>
    </a>
  </div>
</div>

<div class="card">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr><th>Voucher No.</th><th>Date</th><?= $is_pv ? '<th>Paid From</th>' : '<th>Received Into</th>' ?><th>Payee/Payer</th><th>Description</th><th class="num">Total</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($vouchers as $v): ?>
        <tr>
          <td class="fw-bold" style="color:var(--blue-l);"><?= e($v['voucher_no']) ?></td>
          <td><?= date(DATE_FMT, strtotime($v['voucher_date'])) ?></td>
          <td><?= e($v['paid_through_name']) ?></td>
          <td><?= e($v['payee_payer'] ?? '—') ?></td>
          <td><?= e($v['description'] ?? '—') ?></td>
          <td class="num fw-bold"><?= money($v['total_amount']) ?></td>
          <td><?php $sc=['draft'=>'pill-amber','posted'=>'pill-green','void'=>'pill-red']; ?>
            <span class="pill <?= $sc[$v['status']] ?>"><?= ucfirst($v['status']) ?></span></td>
          <td>
            <div class="flex-gap">
              <a href="?type=<?= $type ?>&action=view&id=<?= $v['id'] ?>" class="btn btn-ghost btn-sm">View</a>
              <?php if ($v['status']==='draft'): ?>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="post_voucher"/><input type="hidden" name="id" value="<?= $v['id'] ?>"/><button class="btn btn-success btn-sm">Post</button></form>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="void_voucher"/><input type="hidden" name="id" value="<?= $v['id'] ?>"/><button class="btn btn-danger btn-sm" data-confirm="Void this voucher?">Void</button></form>
              <?php elseif ($v['status']==='posted'): ?>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="void_voucher"/><input type="hidden" name="id" value="<?= $v['id'] ?>"/><button class="btn btn-danger btn-sm" data-confirm="Void this voucher? This will also void the linked journal entry.">Void</button></form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($vouchers)): ?>
        <tr><td colspan="8" class="text-muted" style="text-align:center; padding:2rem;">No <?= strtolower($label) ?>s yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); }

// ════════════════════════════════════════════════════════════
//  NEW VOUCHER FORM — multi-line split across accounts/parties
// ════════════════════════════════════════════════════════════
if ($action === 'new') {
    render_header('New ' . $label, $active_key);
?>
<div class="page-header">
  <div>
    <div class="page-title">New <?= e($label) ?></div>
    <div class="page-sub"><?= $is_pv ? 'One payment can be split across multiple expense/payable accounts and suppliers at once' : 'One receipt can be split across multiple revenue/receivable accounts and customers at once' ?></div>
  </div>
  <a href="/modules/vouchers.php?type=<?= $type ?>" class="btn btn-ghost">← Back</a>
</div>

<form method="POST">
  <input type="hidden" name="action" value="save"/>
  <input type="hidden" name="voucher_type" value="<?= $type ?>"/>

  <div class="card mb-6">
    <div class="card-title"><?= e($label) ?> Details</div>
    <div class="form-grid form-grid-3">
      <div class="form-group">
        <label><?= $is_pv ? 'Pay From *' : 'Receive Into *' ?></label>
        <select name="paid_through" required>
          <option value="">— Select Account —</option>
          <?php foreach ($cash_accounts as $a): ?>
          <option value="<?= $a['id'] ?>"><?= e($a['code']) ?> — <?= e($a['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Date *</label>
        <input type="date" name="voucher_date" value="<?= date('Y-m-d') ?>" required/>
      </div>
      <div class="form-group">
        <label><?= $is_pv ? 'Payee' : 'Payer' ?> (display name)</label>
        <input type="text" name="payee_payer" placeholder="<?= $is_pv ? 'e.g. Steel Supply Co.' : 'e.g. ABC Retailers' ?>"/>
      </div>
      <div class="form-group" style="grid-column:span 3;">
        <label>Description</label>
        <input type="text" name="description" placeholder="Purpose of this voucher"/>
      </div>
    </div>
  </div>

  <div class="card mb-6">
    <div class="flex-between mb-4">
      <div class="card-title">Split Lines — <?= $is_pv ? 'each line is debited to that account' : 'each line is credited to that account' ?></div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="addVoucherLine()">+ Add Line</button>
    </div>
    <div class="tbl-wrap">
      <table class="lines-table">
        <thead>
          <tr>
            <th style="width:28%">Account</th>
            <th style="width:18%">Supplier (optional)</th>
            <th style="width:18%">Customer (optional)</th>
            <th style="width:14%">Amount</th>
            <th>Memo</th>
            <th style="width:50px"></th>
          </tr>
        </thead>
        <tbody id="voucher-lines-body"></tbody>
      </table>
    </div>
    <div class="totals-row" style="margin-top:8px;">
      <span>Total <?= $is_pv ? 'Paid' : 'Received' ?>: <strong id="voucher-total">0.00</strong></span>
    </div>
    <p class="text-muted" style="margin-top:8px;">
      Tip: tag a line to a Supplier or Customer to keep their subsidiary ledger (AP/AR by party) accurate. Leave both blank for general expense/revenue lines.
    </p>
  </div>

  <div class="flex-gap">
    <button type="submit" class="btn btn-primary">Save as Draft</button>
    <a href="/modules/vouchers.php?type=<?= $type ?>" class="btn btn-ghost">Cancel</a>
  </div>
</form>

<script>
const voucherAccounts  = <?= json_encode($all_accounts) ?>;
const voucherSuppliers = <?= json_encode($suppliers) ?>;
const voucherCustomers = <?= json_encode($customers) ?>;
let voucherLineCount = 0;

function addVoucherLine() {
  voucherLineCount++;
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td>
      <select name="lines[${voucherLineCount}][account_id]" required>
        <option value="">— Select Account —</option>
        ${voucherAccounts.map(a => `<option value="${a.id}">${a.code} — ${a.name}</option>`).join('')}
      </select>
    </td>
    <td>
      <select name="lines[${voucherLineCount}][supplier_id]">
        <option value="">—</option>
        ${voucherSuppliers.map(s => `<option value="${s.id}">${s.name}</option>`).join('')}
      </select>
    </td>
    <td>
      <select name="lines[${voucherLineCount}][customer_id]">
        <option value="">—</option>
        ${voucherCustomers.map(c => `<option value="${c.id}">${c.name}</option>`).join('')}
      </select>
    </td>
    <td><input type="number" name="lines[${voucherLineCount}][amount]" step="0.01" min="0" value="0" oninput="recalcVoucherTotal()"/></td>
    <td><input type="text" name="lines[${voucherLineCount}][memo]" placeholder="Memo…"/></td>
    <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove(); recalcVoucherTotal()">✕</button></td>
  `;
  document.getElementById('voucher-lines-body').appendChild(tr);
  recalcVoucherTotal();
}

function recalcVoucherTotal() {
  let total = 0;
  document.querySelectorAll('[name$="[amount]"]').forEach(i => total += parseFloat(i.value) || 0);
  document.getElementById('voucher-total').textContent = total.toFixed(2);
}

// Start with 2 lines by default
addVoucherLine();
addVoucherLine();
</script>
<?php render_footer(); }

// ════════════════════════════════════════════════════════════
//  VIEW VOUCHER
// ════════════════════════════════════════════════════════════
if ($action === 'view') {
    $id = (int)$_GET['id'];
    $st = $db->prepare("
        SELECT v.*, a.code AS pt_code, a.name AS pt_name, u.full_name AS creator, ap.full_name AS approver
        FROM vouchers v
        JOIN accounts a ON a.id = v.paid_through
        LEFT JOIN users u ON u.id = v.created_by
        LEFT JOIN users ap ON ap.id = v.approved_by
        WHERE v.id = ?
    ");
    $st->execute([$id]);
    $v = $st->fetch();
    if (!$v) { flash('Voucher not found.', 'error'); header("Location: /modules/vouchers.php?type=$type"); exit; }

    $lst = $db->prepare("
        SELECT vl.*, a.code, a.name AS account_name, s.name AS supplier_name, c.name AS customer_name
        FROM voucher_lines vl
        JOIN accounts a ON a.id = vl.account_id
        LEFT JOIN suppliers s ON s.id = vl.supplier_id
        LEFT JOIN customers c ON c.id = vl.customer_id
        WHERE vl.voucher_id = ?
        ORDER BY vl.id
    ");
    $lst->execute([$id]);
    $lines = $lst->fetchAll();

    $v_is_pv = $v['voucher_type'] === 'PV';
    render_header($v['voucher_no'], $v_is_pv ? 'pv' : 'rv');
?>
<div class="page-header">
  <div>
    <div class="page-title"><?= e($v['voucher_no']) ?></div>
    <div class="page-sub"><?= e($v['description'] ?? '') ?></div>
  </div>
  <div class="flex-gap">
    <?php $sc=['draft'=>'pill-amber','posted'=>'pill-green','void'=>'pill-red']; ?>
    <span class="pill <?= $sc[$v['status']] ?>" style="font-size:13px;"><?= ucfirst($v['status']) ?></span>
    <?php if ($v['status']==='draft'): ?>
    <form method="POST"><input type="hidden" name="action" value="post_voucher"/><input type="hidden" name="id" value="<?= $id ?>"/><button class="btn btn-success">Post Voucher</button></form>
    <form method="POST"><input type="hidden" name="action" value="void_voucher"/><input type="hidden" name="id" value="<?= $id ?>"/><button class="btn btn-danger" data-confirm="Void this voucher?">Void</button></form>
    <?php elseif ($v['status']==='posted'): ?>
    <form method="POST"><input type="hidden" name="action" value="void_voucher"/><input type="hidden" name="id" value="<?= $id ?>"/><button class="btn btn-danger" data-confirm="Void this voucher? This will also void its journal entry.">Void</button></form>
    <?php endif; ?>
    <a href="/modules/vouchers.php?type=<?= $v['voucher_type'] ?>" class="btn btn-ghost">← Back</a>
  </div>
</div>

<div class="grid-2 mb-6">
  <div class="card">
    <div class="card-title">Voucher Info</div>
    <table style="width:100%; font-size:13px;">
      <tr><td class="text-muted" style="padding:5px 0; width:40%;">Type</td><td class="fw-bold"><?= $v_is_pv ? 'Payment Voucher' : 'Receipt Voucher' ?></td></tr>
      <tr><td class="text-muted" style="padding:5px 0;">Date</td><td><?= date(DATE_FMT, strtotime($v['voucher_date'])) ?></td></tr>
      <tr><td class="text-muted" style="padding:5px 0;"><?= $v_is_pv ? 'Paid From' : 'Received Into' ?></td><td><?= e($v['pt_code']) ?> — <?= e($v['pt_name']) ?></td></tr>
      <tr><td class="text-muted" style="padding:5px 0;"><?= $v_is_pv ? 'Payee' : 'Payer' ?></td><td><?= e($v['payee_payer'] ?? '—') ?></td></tr>
      <tr><td class="text-muted" style="padding:5px 0;">Created By</td><td><?= e($v['creator'] ?? '—') ?></td></tr>
      <?php if ($v['approver']): ?><tr><td class="text-muted" style="padding:5px 0;">Posted By</td><td><?= e($v['approver']) ?></td></tr><?php endif; ?>
    </table>
  </div>
  <div class="card">
    <div class="card-title">Total</div>
    <div style="font-size:28px; font-weight:800;"><?= money($v['total_amount']) ?></div>
    <div class="text-muted" style="margin-top:6px;"><?= count($lines) ?> split line<?= count($lines)!=1?'s':'' ?></div>
  </div>
</div>

<div class="card">
  <div class="card-title">Split Lines</div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Account</th><th>Party</th><th class="num">Amount</th><th>Memo</th></tr></thead>
      <tbody>
        <?php foreach ($lines as $line): ?>
        <tr>
          <td><?= e($line['code']) ?> — <?= e($line['account_name']) ?></td>
          <td>
            <?php if ($line['supplier_name']): ?><span class="pill pill-blue"><?= e($line['supplier_name']) ?></span>
            <?php elseif ($line['customer_name']): ?><span class="pill pill-purple"><?= e($line['customer_name']) ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="num fw-bold"><?= money($line['amount']) ?></td>
          <td><?= e($line['memo'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="border-top:1px solid var(--border);">
          <td colspan="2" class="fw-bold text-right">Total</td>
          <td class="num fw-bold" style="font-size:16px;"><?= money($v['total_amount']) ?></td>
          <td></td>
        </tr>
      </tbody>
    </table>
  </div>
  <?php if ($v['journal_entry_id']): ?>
  <div style="margin-top:1rem;">
    <a href="/modules/journal.php?action=view&id=<?= $v['journal_entry_id'] ?>" class="btn btn-ghost btn-sm">View Linked Journal Entry →</a>
  </div>
  <?php endif; ?>
</div>
<?php render_footer(); }
