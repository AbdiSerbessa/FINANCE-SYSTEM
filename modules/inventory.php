<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$db     = db();
$action = $_GET['action'] ?? 'list';

// ── POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'save_item') {
        $stmt = $db->prepare("
            INSERT INTO inventory (item_code, item_name, category, unit, unit_cost, qty_on_hand, reorder_level)
            VALUES (?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $_POST['item_code'], $_POST['item_name'], $_POST['category'],
            $_POST['unit'], $_POST['unit_cost'], $_POST['qty_on_hand'], $_POST['reorder_level']
        ]);
        flash('Item added successfully.');
        header('Location: /modules/inventory.php'); exit;
    }

    if ($post_action === 'update_item') {
        $stmt = $db->prepare("
            UPDATE inventory SET item_name=?, category=?, unit=?, unit_cost=?, reorder_level=? WHERE id=?
        ");
        $stmt->execute([$_POST['item_name'],$_POST['category'],$_POST['unit'],$_POST['unit_cost'],$_POST['reorder_level'],(int)$_POST['id']]);
        flash('Item updated.');
        header('Location: /modules/inventory.php'); exit;
    }

    if ($post_action === 'movement') {
        $inv_id   = (int)$_POST['inventory_id'];
        $move_type = $_POST['move_type'];
        $qty       = (float)$_POST['qty'];
        $db->beginTransaction();
        $db->prepare("INSERT INTO inventory_movements (inventory_id, move_date, move_type, qty, unit_cost, reference, notes, created_by) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$inv_id, $_POST['move_date'], $move_type, $qty, $_POST['unit_cost'] ?? 0, $_POST['reference'] ?? null, $_POST['notes'] ?? null, auth()['id']]);
        $delta = $move_type === 'out' ? -$qty : $qty;
        $db->prepare("UPDATE inventory SET qty_on_hand = qty_on_hand + ? WHERE id=?")->execute([$delta, $inv_id]);
        $db->commit();
        flash('Stock movement recorded.');
        header('Location: /modules/inventory.php'); exit;
    }
}

$items = $db->query("
    SELECT i.*, at.name AS account_name
    FROM inventory i
    LEFT JOIN accounts at ON at.id = i.account_id
    WHERE i.active = TRUE
    ORDER BY i.item_code
")->fetchAll();

$accounts = $db->query("SELECT id, code, name FROM accounts WHERE active=TRUE ORDER BY code")->fetchAll();

if ($action === 'list') {
    $total_value = array_sum(array_map(fn($r) => $r['qty_on_hand'] * $r['unit_cost'], $items));
    render_header('Inventory', 'inventory');
?>
<div class="page-header">
  <div>
    <div class="page-title">Inventory</div>
    <div class="page-sub">Raw materials, WIP &amp; finished goods</div>
  </div>
  <div class="flex-gap">
    <a href="?action=movement" class="btn btn-ghost">Record Movement</a>
    <a href="?action=new" class="btn btn-primary">
      <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Item
    </a>
  </div>
</div>

<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr); margin-bottom:1.4rem;">
  <div class="kpi">
    <div class="kpi-label">Total Items</div>
    <div class="kpi-value"><?= count($items) ?></div>
  </div>
  <div class="kpi green">
    <div class="kpi-label">Total Inventory Value</div>
    <div class="kpi-value"><?= money($total_value) ?></div>
  </div>
  <div class="kpi red">
    <div class="kpi-label">Low Stock Alerts</div>
    <div class="kpi-value"><?= count(array_filter($items, fn($r) => $r['qty_on_hand'] <= $r['reorder_level'])) ?></div>
  </div>
</div>

<div class="card">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr><th>Code</th><th>Item Name</th><th>Category</th><th>Unit</th><th class="num">Qty on Hand</th><th class="num">Unit Cost</th><th class="num">Total Value</th><th>Reorder At</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($items as $item): ?>
        <?php $low = $item['qty_on_hand'] <= $item['reorder_level']; ?>
        <tr>
          <td class="fw-bold"><?= e($item['item_code']) ?></td>
          <td><?= e($item['item_name']) ?></td>
          <td><span class="pill pill-blue"><?= e($item['category'] ?? '—') ?></span></td>
          <td><?= e($item['unit'] ?? '—') ?></td>
          <td class="num <?= $low ? 'td-debit' : '' ?>"><?= number_format($item['qty_on_hand'], 2) ?></td>
          <td class="num"><?= money($item['unit_cost']) ?></td>
          <td class="num fw-bold"><?= money($item['qty_on_hand'] * $item['unit_cost']) ?></td>
          <td><?= number_format($item['reorder_level'], 2) ?></td>
          <td><?= $low ? '<span class="pill pill-red">Low Stock</span>' : '<span class="pill pill-green">OK</span>' ?></td>
          <td>
            <div class="flex-gap">
              <a href="?action=edit&id=<?= $item['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
              <a href="?action=history&id=<?= $item['id'] ?>" class="btn btn-ghost btn-sm">History</a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); }

// ── New item form ──────────────────────────────────────────
if ($action === 'new') {
    render_header('Add Inventory Item', 'inventory');
?>
<div class="page-header">
  <div class="page-title">Add Inventory Item</div>
  <a href="/modules/inventory.php" class="btn btn-ghost">← Back</a>
</div>
<form method="POST">
  <input type="hidden" name="action" value="save_item"/>
  <div class="card">
    <div class="form-grid form-grid-3">
      <div class="form-group"><label>Item Code *</label><input name="item_code" required placeholder="e.g. RM-001"/></div>
      <div class="form-group"><label>Item Name *</label><input name="item_name" required placeholder="Steel Rods"/></div>
      <div class="form-group"><label>Category</label>
        <select name="category">
          <option value="raw_material">Raw Material</option>
          <option value="wip">Work in Progress</option>
          <option value="finished_good">Finished Good</option>
          <option value="supply">Supply</option>
        </select>
      </div>
      <div class="form-group"><label>Unit</label><input name="unit" placeholder="kg, pcs, ltr…"/></div>
      <div class="form-group"><label>Unit Cost</label><input type="number" name="unit_cost" step="0.01" min="0" value="0"/></div>
      <div class="form-group"><label>Opening Qty</label><input type="number" name="qty_on_hand" step="0.001" min="0" value="0"/></div>
      <div class="form-group"><label>Reorder Level</label><input type="number" name="reorder_level" step="0.001" min="0" value="0"/></div>
    </div>
    <hr class="div"/>
    <button type="submit" class="btn btn-primary">Save Item</button>
  </div>
</form>
<?php render_footer(); }

// ── Record movement ────────────────────────────────────────
if ($action === 'movement') {
    render_header('Record Stock Movement', 'inventory');
?>
<div class="page-header">
  <div class="page-title">Record Stock Movement</div>
  <a href="/modules/inventory.php" class="btn btn-ghost">← Back</a>
</div>
<form method="POST">
  <input type="hidden" name="action" value="movement"/>
  <div class="card">
    <div class="form-grid form-grid-3">
      <div class="form-group"><label>Item *</label>
        <select name="inventory_id" required>
          <option value="">— Select Item —</option>
          <?php foreach ($items as $i): ?>
          <option value="<?= $i['id'] ?>"><?= e($i['item_code']) ?> — <?= e($i['item_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Date *</label><input type="date" name="move_date" value="<?= date('Y-m-d') ?>" required/></div>
      <div class="form-group"><label>Movement Type *</label>
        <select name="move_type">
          <option value="in">Stock In</option>
          <option value="out">Stock Out</option>
          <option value="adjust">Adjustment</option>
        </select>
      </div>
      <div class="form-group"><label>Quantity *</label><input type="number" name="qty" step="0.001" min="0.001" required/></div>
      <div class="form-group"><label>Unit Cost</label><input type="number" name="unit_cost" step="0.01" min="0" value="0"/></div>
      <div class="form-group"><label>Reference</label><input name="reference" placeholder="PO number, etc."/></div>
      <div class="form-group" style="grid-column:span 3;"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
    </div>
    <hr class="div"/>
    <button type="submit" class="btn btn-success">Record Movement</button>
  </div>
</form>
<?php render_footer(); }

// ── Movement history ───────────────────────────────────────
if ($action === 'history') {
    $inv_id = (int)$_GET['id'];
    $item   = $db->prepare("SELECT * FROM inventory WHERE id=?")->execute([$inv_id]) ? null : null;
    $st = $db->prepare("SELECT * FROM inventory WHERE id=?"); $st->execute([$inv_id]); $item = $st->fetch();
    $hist_st = $db->prepare("
        SELECT im.*, u.full_name AS by_user
        FROM inventory_movements im
        LEFT JOIN users u ON u.id = im.created_by
        WHERE im.inventory_id = ?
        ORDER BY im.move_date DESC, im.id DESC
    ");
    $hist_st->execute([$inv_id]);
    $history = $hist_st->fetchAll();
    render_header('Movement History', 'inventory');
?>
<div class="page-header">
  <div>
    <div class="page-title"><?= e($item['item_name'] ?? '') ?></div>
    <div class="page-sub">Code: <?= e($item['item_code'] ?? '') ?> | On Hand: <?= number_format($item['qty_on_hand'] ?? 0, 3) ?> <?= e($item['unit'] ?? '') ?></div>
  </div>
  <a href="/modules/inventory.php" class="btn btn-ghost">← Back</a>
</div>
<div class="card">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Date</th><th>Type</th><th class="num">Qty</th><th class="num">Unit Cost</th><th>Reference</th><th>Notes</th><th>By</th></tr></thead>
      <tbody>
        <?php foreach ($history as $h): ?>
        <tr>
          <td><?= date(DATE_FMT, strtotime($h['move_date'])) ?></td>
          <td><?php $mc=['in'=>'pill-green','out'=>'pill-red','adjust'=>'pill-amber']; ?>
            <span class="pill <?= $mc[$h['move_type']] ?? 'pill-blue' ?>"><?= ucfirst($h['move_type']) ?></span></td>
          <td class="num <?= $h['move_type']==='out'?'td-debit':'td-credit' ?>"><?= ($h['move_type']==='out'?'−':'+') . number_format($h['qty'],3) ?></td>
          <td class="num"><?= money($h['unit_cost']) ?></td>
          <td><?= e($h['reference'] ?? '—') ?></td>
          <td><?= e($h['notes'] ?? '—') ?></td>
          <td><?= e($h['by_user'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); }
