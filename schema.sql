-- ============================================================
--  FACTORY FINANCE MANAGEMENT SYSTEM — PostgreSQL Schema
-- ============================================================

-- Drop existing tables (order matters for FK constraints)
DROP TABLE IF EXISTS payroll_items CASCADE;
DROP TABLE IF EXISTS payroll CASCADE;
DROP TABLE IF EXISTS purchase_order_items CASCADE;
DROP TABLE IF EXISTS purchase_orders CASCADE;
DROP TABLE IF EXISTS inventory_movements CASCADE;
DROP TABLE IF EXISTS inventory CASCADE;
DROP TABLE IF EXISTS journal_lines CASCADE;
DROP TABLE IF EXISTS journal_entries CASCADE;
DROP TABLE IF EXISTS accounts CASCADE;
DROP TABLE IF EXISTS account_types CASCADE;
DROP TABLE IF EXISTS suppliers CASCADE;
DROP TABLE IF EXISTS employees CASCADE;
DROP TABLE IF EXISTS users CASCADE;

-- ── Users ──────────────────────────────────────────────────
CREATE TABLE users (
  id          SERIAL PRIMARY KEY,
  username    VARCHAR(60)  NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,  -- bcrypt hash
  full_name   VARCHAR(120) NOT NULL,
  role        VARCHAR(30)  NOT NULL DEFAULT 'staff', -- admin | accountant | staff
  active      BOOLEAN      NOT NULL DEFAULT TRUE,
  created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- ── Account Types ──────────────────────────────────────────
CREATE TABLE account_types (
  id        SERIAL PRIMARY KEY,
  name      VARCHAR(60) NOT NULL UNIQUE,   -- Asset, Liability, Equity, Revenue, Expense
  normal_balance VARCHAR(6) NOT NULL        -- debit | credit
);

INSERT INTO account_types (name, normal_balance) VALUES
  ('Asset',     'debit'),
  ('Liability', 'credit'),
  ('Equity',    'credit'),
  ('Revenue',   'credit'),
  ('Expense',   'debit');

-- ── Chart of Accounts ──────────────────────────────────────
CREATE TABLE accounts (
  id              SERIAL PRIMARY KEY,
  code            VARCHAR(20)  NOT NULL UNIQUE,
  name            VARCHAR(120) NOT NULL,
  account_type_id INTEGER      NOT NULL REFERENCES account_types(id),
  parent_id       INTEGER      REFERENCES accounts(id),
  description     TEXT,
  active          BOOLEAN      NOT NULL DEFAULT TRUE,
  created_at      TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- Seed default chart of accounts
INSERT INTO accounts (code, name, account_type_id) VALUES
  ('1000', 'Cash on Hand',            1),
  ('1010', 'Bank Account',            1),
  ('1100', 'Accounts Receivable',     1),
  ('1200', 'Raw Materials Inventory', 1),
  ('1210', 'WIP Inventory',           1),
  ('1220', 'Finished Goods',          1),
  ('1500', 'Machinery & Equipment',   1),
  ('1510', 'Accumulated Depreciation',2),
  ('2000', 'Accounts Payable',        2),
  ('2100', 'Salaries Payable',        2),
  ('2200', 'Tax Payable',             2),
  ('3000', 'Owner Equity',            3),
  ('3100', 'Retained Earnings',       3),
  ('4000', 'Sales Revenue',           4),
  ('4100', 'Service Revenue',         4),
  ('5000', 'Cost of Goods Sold',      5),
  ('5100', 'Raw Material Cost',       5),
  ('5200', 'Direct Labor Cost',       5),
  ('5300', 'Manufacturing Overhead',  5),
  ('6000', 'Payroll Expense',         5),
  ('6100', 'Energy / Utilities',      5),
  ('6200', 'Maintenance Expense',     5),
  ('6300', 'Logistics / Freight',     5),
  ('6400', 'Administrative Expense',  5),
  ('6500', 'Depreciation Expense',    5);

-- ── Suppliers ──────────────────────────────────────────────
CREATE TABLE suppliers (
  id          SERIAL PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  contact     VARCHAR(100),
  phone       VARCHAR(30),
  email       VARCHAR(100),
  address     TEXT,
  active      BOOLEAN   NOT NULL DEFAULT TRUE,
  created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);

-- ── Employees ──────────────────────────────────────────────
CREATE TABLE employees (
  id            SERIAL PRIMARY KEY,
  employee_no   VARCHAR(20)  NOT NULL UNIQUE,
  full_name     VARCHAR(120) NOT NULL,
  department    VARCHAR(60),
  position      VARCHAR(80),
  base_salary   NUMERIC(14,2) NOT NULL DEFAULT 0,
  hire_date     DATE,
  active        BOOLEAN   NOT NULL DEFAULT TRUE,
  created_at    TIMESTAMP NOT NULL DEFAULT NOW()
);

-- ── Journal Entries ────────────────────────────────────────
CREATE TABLE journal_entries (
  id           SERIAL PRIMARY KEY,
  entry_no     VARCHAR(20)  NOT NULL UNIQUE,
  entry_date   DATE         NOT NULL,
  description  TEXT         NOT NULL,
  reference    VARCHAR(80),
  type         VARCHAR(30)  NOT NULL DEFAULT 'manual', -- manual | payroll | purchase | inventory
  status       VARCHAR(20)  NOT NULL DEFAULT 'draft',  -- draft | posted | void
  created_by   INTEGER      REFERENCES users(id),
  approved_by  INTEGER      REFERENCES users(id),
  created_at   TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- ── Journal Lines ──────────────────────────────────────────
CREATE TABLE journal_lines (
  id          SERIAL PRIMARY KEY,
  entry_id    INTEGER        NOT NULL REFERENCES journal_entries(id) ON DELETE CASCADE,
  account_id  INTEGER        NOT NULL REFERENCES accounts(id),
  debit       NUMERIC(14,2)  NOT NULL DEFAULT 0,
  credit      NUMERIC(14,2)  NOT NULL DEFAULT 0,
  memo        TEXT
);

-- ── Inventory ──────────────────────────────────────────────
CREATE TABLE inventory (
  id            SERIAL PRIMARY KEY,
  item_code     VARCHAR(30)   NOT NULL UNIQUE,
  item_name     VARCHAR(120)  NOT NULL,
  category      VARCHAR(60),   -- raw_material | wip | finished_good | supply
  unit          VARCHAR(20),
  unit_cost     NUMERIC(14,2) NOT NULL DEFAULT 0,
  qty_on_hand   NUMERIC(14,3) NOT NULL DEFAULT 0,
  reorder_level NUMERIC(14,3) NOT NULL DEFAULT 0,
  account_id    INTEGER       REFERENCES accounts(id),
  active        BOOLEAN       NOT NULL DEFAULT TRUE,
  created_at    TIMESTAMP     NOT NULL DEFAULT NOW()
);

-- ── Inventory Movements ────────────────────────────────────
CREATE TABLE inventory_movements (
  id           SERIAL PRIMARY KEY,
  inventory_id INTEGER        NOT NULL REFERENCES inventory(id),
  move_date    DATE           NOT NULL,
  move_type    VARCHAR(20)    NOT NULL, -- in | out | adjust
  qty          NUMERIC(14,3)  NOT NULL,
  unit_cost    NUMERIC(14,2)  NOT NULL DEFAULT 0,
  reference    VARCHAR(80),
  notes        TEXT,
  created_by   INTEGER        REFERENCES users(id),
  created_at   TIMESTAMP      NOT NULL DEFAULT NOW()
);

-- ── Purchase Orders ────────────────────────────────────────
CREATE TABLE purchase_orders (
  id           SERIAL PRIMARY KEY,
  po_number    VARCHAR(20)   NOT NULL UNIQUE,
  supplier_id  INTEGER       NOT NULL REFERENCES suppliers(id),
  po_date      DATE          NOT NULL,
  expected_date DATE,
  status       VARCHAR(20)   NOT NULL DEFAULT 'draft', -- draft | approved | received | cancelled
  total_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
  notes        TEXT,
  created_by   INTEGER       REFERENCES users(id),
  created_at   TIMESTAMP     NOT NULL DEFAULT NOW()
);

-- ── Purchase Order Items ───────────────────────────────────
CREATE TABLE purchase_order_items (
  id            SERIAL PRIMARY KEY,
  po_id         INTEGER        NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
  inventory_id  INTEGER        NOT NULL REFERENCES inventory(id),
  qty           NUMERIC(14,3)  NOT NULL,
  unit_price    NUMERIC(14,2)  NOT NULL,
  total_price   NUMERIC(14,2)  GENERATED ALWAYS AS (qty * unit_price) STORED
);

-- ── Payroll ────────────────────────────────────────────────
CREATE TABLE payroll (
  id           SERIAL PRIMARY KEY,
  period_label VARCHAR(30)   NOT NULL,  -- e.g. "May 2026"
  period_start DATE          NOT NULL,
  period_end   DATE          NOT NULL,
  status       VARCHAR(20)   NOT NULL DEFAULT 'draft', -- draft | approved | paid
  total_gross  NUMERIC(14,2) NOT NULL DEFAULT 0,
  total_net    NUMERIC(14,2) NOT NULL DEFAULT 0,
  created_by   INTEGER       REFERENCES users(id),
  created_at   TIMESTAMP     NOT NULL DEFAULT NOW()
);

-- ── Payroll Items ──────────────────────────────────────────
CREATE TABLE payroll_items (
  id            SERIAL PRIMARY KEY,
  payroll_id    INTEGER        NOT NULL REFERENCES payroll(id) ON DELETE CASCADE,
  employee_id   INTEGER        NOT NULL REFERENCES employees(id),
  gross_salary  NUMERIC(14,2)  NOT NULL DEFAULT 0,
  deductions    NUMERIC(14,2)  NOT NULL DEFAULT 0,
  net_salary    NUMERIC(14,2)  NOT NULL DEFAULT 0,
  notes         TEXT
);

-- ── Customers (for AR subsidiary ledger) ───────────────────
CREATE TABLE customers (
  id          SERIAL PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  contact     VARCHAR(100),
  phone       VARCHAR(30),
  email       VARCHAR(100),
  address     TEXT,
  active      BOOLEAN   NOT NULL DEFAULT TRUE,
  created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);

-- ── Add party reference to journal_lines (subsidiary ledger) ─
-- A journal line posted to AP/AR can optionally be tagged to a
-- specific supplier or customer, enabling subsidiary ledgers.
ALTER TABLE journal_lines ADD COLUMN supplier_id INTEGER REFERENCES suppliers(id);
ALTER TABLE journal_lines ADD COLUMN customer_id INTEGER REFERENCES customers(id);

-- ── Vouchers (Payment Voucher / Receipt Voucher) ───────────
-- A voucher is a structured cash-out or cash-in document.
-- It can have MULTIPLE lines hitting different accounts/parties
-- at once (e.g. one payment split across 3 supplier invoices,
-- or paying an AP invoice partly from Bank + partly from Cash).
CREATE TABLE vouchers (
  id            SERIAL PRIMARY KEY,
  voucher_no    VARCHAR(20)   NOT NULL UNIQUE,
  voucher_type  VARCHAR(10)   NOT NULL,        -- PV | RV
  voucher_date  DATE          NOT NULL,
  paid_through  INTEGER       NOT NULL REFERENCES accounts(id), -- Cash/Bank account
  payee_payer   VARCHAR(120),                  -- free-text name (or derived from party lines)
  total_amount  NUMERIC(14,2) NOT NULL DEFAULT 0,
  description   TEXT,
  status        VARCHAR(20)   NOT NULL DEFAULT 'draft', -- draft | posted | void
  journal_entry_id INTEGER    REFERENCES journal_entries(id),
  created_by    INTEGER       REFERENCES users(id),
  approved_by   INTEGER       REFERENCES users(id),
  created_at    TIMESTAMP     NOT NULL DEFAULT NOW()
);

-- ── Voucher Lines ───────────────────────────────────────────
-- Multiple lines = split across accounts/suppliers/customers.
CREATE TABLE voucher_lines (
  id            SERIAL PRIMARY KEY,
  voucher_id    INTEGER        NOT NULL REFERENCES vouchers(id) ON DELETE CASCADE,
  account_id    INTEGER        NOT NULL REFERENCES accounts(id),
  supplier_id   INTEGER        REFERENCES suppliers(id),
  customer_id   INTEGER        REFERENCES customers(id),
  amount        NUMERIC(14,2)  NOT NULL,
  memo          TEXT
);

-- ── Indexes ────────────────────────────────────────────────
CREATE INDEX idx_journal_lines_entry    ON journal_lines(entry_id);
CREATE INDEX idx_journal_lines_account  ON journal_lines(account_id);
CREATE INDEX idx_journal_lines_supplier ON journal_lines(supplier_id);
CREATE INDEX idx_journal_lines_customer ON journal_lines(customer_id);
CREATE INDEX idx_journal_entries_date   ON journal_entries(entry_date);
CREATE INDEX idx_inv_movements_inv      ON inventory_movements(inventory_id);
CREATE INDEX idx_po_supplier            ON purchase_orders(supplier_id);
CREATE INDEX idx_payroll_items_payroll  ON payroll_items(payroll_id);
CREATE INDEX idx_voucher_lines_voucher  ON voucher_lines(voucher_id);
CREATE INDEX idx_voucher_lines_supplier ON voucher_lines(supplier_id);
CREATE INDEX idx_voucher_lines_customer ON voucher_lines(customer_id);

-- ── Default Admin User (password: admin123) ────────────────
INSERT INTO users (username, password, full_name, role) VALUES
  ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin');
