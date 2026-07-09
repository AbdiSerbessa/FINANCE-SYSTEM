<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$db     = db();
$action = $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'save') {
        $stmt = $db->prepare("INSERT INTO suppliers (name, contact, phone, email, address) VALUES (?,?,?,?,?)");
        $stmt->execute([$_POST['name'], $_POST['contact'], $_POST['phone'], $_POST['email'], $_POST['address']]);
        flash('Supplier added.');
        header('Location: /modules/suppliers.php'); exit;
    }

    if ($post_action === 'update') {
        $stmt = $db->prepare("UPDATE suppliers SET name=?, contact=?, phone=?, email=?, address=?, active=? WHERE id=?");
        $stmt->execute([
            $_POST['name'], $_POST['contact'], $_POST['phone'], $_POST['email'], $_POST['address'],
            isset($_POST['active']) ? 't' : 'f', (int)$_POST['id']
        ]);
        flash('Supplier updated.');
        header('Location: /modules/suppliers.php'); exit;
    }

    if ($post_action === 'deactivate') {
        $db->prepare("UPDATE suppliers SET active=FALSE WHERE id=?")->execute([(int)$_POST['id']]);
        flash('Supplier deactivated.', 'warning');
        header('Location: /modules/suppliers.php'); exit;
    }
}

if ($action === 'list') {
    $suppliers = $db->query("
        SELECT s.*,
               (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.id) AS po_count,
               (SELECT COALESCE(SUM(total_amount),0) FROM purchase_orders po WHERE po.supplier_id = s.id) AS total_spent,
               COALESCE((
                 SELECT SUM(jl.credit - jl.debit)
                 FROM journal_lines jl
                 JOIN journal_entries je ON je.id = jl.entry_id
                 WHERE jl.supplier_id = s.id AND je.status = 'posted'
               ), 0) AS ap_balance
        FROM suppliers s ORDER BY s.name
    ")->fetchAll();

    render_header('Suppliers', 'suppliers');
?>
<div class="page-header">
  <div>
    <div class="page-title">Suppliers</div>
    <div class="page-sub">Manage your supplier directory &amp; payables</div>
  </div>
  <a href="?action=new" class="btn btn-primary">
    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Supplier
  </a>
</div>

<div class="card">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Name</th><th>Contact</th><th>Phone</th><th>Email</th><th class="num">Orders</th><th class="num">AP Balance</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($suppliers as $s): ?>
        <tr>
          <td class="fw-bold"><?= e($s['name']) ?></td>
          <td><?= e($s['contact'] ?? '—') ?></td>
          <td><?= e($s['phone'] ?? '—') ?></td>
          <td><?= e($s['email'] ?? '—') ?></td>
          <td class="num"><?= $s['po_count'] ?></td>
          <td class="num <?= $s['ap_balance'] > 0 ? 'td-credit' : '' ?> fw-bold">
            <?= money($s['ap_balance']) ?>
            <?php if ($s['ap_balance'] > 0): ?><div class="text-muted" style="font-size:11px;">We owe them</div><?php endif; ?>
          </td>
          <td><?= $s['active'] ? '<span class="pill pill-green">Active</span>' : '<span class="pill pill-red">Inactive</span>' ?></td>
          <td>
            <div class="flex-gap">
              <a href="/modules/reports.php?type=subsidiary&party_type=supplier&party_id=<?= $s['id'] ?>" class="btn btn-ghost btn-sm">Statement</a>
              <a href="?action=edit&id=<?= $s['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
              <?php if ($s['active']): ?>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="deactivate"/><input type="hidden" name="id" value="<?= $s['id'] ?>"/><button class="btn btn-danger btn-sm" data-confirm="Deactivate this supplier?">Deactivate</button></form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($suppliers)): ?>
        <tr><td colspan="8" class="text-muted" style="text-align:center; padding:2rem;">No suppliers yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); }

if ($action === 'new') {
    render_header('Add Supplier', 'suppliers');
?>
<div class="page-header">
  <div class="page-title">Add Supplier</div>
  <a href="/modules/suppliers.php" class="btn btn-ghost">← Back</a>
</div>
<form method="POST">
  <input type="hidden" name="action" value="save"/>
  <div class="card">
    <div class="form-grid form-grid-2">
      <div class="form-group"><label>Supplier Name *</label><input name="name" required/></div>
      <div class="form-group"><label>Contact Person</label><input name="contact"/></div>
      <div class="form-group"><label>Phone</label><input name="phone"/></div>
      <div class="form-group"><label>Email</label><input type="email" name="email"/></div>
      <div class="form-group" style="grid-column:span 2;"><label>Address</label><textarea name="address" rows="2"></textarea></div>
    </div>
    <hr class="div"/>
    <button type="submit" class="btn btn-primary">Save Supplier</button>
  </div>
</form>
<?php render_footer(); }

if ($action === 'edit') {
    $id = (int)$_GET['id'];
    $st = $db->prepare("SELECT * FROM suppliers WHERE id=?"); $st->execute([$id]); $sup = $st->fetch();
    if (!$sup) { flash('Supplier not found.', 'error'); header('Location: /modules/suppliers.php'); exit; }
    render_header('Edit Supplier', 'suppliers');
?>
<div class="page-header">
  <div class="page-title">Edit Supplier</div>
  <a href="/modules/suppliers.php" class="btn btn-ghost">← Back</a>
</div>
<form method="POST">
  <input type="hidden" name="action" value="update"/>
  <input type="hidden" name="id" value="<?= $sup['id'] ?>"/>
  <div class="card">
    <div class="form-grid form-grid-2">
      <div class="form-group"><label>Supplier Name *</label><input name="name" required value="<?= e($sup['name']) ?>"/></div>
      <div class="form-group"><label>Contact Person</label><input name="contact" value="<?= e($sup['contact'] ?? '') ?>"/></div>
      <div class="form-group"><label>Phone</label><input name="phone" value="<?= e($sup['phone'] ?? '') ?>"/></div>
      <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= e($sup['email'] ?? '') ?>"/></div>
      <div class="form-group" style="grid-column:span 2;"><label>Address</label><textarea name="address" rows="2"><?= e($sup['address'] ?? '') ?></textarea></div>
      <div class="form-group">
        <label>Status</label>
        <select name="active">
          <option value="1" <?= $sup['active']?'selected':'' ?>>Active</option>
          <option value="0" <?= !$sup['active']?'selected':'' ?>>Inactive</option>
        </select>
      </div>
    </div>
    <hr class="div"/>
    <button type="submit" class="btn btn-primary">Update Supplier</button>
  </div>
</form>
<?php render_footer(); }
