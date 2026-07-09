// app.js — Factory Finance

// ── Auto-dismiss flash messages ───────────────────────────
document.querySelectorAll('.flash').forEach(el => {
  setTimeout(() => el.remove(), 5000);
});

// ── Confirm delete dialogs ────────────────────────────────
document.querySelectorAll('[data-confirm]').forEach(el => {
  el.addEventListener('click', e => {
    if (!confirm(el.dataset.confirm || 'Are you sure?')) e.preventDefault();
  });
});

// ── Journal entry line management ─────────────────────────
const linesBody = document.getElementById('je-lines-body');
const totalDebitEl  = document.getElementById('total-debit');
const totalCreditEl = document.getElementById('total-credit');
let lineCount = 0;

function addLine(accounts = [], suppliers = [], customers = []) {
  lineCount++;
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td>
      <select name="lines[${lineCount}][account_id]" required>
        <option value="">— Select Account —</option>
        ${accounts.map(a => `<option value="${a.id}">${a.code} — ${a.name}</option>`).join('')}
      </select>
    </td>
    <td><input type="number" name="lines[${lineCount}][debit]"  step="0.01" min="0" value="0" oninput="recalcTotals()"/></td>
    <td><input type="number" name="lines[${lineCount}][credit]" step="0.01" min="0" value="0" oninput="recalcTotals()"/></td>
    <td>
      <select name="lines[${lineCount}][supplier_id]">
        <option value="">—</option>
        ${suppliers.map(s => `<option value="${s.id}">${s.name}</option>`).join('')}
      </select>
    </td>
    <td>
      <select name="lines[${lineCount}][customer_id]">
        <option value="">—</option>
        ${customers.map(c => `<option value="${c.id}">${c.name}</option>`).join('')}
      </select>
    </td>
    <td><input type="text"   name="lines[${lineCount}][memo]"   placeholder="Memo…"/></td>
    <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove(); recalcTotals()">✕</button></td>
  `;
  linesBody && linesBody.appendChild(tr);
  recalcTotals();
}

function recalcTotals() {
  let d = 0, c = 0;
  document.querySelectorAll('[name$="[debit]"]').forEach(i  => d += parseFloat(i.value)||0);
  document.querySelectorAll('[name$="[credit]"]').forEach(i => c += parseFloat(i.value)||0);
  if (totalDebitEl)  totalDebitEl.textContent  = d.toFixed(2);
  if (totalCreditEl) totalCreditEl.textContent = c.toFixed(2);
  const bal = document.getElementById('balance-indicator');
  if (bal) {
    const ok = Math.abs(d - c) < 0.01;
    bal.textContent = ok ? '✓ Balanced' : `⚠ Out by ${Math.abs(d-c).toFixed(2)}`;
    bal.className = ok ? 'pill pill-green' : 'pill pill-red';
  }
}

window.addLine = addLine;
window.recalcTotals = recalcTotals;

// ── PO line management ────────────────────────────────────
const poLinesBody = document.getElementById('po-lines-body');
let poLineCount = 0;

function addPoLine(items = []) {
  poLineCount++;
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td>
      <select name="items[${poLineCount}][inventory_id]" required onchange="updatePoTotal()">
        <option value="">— Select Item —</option>
        ${items.map(i => `<option value="${i.id}" data-cost="${i.unit_cost}">${i.item_code} — ${i.item_name}</option>`).join('')}
      </select>
    </td>
    <td><input type="number" name="items[${poLineCount}][qty]"        step="0.001" min="0.001" value="1"    oninput="updatePoTotal()"/></td>
    <td><input type="number" name="items[${poLineCount}][unit_price]" step="0.01"  min="0"     value="0"    oninput="updatePoTotal()"/></td>
    <td class="po-line-total num">0.00</td>
    <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove(); updatePoTotal()">✕</button></td>
  `;
  // auto-fill unit price when item selected
  tr.querySelector('select').addEventListener('change', function() {
    const opt = this.selectedOptions[0];
    const priceInput = tr.querySelector('[name$="[unit_price]"]');
    if (opt && opt.dataset.cost) priceInput.value = parseFloat(opt.dataset.cost).toFixed(2);
    updatePoTotal();
  });
  poLinesBody && poLinesBody.appendChild(tr);
  updatePoTotal();
}

function updatePoTotal() {
  let total = 0;
  document.querySelectorAll('#po-lines-body tr').forEach(tr => {
    const qty   = parseFloat(tr.querySelector('[name$="[qty]"]')?.value)        || 0;
    const price = parseFloat(tr.querySelector('[name$="[unit_price]"]')?.value) || 0;
    const line  = qty * price;
    const td    = tr.querySelector('.po-line-total');
    if (td) td.textContent = line.toFixed(2);
    total += line;
  });
  const el = document.getElementById('po-grand-total');
  if (el) el.textContent = total.toFixed(2);
}

window.addPoLine    = addPoLine;
window.updatePoTotal = updatePoTotal;

// ── Payroll gross → net calculator ───────────────────────
document.addEventListener('input', e => {
  if (!e.target.matches('[name$="[gross_salary]"], [name$="[deductions]"]')) return;
  const tr = e.target.closest('tr');
  if (!tr) return;
  const gross = parseFloat(tr.querySelector('[name$="[gross_salary]"]')?.value) || 0;
  const ded   = parseFloat(tr.querySelector('[name$="[deductions]"]')?.value)   || 0;
  const netEl = tr.querySelector('.net-display');
  if (netEl) netEl.textContent = (gross - ded).toFixed(2);
  // update payroll total
  let gt = 0, nt = 0;
  document.querySelectorAll('[name$="[gross_salary]"]').forEach(i => gt += parseFloat(i.value)||0);
  document.querySelectorAll('.net-display').forEach(el => nt += parseFloat(el.textContent)||0);
  const tg = document.getElementById('payroll-total-gross');
  const tn = document.getElementById('payroll-total-net');
  if (tg) tg.textContent = gt.toFixed(2);
  if (tn) tn.textContent = nt.toFixed(2);
});
