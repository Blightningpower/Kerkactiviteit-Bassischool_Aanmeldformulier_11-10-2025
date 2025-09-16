<?php
// db.php – eenvoudige SQLite opslag


require_once __DIR__ . '/config.php';


$dbPath = __DIR__ . '/data/payments.db';
if (!is_dir(__DIR__ . '/data')) {
mkdir(__DIR__ . '/data', 0775, true);
}


$pdo = new PDO('sqlite:' . $dbPath, null, null, [
PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);


// Tabellen
$pdo->exec('CREATE TABLE IF NOT EXISTS payments (
id INTEGER PRIMARY KEY AUTOINCREMENT,
session_id TEXT UNIQUE,
status TEXT NOT NULL,
option TEXT NOT NULL, -- bus|zonder_bus
amount INTEGER NOT NULL,
child_name TEXT,
group_class TEXT,
phone TEXT,
created_at TEXT NOT NULL
)');


function bus_count_paid(PDO $pdo): int {
$stmt = $pdo->query("SELECT COUNT(*) AS c FROM payments WHERE status='paid' AND option='bus'");
$row = $stmt->fetch();
return (int)($row['c'] ?? 0);
}