<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$dbPath = __DIR__ . '/data/payments.db';
if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0775, true);
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Basistabel (nieuwe installaties)
$pdo->exec('CREATE TABLE IF NOT EXISTS payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id TEXT UNIQUE,          -- onze eigen referentie (bijv. BJB-...); blijft ook zo voor bank
  status TEXT NOT NULL,            -- pending | paid | cancelled
  option TEXT NOT NULL,            -- bus | zonder_bus | (eventueel) bus_waitlist
  amount INTEGER NOT NULL,         -- eurocent
  child_name TEXT,
  group_class TEXT,
  phone TEXT,
  parent_required INTEGER DEFAULT 0,
  parent_name TEXT,
  parent_phone TEXT,
  parent_email TEXT,
  contact_email TEXT,
  emailed INTEGER DEFAULT 0,       -- of adminmail al verstuurd is
  expires_at TEXT,                 -- ATOM: einde reservering (voor pending)
  created_at TEXT NOT NULL
)');

// Backwards compatible: voeg ontbrekende kolommen toe zonder data te verliezen
$cols = array_column($pdo->query("PRAGMA table_info(payments)")->fetchAll(), 'name');
$add = function (string $name, string $type) use (&$cols, $pdo) {
    if (!in_array($name, $cols, true)) {
        $pdo->exec("ALTER TABLE payments ADD COLUMN $name $type");
        $cols[] = $name;
    }
};

$add('parent_required', 'INTEGER DEFAULT 0');
$add('parent_name', 'TEXT');
$add('parent_phone', 'TEXT');
$add('parent_email', 'TEXT');
$add('contact_email', 'TEXT');
$add('emailed', 'INTEGER DEFAULT 0');
$add('expires_at', 'TEXT');

/**
 * Aantal actieve busplekken:
 * - telt 'bus' aanmeldingen die al betaald zijn OF pending zijn maar nog niet verlopen
 * - zo voorkom je overboeking terwijl iemand nog aan het overmaken is
 */
function active_bus_count(PDO $pdo): int
{
    $nowAtom = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS c
        FROM payments
        WHERE option = 'bus'
          AND status IN ('paid','pending')
          AND (expires_at IS NULL OR expires_at > ?)
    ");
    $stmt->execute([$nowAtom]);
    $row = $stmt->fetch();
    return (int) ($row['c'] ?? 0);
}

/** Betaalde busplekken (gebruikt o.a. bij markeren betaald) */
function bus_count_paid(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COUNT(*) AS c FROM payments WHERE status='paid' AND option='bus'");
    $row = $stmt->fetch();
    return (int) ($row['c'] ?? 0);
}