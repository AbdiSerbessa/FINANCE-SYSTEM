# Factory Finance Management System

A complete double-entry finance management system for factories, built with PHP and PostgreSQL.

## Features
- **Dashboard** — KPIs, revenue/expense charts, trial balance widget, recent activity
- **Journal Entries** — double-entry bookkeeping, draft/post/void workflow
- **Chart of Accounts** — full account hierarchy with types (Asset, Liability, Equity, Revenue, Expense)
- **Inventory** — raw materials, WIP, finished goods, stock movements, reorder alerts
- **Purchase Orders** — create → approve → receive, auto-updates inventory
- **Payroll** — run periods, approve, pay, auto-generates journal entry
- **Employees** & **Suppliers** — master data management
- **Reports** — Trial Balance, Profit & Loss, Balance Sheet, Ledger Card
- **Users** — role-based access (admin / accountant / staff)
- **Auth** — session-based login with bcrypt password hashing

## Setup

### 1. Database
Create a PostgreSQL database and run the schema:
```bash
createdb finance_db
psql finance_db < schema.sql
```
This seeds a default chart of accounts and an admin user.

**Default login:** `admin` / `admin123`
(Change this immediately after first login — see Users module.)

### 2. Configure connection
Edit `includes/config.php` and set your DB credentials:
```php
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'finance_db');
define('DB_USER', 'postgres');
define('DB_PASS', 'your_password_here');
```

### 3. Web server
Point your web server (Apache/Nginx/PHP built-in) document root to this folder.

Quick local test:
```bash
php -S localhost:9002
```
Then visit `http://localhost:9002/login.php`

### 4. Requirements
- PHP 8.0+ with `pdo_pgsql` extension enabled
- PostgreSQL 13+

## Folder Structure
```
/
├── index.php              Dashboard
├── login.php / logout.php Auth
├── about.php
├── schema.sql              Database schema
├── includes/
│   ├── config.php          DB connection + helpers
│   └── layout.php           Shared topbar/sidebar template
├── modules/
│   ├── journal.php          Journal entries
│   ├── accounts.php         Chart of accounts
│   ├── inventory.php        Inventory + stock movements
│   ├── purchase_orders.php  Purchase orders
│   ├── payroll.php          Payroll runs
│   ├── employees.php        Employee records
│   ├── suppliers.php        Supplier records
│   ├── reports.php          Trial balance / P&L / Balance sheet / Ledger card
│   └── users.php            User management (admin only)
└── assets/
    ├── css/app.css           Dark theme stylesheet
    └── js/app.js              Dynamic form behavior
```

## Notes
- All money amounts use `NUMERIC(14,2)` in Postgres to avoid floating point errors.
- Journal entries enforce debit = credit balance before saving.
- Posting a journal entry is separate from saving — entries start as `draft` and must be explicitly `posted` to affect reports/balances.
- Receiving a Purchase Order automatically creates inventory stock-in movements.
- Paying a payroll run automatically creates and posts a journal entry (Dr Payroll Expense / Cr Salaries Payable).
- Update `password_hash()` calls if you change PHP's default hashing algorithm.

## Security Recommendations Before Production
- Use HTTPS only, set `session.cookie_secure = 1`
- Add CSRF tokens to all forms
- Move `config.php` credentials to environment variables
- Add rate limiting to `login.php`
- Restrict `modules/users.php` access at the web-server level too, not just in-app
