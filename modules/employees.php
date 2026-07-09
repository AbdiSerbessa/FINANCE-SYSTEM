<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$db     = db();
$action = $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'save') {
        $stmt = $db->prepare("INSERT INTO employees (employee_no, full_name, department, position, base_salary, hire_date) VALUES (?,?,?,?,?,?)");
        $stmt->execute([
            $_POST['employee_no'], $_POST['full_name'], $_POST['department'],
            $_POST['position'], $_POST['base_salary'], $_POST['hire_date'] ?: null
        ]);
        flash('Employee added.');
        header('Location: /modules/employees.php'); exit;
    }

    if ($post_action === 'update') {
        $stmt = $db->prepare("UPDATE employees SET full_name=?, department=?, position=?, base_salary=?, hire_date=?, active=? WHERE id=?");
        $stmt->execute([
            $_POST['full_name'], $_POST['department'], $_POST['position'],
            $_POST['base_salary'], $_POST['hire_date'] ?: null,
            isset($_POST['active']) ? 't' : 'f', (int)$_POST['id']
        ]);
        flash('Employee updated.');
        header('Location: /modules/employees.php'); exit;
    }

    if ($post_action === 'deactivate') {
        $db->prepare("UPDATE employees SET active=FALSE WHERE id=?")->execute([(int)$_POST['id']]);
        flash('Employee deactivated.', 'warning');
        header('Location: /modules/employees.php'); exit;
    }
}

if ($action === 'list') {
    $employees = $db->query("SELECT * FROM employees ORDER BY full_name")->fetchAll();
    $total_payroll = array_sum(array_column(array_filter($employees, fn($e)=>$e['active']), 'base_salary'));

    render_header('Employees', 'employees');
?>
<div class="page-header">
  <div>
    <div class="page-title">Employees</div>
    <div class="page-sub">Manage factory staff records</div>
  </div>
  <a href="?action=new" class="btn btn-primary">
    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Employee
  </a>
</div>

<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr); margin-bottom:1.4rem;">
  <div class="kpi"><div class="kpi-label">Total Employees</div><div class="kpi-value"><?= count($employees) ?></div></div>
  <div class="kpi green"><div class="kpi-label">Active</div><div class="kpi-value"><?= count(array_filter($employees, fn($e)=>$e['active'])) ?></div></div>
  <div class="kpi amber"><div class="kpi-label">Monthly Base Payroll</div><div class="kpi-value"><?= money($total_payroll) ?></div></div>
</div>

<div class="card">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Emp No.</th><th>Full Name</th><th>Department</th><th>Position</th><th class="num">Base Salary</th><th>Hire Date</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($employees as $emp): ?>
        <tr>
          <td class="fw-bold"><?= e($emp['employee_no']) ?></td>
          <td><?= e($emp['full_name']) ?></td>
          <td><span class="pill pill-blue"><?= e($emp['department'] ?? '—') ?></span></td>
          <td><?= e($emp['position'] ?? '—') ?></td>
          <td class="num"><?= money($emp['base_salary']) ?></td>
          <td><?= $emp['hire_date'] ? date(DATE_FMT, strtotime($emp['hire_date'])) : '—' ?></td>
          <td><?= $emp['active'] ? '<span class="pill pill-green">Active</span>' : '<span class="pill pill-red">Inactive</span>' ?></td>
          <td>
            <div class="flex-gap">
              <a href="?action=edit&id=<?= $emp['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
              <?php if ($emp['active']): ?>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="deactivate"/><input type="hidden" name="id" value="<?= $emp['id'] ?>"/><button class="btn btn-danger btn-sm" data-confirm="Deactivate this employee?">Deactivate</button></form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($employees)): ?>
        <tr><td colspan="8" class="text-muted" style="text-align:center; padding:2rem;">No employees yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); }

if ($action === 'new') {
    render_header('Add Employee', 'employees');
?>
<div class="page-header">
  <div class="page-title">Add Employee</div>
  <a href="/modules/employees.php" class="btn btn-ghost">← Back</a>
</div>
<form method="POST">
  <input type="hidden" name="action" value="save"/>
  <div class="card">
    <div class="form-grid form-grid-3">
      <div class="form-group"><label>Employee No. *</label><input name="employee_no" required placeholder="e.g. EMP-001"/></div>
      <div class="form-group"><label>Full Name *</label><input name="full_name" required/></div>
      <div class="form-group"><label>Department</label>
        <select name="department">
          <option value="Production">Production</option>
          <option value="Maintenance">Maintenance</option>
          <option value="Logistics">Logistics</option>
          <option value="Quality Control">Quality Control</option>
          <option value="Administration">Administration</option>
          <option value="Finance">Finance</option>
          <option value="Finance">IT</option>
        </select>
      </div>
      <div class="form-group"><label>Position</label><input name="position" placeholder="e.g. Machine Operator"/></div>
      <div class="form-group"><label>Base Salary *</label><input type="number" name="base_salary" step="0.01" min="0" required/></div>
      <div class="form-group"><label>Hire Date</label><input type="date" name="hire_date"/></div>
    </div>
    <hr class="div"/>
    <button type="submit" class="btn btn-primary">Save Employee</button>
  </div>
</form>
<?php render_footer(); }

if ($action === 'edit') {
    $id = (int)$_GET['id'];
    $st = $db->prepare("SELECT * FROM employees WHERE id=?"); $st->execute([$id]); $emp = $st->fetch();
    if (!$emp) { flash('Employee not found.', 'error'); header('Location: /modules/employees.php'); exit; }
    render_header('Edit Employee', 'employees');
?>
<div class="page-header">
  <div class="page-title">Edit Employee — <?= e($emp['employee_no']) ?></div>
  <a href="/modules/employees.php" class="btn btn-ghost">← Back</a>
</div>
<form method="POST">
  <input type="hidden" name="action" value="update"/>
  <input type="hidden" name="id" value="<?= $emp['id'] ?>"/>
  <div class="card">
    <div class="form-grid form-grid-3">
      <div class="form-group"><label>Employee No.</label><input value="<?= e($emp['employee_no']) ?>" disabled style="opacity:0.5;"/></div>
      <div class="form-group"><label>Full Name *</label><input name="full_name" required value="<?= e($emp['full_name']) ?>"/></div>
      <div class="form-group"><label>Department</label>
        <select name="department">
          <?php foreach (['Production','Maintenance','Logistics','Quality Control','Administration','Finance'] as $d): ?>
          <option value="<?= $d ?>" <?= $emp['department']==$d?'selected':'' ?>><?= $d ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Position</label><input name="position" value="<?= e($emp['position'] ?? '') ?>"/></div>
      <div class="form-group"><label>Base Salary *</label><input type="number" name="base_salary" step="0.01" min="0" required value="<?= $emp['base_salary'] ?>"/></div>
      <div class="form-group"><label>Hire Date</label><input type="date" name="hire_date" value="<?= e($emp['hire_date'] ?? '') ?>"/></div>
      <div class="form-group">
        <label>Status</label>
        <select name="active">
          <option value="1" <?= $emp['active']?'selected':'' ?>>Active</option>
          <option value="0" <?= !$emp['active']?'selected':'' ?>>Inactive</option>
        </select>
      </div>
    </div>
    <hr class="div"/>
    <button type="submit" class="btn btn-primary">Update Employee</button>
  </div>
</form>
<?php render_footer(); }
