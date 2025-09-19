<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$dbPath = __DIR__ . '/data/payments.db';
if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0775, true);
}

// PDO + nette defaults
$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// SQLite pragmas voor robuuster gedrag
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA journal_mode = WAL');   // beter voor gelijktijdige reads/writes
$pdo->exec('PRAGMA synchronous = NORMAL'); // balans tussen veiligheid en snelheid
$pdo->exec('PRAGMA busy_timeout = 5000');  // 5s wachten als db even “busy” is

// Basistabel (nieuwe installaties)
$pdo->exec('CREATE TABLE IF NOT EXISTS payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id    TEXT UNIQUE,          -- onze eigen referentie (bijv. BJB-...); blijft ook zo voor bank
  status        TEXT NOT NULL,        -- pending | paid | cancelled
  option        TEXT NOT NULL,        -- bus | zonder_bus | (eventueel) bus_waitlist
  amount        INTEGER NOT NULL,     -- eurocent
  child_name    TEXT,
  group_class   TEXT,
  phone         TEXT,
  parent_required INTEGER DEFAULT 0,
  parent_name   TEXT,
  parent_phone  TEXT,
  parent_email  TEXT,
  contact_email TEXT,
  emailed       INTEGER DEFAULT 0,    -- of adminmail al verstuurd is
  expires_at    TEXT,                 -- ATOM: einde reservering (voor pending)
  tikkie_url    TEXT,                 -- optioneel: per-inschrijving Tikkie
  created_at    TEXT NOT NULL
)');

// Indexen (no-op als ze al bestaan)
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_payments_status_option_expires ON payments(status, "option", expires_at)');
$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_payments_session_id ON payments(session_id)');

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
$add('tikkie_url', 'TEXT');

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

/** Handige helper: haal één inschrijving op via ref */
function get_payment_by_ref(PDO $pdo, string $ref): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM payments WHERE session_id = ? LIMIT 1');
    $stmt->execute([$ref]);
    $row = $stmt->fetch();
    return $row ?: null;
}