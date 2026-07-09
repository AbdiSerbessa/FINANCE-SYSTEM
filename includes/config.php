<?php
// ============================================================
//  config.php — Database + App Configuration
//  Edit the DB_* constants to match your PostgreSQL setup
// ============================================================

define('DB_HOST', 'db');               // Changed from 'localhost' to 'db'
define('DB_PORT', '5432');
define('DB_NAME', 'factory_finance');   // Changed from 'finance_db' to 'factory_finance'
define('DB_USER', 'postgres');
define('DB_PASS', 'securefinancepass123');          // Change to 'postgres' to match your seed setup
define('APP_NAME',  'Factory Finance');
define('APP_VERSION', '1.0.0');
define('CURRENCY',  'ETB');
define('DATE_FMT',  'd M Y');

// Session config
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_start();

// ── PDO connection (singleton) ─────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            DB_HOST, DB_PORT, DB_NAME
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// ── Auth helpers ───────────────────────────────────────────
function auth(): array {
    return $_SESSION['user'] ?? [];
}

function logged_in(): bool {
    return !empty($_SESSION['user']['id']);
}

function require_login(): void {
    if (!logged_in()) {
        header('Location: /login.php');
        exit;
    }
}

function require_role(string ...$roles): void {
    require_login();
    if (!in_array(auth()['role'] ?? '', $roles, true)) {
        http_response_code(403);
        die('<h2>403 — Access Denied</h2>');
    }
}

// ── Helpers ────────────────────────────────────────────────
function money(float $n): string {
    return CURRENCY . number_format($n, 2);
}

function e(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function flash(string $msg, string $type = 'success'): void {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function get_flash(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function next_entry_no(): string {
    $row = db()->query("SELECT COUNT(*) AS c FROM journal_entries")->fetch();
    return 'JE-' . str_pad((int)$row['c'] + 1, 5, '0', STR_PAD_LEFT);
}

function next_po_no(): string {
    $row = db()->query("SELECT COUNT(*) AS c FROM purchase_orders")->fetch();
    return 'PO-' . str_pad((int)$row['c'] + 1, 5, '0', STR_PAD_LEFT);
}

function next_voucher_no(string $type): string {
    $row = db()->prepare("SELECT COUNT(*) AS c FROM vouchers WHERE voucher_type = ?");
    $row->execute([$type]);
    $c = (int)$row->fetch()['c'] + 1;
    return $type . '-' . str_pad($c, 5, '0', STR_PAD_LEFT);
}
