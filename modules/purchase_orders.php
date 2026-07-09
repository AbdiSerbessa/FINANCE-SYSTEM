<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$db     = db();
$action = $_GET['action'] ?? 'list';

$suppliers = $db->query("SELECT id, name FROM suppliers WHERE active=TRUE ORDER BY name")->fetchAll();
$inventory = $db->query("SELECT id, item_code, item_name, unit_cost FROM inventory WHERE active=TRUE ORDER BY item_code")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'save_po') {
        $db->beginTransaction();
        $po_no = next_po_no();
        $db->prepare("INSERT INTO purchase_orders (po_number, supplier_id, po_date, expected_date, status, notes, created_by) VALUES (?,?,?,?,?,?,?)")
           ->execute([$po_no, $_POST['supplier_id'], $_POST['po_date'], $_POST['expected_date'] ?: null, 'draft', $_POST['notes'] ?? null, auth()['id']]);
        $po_id = $db->lastInsertId('purchase_orders_id_seq');
        $total = 0;
        foreach ($_POST['items'] ?? [] as $item) {
            if (!$item['inventory_id']) continue;
            $db->prepare("INSERT INTO purchase_order_items (po_id, inventory_id, qty, unit_price) VALUES (?,?,?,?)")
               ->execute([$po_id, $item['inventory_id'], $item['qty'], $item['unit_price']]);
            $total += $item['qty'] * $item['unit_price'];
        }
        $db->prepare("UPDATE purchase_orders SET total_amount=? WHERE id=?")->execute([$total, $po_id]);
        $db->commit();
        flash("Purchase Order {$po_no} created.");
        header('Location: /modules/purchase_orders.php'); exit;
    }

    if ($post_action === 'approve') {
        $db->prepare("UPDATE purchase_orders SET status='approved' WHERE id=?")->execute([(int)$_POST['id']]);
        flash('PO approved.'); header('Location: /modules/purchase_orders.php'); exit;
    }
    if ($post_action === 'receive') {
        $db->beginTransaction();
        $po_id = (int)$_POST['id'];
        $db->prepare("UPDATE purchase_orders SET status='received' WHERE id=?")->execute([$po_id]);
        // Update inventory
        $items_st = $db->prepare("SELECT poi.*, i.id AS inv_id FROM purchase_order_items poi JOIN inventory i ON i.id = poi.inventory_id WHERE poi.po_id=?");
        $items_st->execute([$po_id]);
        foreach ($items_st->fetchAll() as $pi) {
            $db->prepare("UPDATE inventory SET qty_on_hand = qty_on_hand + ? WHERE id=?")->execute([$pi['qty'], $pi['inv_id']]);
            $db->prepare("INSERT INTO inventory_movements (inventory_id, move_date, move_type, qty, unit_cost, reference, created_by) VALUES (?,CURRENT_DATE,'in',?,?,?,?)")
               ->execute([$pi['inv_id'], $pi['qty'], $pi['unit_price'], 'PO-received', auth()['id']]);
        }
        $db->commit();
        flash('PO marked as received and inventory updated.'); header('Location: /modules/purchase_orders.php'); exit;
    }
    if ($post_action === 'cancel') {
        $db->prepare("UPDATE purchase_orders SET status='cancelled' WHERE id=?")->execute([(int)$_POST['id']]);
        flash('PO cancelled.', 'warning'); header('Location: /modules/purchase_orders.php'); exit;
    }
}

if ($action === 'list') {
    $orders = $db->query("
        SELECT po.*, s.name AS supplier_name
        FROM purchase_orders po
        JOIN suppliers s ON s.id = po.supplier_id
        ORDER BY po.created_at DESC LIMIT 100
    ")->fetchAll();

    render_header('Purchase Orders', 'po');
?>
<div class="page-header">
  <div>
    <div class="page-title">Purchase Orders</div>
    <div class="page-sub">Manage supplier purchase orders</div>
  </div>
  <a href="?action=new" class="btn btn-primary">
    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    New PO
  </a>
</div>
<div class="card">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr><th>PO Number</th><th>Supplier</th><th>PO Date</th><th>Expected</th><th class="num">Total</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $po): ?>
        <tr>
          <td class="fw-bold" style="color:var(--blue-l);"><?= e($po['po_number']) ?></td>
          <td><?= e($po['supplier_name']) ?></td>
          <td><?= date(DATE_FMT, strtotime($po['po_date'])) ?></td>
          <td><?= $po['expected_date'] ? date(DATE_FMT, strtotime($po['expected_date'])) : '—' ?></td>
          <td class="num fw-bold"><?= money($po['total_amount']) ?></td>
          <td><?php $sc=['draft'=>'pill-amber','approved'=>'pill-blue','received'=>'pill-green','cancelled'=>'pill-red']; ?>
            <span class="pill <?= $sc[$po['status']] ?>"><?= ucfirst($po['status']) ?></span></td>
          <td>
            <div class="flex-gap">
              <a href="?action=view&id=<?= $po['id'] ?>" class="btn btn-ghost btn-sm">View</a>
              <?php if ($po['status'] === 'draft'): ?>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="approve"/><input type="hidden" name="id" value="<?= $po['id'] ?>"/><button class="btn btn-success btn-sm">Approve</button></form>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="cancel"/><input type="hidden" name="id" value="<?= $po['id'] ?>"/><button class="btn btn-danger btn-sm" data-confirm="Cancel PO?">Cancel</button></form>
              <?php elseif ($po['status'] === 'approved'): ?>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="receive"/><input type="hidden" name="id" value="<?= $po['id'] ?>"/><button class="btn btn-success btn-sm" data-confirm="Mark as received? This will update inventory.">Mark Received</button></form>
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
    render_header('New Purchase Order', 'po');
?>
<div class="page-header">
  <div class="page-title">New Purchase Order</div>
  <a href="/modules/purchase_orders.php" class="btn btn-ghost">← Back</a>
</div>
<form method="POST">
  <input type="hidden" name="action" value="save_po"/>
  <div class="card mb-6">
    <div class="card-title">PO Details</div>
    <div class="form-grid form-grid-3">
      <div class="form-group"><label>Supplier *</label>
        <select name="supplier_id" required>
          <option value="">— Select Supplier —</option>
          <?php foreach ($suppliers as $s): ?>
          <option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>PO Date *</label><input type="date" name="po_date" value="<?= date('Y-m-d') ?>" required/></div>
      <div class="form-group"><label>Expected Delivery</label><input type="date" name="expected_date"/></div>
      <div class="form-group" style="grid-column:span 3;"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
    </div>
  </div>
  <div class="card mb-6">
    <div class="flex-between mb-4">
      <div class="card-title">Order Items</div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="addPoLine(inventoryItems)">+ Add Item</button>
    </div>
    <div class="tbl-wrap">
      <table class="lines-table">
        <thead><tr><th>Item</th><th style="width:15%">Qty</th><th style="width:15%">Unit Price</th><th class="num" style="width:15%">Total</th><th style="width:50px"></th></tr></thead>
        <tbody id="po-lines-body"></tbody>
      </table>
    </div>
    <div class="totals-row" style="margin-top:8px;">
      <span>Grand Total: <strong id="po-grand-total">0.00</strong></span>
    </div>
  </div>
  <div class="flex-gap">
    <button type="submit" class="btn btn-primary">Save Purchase Order</button>
    <a href="/modules/purchase_orders.php" class="btn btn-ghost">Cancel</a>
  </div>
</form>
<script>
const inventoryItems = <?= json_encode($inventory) ?>;
addPoLine(inventoryItems);
</script>
<?php render_footer(); }

if ($action === 'view') {
    $id = (int)$_GET['id'];
    $st = $db->prepare("SELECT po.*, s.name AS supplier_name, s.email, s.phone, u.full_name AS creator FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id LEFT JOIN users u ON u.id=po.created_by WHERE po.id=?");
    $st->execute([$id]); $po = $st->fetch();
    $ist = $db->prepare("SELECT poi.*, i.item_code, i.item_name, i.unit FROM purchase_order_items poi JOIN inventory i ON i.id=poi.inventory_id WHERE poi.po_id=?");
    $ist->execute([$id]); $items = $ist->fetchAll();
    render_header('PO ' . $po['po_number'], 'po');
?>
<div class="page-header">
  <div>
    <div class="page-title"><?= e($po['po_number']) ?></div>
    <div class="page-sub">Supplier: <?= e($po['supplier_name']) ?></div>
  </div>
  <div class="flex-gap">
    <?php $sc=['draft'=>'pill-amber','approved'=>'pill-blue','received'=>'pill-green','cancelled'=>'pill-red']; ?>
    <span class="pill <?= $sc[$po['status']] ?>" style="font-size:13px;"><?= ucfirst($po['status']) ?></span>
    <?php if ($po['status']==='draft'): ?>
    <form method="POST"><input type="hidden" name="action" value="approve"/><input type="hidden" name="id" value="<?= $id ?>"/><button class="btn btn-success">Approve</button></form>
    <?php elseif ($po['status']==='approved'): ?>
    <form method="POST"><input type="hidden" name="action" value="receive"/><input type="hidden" name="id" value="<?= $id ?>"/><button class="btn btn-success" data-confirm="Mark as received? Inventory will be updated.">Mark Received</button></form>
    <?php endif; ?>
    <a href="/modules/purchase_orders.php" class="btn btn-ghost">← Back</a>
  </div>
</div>
<div class="grid-2 mb-6">
  <div class="card">
    <div class="card-title">Order Info</div>
    <table style="width:100%; font-size:13px;">
      <tr><td class="text-muted" style="padding:5px 0; width:40%">PO Number</td><td class="fw-bold"><?= e($po['po_number']) ?></td></tr>
      <tr><td class="text-muted" style="padding:5px 0">Date</td><td><?= date(DATE_FMT, strtotime($po['po_date'])) ?></td></tr>
      <tr><td class="text-muted" style="padding:5px 0">Expected</td><td><?= $po['expected_date'] ? date(DATE_FMT, strtotime($po['expected_date'])) : '—' ?></td></tr>
      <tr><td class="text-muted" style="padding:5px 0">Created By</td><td><?= e($po['creator'] ?? '—') ?></td></tr>
      <?php if ($po['notes']): ?><tr><td class="text-muted" style="padding:5px 0">Notes</td><td><?= e($po['notes']) ?></td></tr><?php endif; ?>
    </table>
  </div>
  <div class="card">
    <div class="card-title">Supplier</div>
    <table style="width:100%; font-size:13px;">
      <tr><td class="text-muted" style="padding:5px 0; width:40%">Name</td><td class="fw-bold"><?= e($po['supplier_name']) ?></td></tr>
      <tr><td class="text-muted" style="padding:5px 0">Email</td><td><?= e($po['email'] ?? '—') ?></td></tr>
      <tr><td class="text-muted" style="padding:5px 0">Phone</td><td><?= e($po['phone'] ?? '—') ?></td></tr>
    </table>
  </div>
</div>
<div class="card">
  <div class="card-title">Order Items</div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Code</th><th>Item</th><th>Unit</th><th class="num">Qty</th><th class="num">Unit Price</th><th class="num">Total</th></tr></thead>
      <tbody>
        <?php foreach ($items as $item): ?>
        <tr>
          <td><?= e($item['item_code']) ?></td>
          <td><?= e($item['item_name']) ?></td>
          <td><?= e($item['unit'] ?? '—') ?></td>
          <td class="num"><?= number_format($item['qty'], 3) ?></td>
          <td class="num"><?= money($item['unit_price']) ?></td>
          <td class="num fw-bold"><?= money($item['total_price']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="border-top:1px solid var(--border);">
          <td colspan="5" class="fw-bold text-right">Grand Total</td>
          <td class="num fw-bold" style="font-size:16px;"><?= money($po['total_amount']) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); }
