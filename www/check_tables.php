
<?php
$db = getenv('LOCALAPPDATA')
    . DIRECTORY_SEPARATOR
    . 'PhoneElectronicsPOS'
    . DIRECTORY_SEPARATOR
    . 'phone_electronics_pos.sqlite';

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tables = [
    "CREATE TABLE IF NOT EXISTS stock_movements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER,
        product_name TEXT NOT NULL,
        movement_type TEXT NOT NULL,
        quantity INTEGER NOT NULL,
        quantity_before INTEGER,
        quantity_after INTEGER,
        reference TEXT,
        staff_name TEXT NOT NULL DEFAULT 'Administrator',
        notes TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS activity_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        activity_type TEXT NOT NULL,
        description TEXT NOT NULL,
        reference TEXT,
        staff_name TEXT NOT NULL DEFAULT 'Administrator',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS customer_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        amount REAL NOT NULL CHECK (amount > 0),
        payment_method TEXT NOT NULL DEFAULT 'Cash',
        staff_name TEXT NOT NULL DEFAULT 'Administrator',
        notes TEXT,
        paid_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sale_id) REFERENCES sales(id)
    )",

    "CREATE TABLE IF NOT EXISTS sales_returns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        product_id INTEGER,
        product_name TEXT NOT NULL,
        quantity INTEGER NOT NULL CHECK (quantity > 0),
        refund_amount REAL NOT NULL DEFAULT 0,
        reason TEXT,
        staff_name TEXT NOT NULL DEFAULT 'Administrator',
        returned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sale_id) REFERENCES sales(id)
    )"
];

try {
    $pdo->beginTransaction();

    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }

    $pdo->commit();
    echo "SUCCESS: All four business tracking tables were created or already existed.";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo "ERROR: " . $e->getMessage();
    exit(1);
}
