<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$db     = db();
$action = $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'save') {
        $stmt = $db->prepare("INSERT INTO customers (name, contact, phone, email, address) VALUES (?,?,?,?,?)");
        $stmt->execute([$_POST['name'], $_POST['contact'], $_POST['phone'], $_POST['email'], $_POST['address']]);
        flash('Customer added.');
        header('Location: /modules/customers.php'); exit;
    }

    if ($post_action === 'update') {
        $stmt = $db->prepare("UPDATE customers SET name=?, contact=?, phone=?, email=?, address=?, active=? WHERE id=?");
        $stmt->execute([
            $_POST['name'], $_POST['contact'], $_POST['phone'], $_POST['email'], $_POST['address'],
            isset($_POST['active']) ? 't' : 'f', (int)$_POST['id']
        ]);
        flash('Customer updated.');
        header('Location: /modules/customers.php'); exit;
    }

    if ($post_action === 'deactivate') {
        $db->prepare("UPDATE customers SET active=FALSE WHERE id=?")->execute([(int)$_POST['id']]);
        flash('Customer deactivated.', 'warning');
        header('Location: /modules/customers.php'); exit;
    }
}

// Find the AR account so we can show "balance owed" per customer
$ar_account = $db->query("SELECT id FROM accounts WHERE code = '1100'")->fetchColumn();

if ($action === 'list') {
    $customers = $db->query("
        SELECT c.*,
               COALESCE((
                 SELECT SUM(jl.debit - jl.credit)
                 FROM journal_lines jl
                 JOIN journal_entries je ON je.id = jl.entry_id
                 WHERE jl.customer_id = c.id AND je.status = 'posted'
               ), 0) AS ar_balance
        FROM customers c ORDER BY c.name
    ")->fetchAll();

    render_header('Customers', 'customers');
?>
<div class="page-header">
  <div>
    <div class="page-title">Customers</div>
    <div class="page-sub">Manage your customer directory &amp; receivables</div>
  </div>
  <a href="?action=new" class="btn btn-primary">
    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Customer
  </a>
</div>

<div class="card">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Name</th><th>Contact</th><th>Phone</th><th>Email</th><th class="num">AR Balance</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($customers as $c): ?>
        <tr>
          <td class="fw-bold"><?= e($c['name']) ?></td>
          <td><?= e($c['contact'] ?? '—') ?></td>
          <td><?= e($c['phone'] ?? '—') ?></td>
          <td><?= e($c['email'] ?? '—') ?></td>
          <td class="num <?= $c['ar_balance'] > 0 ? 'td-debit' : '' ?> fw-bold">
            <?= money($c['ar_balance']) ?>
            <?php if ($c['ar_balance'] > 0): ?><div class="text-muted" style="font-size:11px;">Owes us</div><?php endif; ?>
          </td>
          <td><?= $c['active'] ? '<span class="pill pill-green">Active</span>' : '<span class="pill pill-red">Inactive</span>' ?></td>
          <td>
            <div class="flex-gap">
              <a href="/modules/reports.php?type=subsidiary&party_type=customer&party_id=<?= $c['id'] ?>" class="btn btn-ghost btn-sm">Statement</a>
              <a href="?action=edit&id=<?= $c['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
              <?php if ($c['active']): ?>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="deactivate"/><input type="hidden" name="id" value="<?= $c['id'] ?>"/><button class="btn btn-danger btn-sm" data-confirm="Deactivate this customer?">Deactivate</button></form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($customers)): ?>
        <tr><td colspan="7" class="text-muted" style="text-align:center; padding:2rem;">No customers yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); }

if ($action === 'new') {
    render_header('Add Customer', 'customers');
?>
<div class="page-header">
  <div class="page-title">Add Customer</div>
  <a href="/modules/customers.php" class="btn btn-ghost">← Back</a>
</div>
<form method="POST">
  <input type="hidden" name="action" value="save"/>
  <div class="card">
    <div class="form-grid form-grid-2">
      <div class="form-group"><label>Customer Name *</label><input name="name" required/></div>
      <div class="form-group"><label>Contact Person</label><input name="contact"/></div>
      <div class="form-group"><label>Phone</label><input name="phone"/></div>
      <div class="form-group"><label>Email</label><input type="email" name="email"/></div>
      <div class="form-group" style="grid-column:span 2;"><label>Address</label><textarea name="address" rows="2"></textarea></div>
    </div>
    <hr class="div"/>
    <button type="submit" class="btn btn-primary">Save Customer</button>
  </div>
</form>
<?php render_footer(); }

if ($action === 'edit') {
    $id = (int)$_GET['id'];
    $st = $db->prepare("SELECT * FROM customers WHERE id=?"); $st->execute([$id]); $cust = $st->fetch();
    if (!$cust) { flash('Customer not found.', 'error'); header('Location: /modules/customers.php'); exit; }
    render_header('Edit Customer', 'customers');
?>
<div class="page-header">
  <div class="page-title">Edit Customer</div>
  <a href="/modules/customers.php" class="btn btn-ghost">← Back</a>
</div>
<form method="POST">
  <input type="hidden" name="action" value="update"/>
  <input type="hidden" name="id" value="<?= $cust['id'] ?>"/>
  <div class="card">
    <div class="form-grid form-grid-2">
      <div class="form-group"><label>Customer Name *</label><input name="name" required value="<?= e($cust['name']) ?>"/></div>
      <div class="form-group"><label>Contact Person</label><input name="contact" value="<?= e($cust['contact'] ?? '') ?>"/></div>
      <div class="form-group"><label>Phone</label><input name="phone" value="<?= e($cust['phone'] ?? '') ?>"/></div>
      <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= e($cust['email'] ?? '') ?>"/></div>
      <div class="form-group" style="grid-column:span 2;"><label>Address</label><textarea name="address" rows="2"><?= e($cust['address'] ?? '') ?></textarea></div>
      <div class="form-group">
        <label>Status</label>
        <select name="active">
          <option value="1" <?= $cust['active']?'selected':'' ?>>Active</option>
          <option value="0" <?= !$cust['active']?'selected':'' ?>>Inactive</option>
        </select>
      </div>
    </div>
    <hr class="div"/>
    <button type="submit" class="btn btn-primary">Update Customer</button>
  </div>
</form>
<?php render_footer(); }
