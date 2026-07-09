<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_role('admin');

$db     = db();
$action = $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'save') {
        $hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?,?,?,?)");
        $stmt->execute([$_POST['username'], $hash, $_POST['full_name'], $_POST['role']]);
        flash('User created.');
        header('Location: /modules/users.php'); exit;
    }

    if ($post_action === 'update') {
        $id = (int)$_POST['id'];
        if (!empty($_POST['password'])) {
            $hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
            $db->prepare("UPDATE users SET full_name=?, role=?, active=?, password=? WHERE id=?")
               ->execute([$_POST['full_name'], $_POST['role'], isset($_POST['active'])?'t':'f', $hash, $id]);
        } else {
            $db->prepare("UPDATE users SET full_name=?, role=?, active=? WHERE id=?")
               ->execute([$_POST['full_name'], $_POST['role'], isset($_POST['active'])?'t':'f', $id]);
        }
        flash('User updated.');
        header('Location: /modules/users.php'); exit;
    }

    if ($post_action === 'deactivate') {
        $id = (int)$_POST['id'];
        if ($id == auth()['id']) {
            flash('You cannot deactivate your own account.', 'error');
        } else {
            $db->prepare("UPDATE users SET active=FALSE WHERE id=?")->execute([$id]);
            flash('User deactivated.', 'warning');
        }
        header('Location: /modules/users.php'); exit;
    }
}

if ($action === 'list') {
    $users = $db->query("SELECT * FROM users ORDER BY full_name")->fetchAll();
    render_header('Users', 'users');
?>
<div class="page-header">
  <div>
    <div class="page-title">User Management</div>
    <div class="page-sub">Manage system accounts and roles</div>
  </div>
  <a href="?action=new" class="btn btn-primary">
    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    New User
  </a>
</div>

<div class="card">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Username</th><th>Full Name</th><th>Role</th><th>Created</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td class="fw-bold"><?= e($u['username']) ?></td>
          <td><?= e($u['full_name']) ?></td>
          <td><?php $rc=['admin'=>'pill-red','accountant'=>'pill-blue','staff'=>'pill-amber']; ?>
            <span class="pill <?= $rc[$u['role']] ?? 'pill-blue' ?>"><?= ucfirst($u['role']) ?></span></td>
          <td><?= date(DATE_FMT, strtotime($u['created_at'])) ?></td>
          <td><?= $u['active'] ? '<span class="pill pill-green">Active</span>' : '<span class="pill pill-red">Inactive</span>' ?></td>
          <td>
            <div class="flex-gap">
              <a href="?action=edit&id=<?= $u['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
              <?php if ($u['active'] && $u['id'] != auth()['id']): ?>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="deactivate"/><input type="hidden" name="id" value="<?= $u['id'] ?>"/><button class="btn btn-danger btn-sm" data-confirm="Deactivate this user?">Deactivate</button></form>
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
    render_header('New User', 'users');
?>
<div class="page-header">
  <div class="page-title">New User</div>
  <a href="/modules/users.php" class="btn btn-ghost">← Back</a>
</div>
<form method="POST">
  <input type="hidden" name="action" value="save"/>
  <div class="card">
    <div class="form-grid form-grid-2">
      <div class="form-group"><label>Username *</label><input name="username" required/></div>
      <div class="form-group"><label>Full Name *</label><input name="full_name" required/></div>
      <div class="form-group"><label>Password *</label><input type="password" name="password" required minlength="6"/></div>
      <div class="form-group"><label>Role *</label>
        <select name="role" required>
          <option value="staff">Staff</option>
          <option value="accountant">Accountant</option>
          <option value="admin">Admin</option>
        </select>
      </div>
    </div>
    <hr class="div"/>
    <button type="submit" class="btn btn-primary">Create User</button>
  </div>
</form>
<?php render_footer(); }

if ($action === 'edit') {
    $id = (int)$_GET['id'];
    $st = $db->prepare("SELECT * FROM users WHERE id=?"); $st->execute([$id]); $u = $st->fetch();
    if (!$u) { flash('User not found.', 'error'); header('Location: /modules/users.php'); exit; }
    render_header('Edit User', 'users');
?>
<div class="page-header">
  <div class="page-title">Edit User — <?= e($u['username']) ?></div>
  <a href="/modules/users.php" class="btn btn-ghost">← Back</a>
</div>
<form method="POST">
  <input type="hidden" name="action" value="update"/>
  <input type="hidden" name="id" value="<?= $u['id'] ?>"/>
  <div class="card">
    <div class="form-grid form-grid-2">
      <div class="form-group"><label>Username</label><input value="<?= e($u['username']) ?>" disabled style="opacity:0.5;"/></div>
      <div class="form-group"><label>Full Name *</label><input name="full_name" required value="<?= e($u['full_name']) ?>"/></div>
      <div class="form-group"><label>New Password (leave blank to keep current)</label><input type="password" name="password" minlength="6"/></div>
      <div class="form-group"><label>Role *</label>
        <select name="role" required>
          <?php foreach (['staff','accountant','admin'] as $r): ?>
          <option value="<?= $r ?>" <?= $u['role']==$r?'selected':'' ?>><?= ucfirst($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Status</label>
        <select name="active">
          <option value="1" <?= $u['active']?'selected':'' ?>>Active</option>
          <option value="0" <?= !$u['active']?'selected':'' ?>>Inactive</option>
        </select>
      </div>
    </div>
    <hr class="div"/>
    <button type="submit" class="btn btn-primary">Update User</button>
  </div>
</form>
<?php render_footer(); }
