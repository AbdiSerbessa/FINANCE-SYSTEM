<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$db     = db();
$action = $_GET['action'] ?? 'list';
$types  = $db->query("SELECT * FROM account_types ORDER BY id")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'save') {
        $stmt = $db->prepare("INSERT INTO accounts (code, name, account_type_id, parent_id, description) VALUES (?,?,?,?,?)");
        $stmt->execute([
            $_POST['code'], $_POST['name'], $_POST['account_type_id'],
            $_POST['parent_id'] ?: null, $_POST['description'] ?? null
        ]);
        flash('Account created.');
        header('Location: /modules/accounts.php'); exit;
    }

    if ($post_action === 'update') {
        $stmt = $db->prepare("UPDATE accounts SET name=?, account_type_id=?, description=?, active=? WHERE id=?");
        $stmt->execute([
            $_POST['name'], $_POST['account_type_id'], $_POST['description'] ?? null,
            isset($_POST['active']) ? 't' : 'f', (int)$_POST['id']
        ]);
        flash('Account updated.');
        header('Location: /modules/accounts.php'); exit;
    }

    if ($post_action === 'deactivate') {
        $db->prepare("UPDATE accounts SET active=FALSE WHERE id=?")->execute([(int)$_POST['id']]);
        flash('Account deactivated.', 'warning');
        header('Location: /modules/accounts.php'); exit;
    }
}

if ($action === 'list') {
    $accounts = $db->query("
        SELECT a.*, at.name AS type_name, at.normal_balance,
               (SELECT COUNT(*) FROM journal_lines jl WHERE jl.account_id = a.id) AS usage_count
        FROM accounts a
        JOIN account_types at ON at.id = a.account_type_id
        ORDER BY a.code
    ")->fetchAll();

    render_header('Chart of Accounts', 'accounts');
?>
<div class="page-header">
  <div>
    <div class="page-title">Chart of Accounts</div>
    <div class="page-sub">Manage your general ledger account structure</div>
  </div>
  <a href="?action=new" class="btn btn-primary">
    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    New Account
  </a>
</div>

<div class="card">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Normal Balance</th><th>Usage</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php $cur = ''; foreach ($accounts as $a): ?>
        <?php if ($a['type_name'] !== $cur): $cur = $a['type_name']; ?>
        <tr><td colspan="7" style="background:rgba(255,255,255,0.03); font-size:11px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:0.08em; padding:6px 12px;"><?= e($cur) ?></td></tr>
        <?php endif; ?>
        <tr>
          <td class="fw-bold"><?= e($a['code']) ?></td>
          <td><?= e($a['name']) ?></td>
          <td><span class="pill pill-blue"><?= e($a['type_name']) ?></span></td>
          <td><?= ucfirst($a['normal_balance']) ?></td>
          <td><?= $a['usage_count'] ?> entries</td>
          <td><?= $a['active'] ? '<span class="pill pill-green">Active</span>' : '<span class="pill pill-red">Inactive</span>' ?></td>
          <td>
            <div class="flex-gap">
              <a href="?action=edit&id=<?= $a['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
              <?php if ($a['active'] && $a['usage_count'] == 0): ?>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="deactivate"/><input type="hidden" name="id" value="<?= $a['id'] ?>"/><button class="btn btn-danger btn-sm" data-confirm="Deactivate this account?">Deactivate</button></form>
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
    render_header('New Account', 'accounts');
?>
<div class="page-header">
  <div class="page-title">New Account</div>
  <a href="/modules/accounts.php" class="btn btn-ghost">← Back</a>
</div>
<form method="POST">
  <input type="hidden" name="action" value="save"/>
  <div class="card">
    <div class="form-grid form-grid-2">
      <div class="form-group"><label>Account Code *</label><input name="code" required placeholder="e.g. 1300"/></div>
      <div class="form-group"><label>Account Name *</label><input name="name" required placeholder="e.g. Spare Parts Inventory"/></div>
      <div class="form-group"><label>Account Type *</label>
        <select name="account_type_id" required>
          <option value="">— Select Type —</option>
          <?php foreach ($types as $t): ?>
          <option value="<?= $t['id'] ?>"><?= e($t['name']) ?> (normally <?= $t['normal_balance'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Parent Account (optional)</label>
        <select name="parent_id">
          <option value="">— None —</option>
          <?php foreach ($db->query("SELECT id, code, name FROM accounts WHERE active=TRUE ORDER BY code")->fetchAll() as $p): ?>
          <option value="<?= $p['id'] ?>"><?= e($p['code']) ?> — <?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="grid-column:span 2;"><label>Description</label><textarea name="description" rows="2"></textarea></div>
    </div>
    <hr class="div"/>
    <button type="submit" class="btn btn-primary">Save Account</button>
  </div>
</form>
<?php render_footer(); }

if ($action === 'edit') {
    $id = (int)$_GET['id'];
    $st = $db->prepare("SELECT * FROM accounts WHERE id=?"); $st->execute([$id]); $acc = $st->fetch();
    if (!$acc) { flash('Account not found.', 'error'); header('Location: /modules/accounts.php'); exit; }
    render_header('Edit Account', 'accounts');
?>
<div class="page-header">
  <div class="page-title">Edit Account — <?= e($acc['code']) ?></div>
  <a href="/modules/accounts.php" class="btn btn-ghost">← Back</a>
</div>
<form method="POST">
  <input type="hidden" name="action" value="update"/>
  <input type="hidden" name="id" value="<?= $acc['id'] ?>"/>
  <div class="card">
    <div class="form-grid form-grid-2">
      <div class="form-group"><label>Account Code</label><input value="<?= e($acc['code']) ?>" disabled style="opacity:0.5;"/></div>
      <div class="form-group"><label>Account Name *</label><input name="name" required value="<?= e($acc['name']) ?>"/></div>
      <div class="form-group"><label>Account Type *</label>
        <select name="account_type_id" required>
          <?php foreach ($types as $t): ?>
          <option value="<?= $t['id'] ?>" <?= $t['id']==$acc['account_type_id']?'selected':'' ?>><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Status</label>
        <select name="active">
          <option value="1" <?= $acc['active']?'selected':'' ?>>Active</option>
          <option value="0" <?= !$acc['active']?'selected':'' ?>>Inactive</option>
        </select>
      </div>
      <div class="form-group" style="grid-column:span 2;"><label>Description</label><textarea name="description" rows="2"><?= e($acc['description'] ?? '') ?></textarea></div>
    </div>
    <hr class="div"/>
    <button type="submit" class="btn btn-primary">Update Account</button>
  </div>
</form>
<?php render_footer(); }
