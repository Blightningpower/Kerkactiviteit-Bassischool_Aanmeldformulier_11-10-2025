<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';
$ref = $_GET['ref'] ?? '';
$action = $_GET['action'] ?? 'paid'; // 'paid' of 'cancel'

// Beveiliging
if (!hash_equals((string) $ADMIN_TOKEN, (string) $token)) {
    http_response_code(403);
    exit('Forbidden');
}
if ($ref === '') {
    http_response_code(400);
    exit('Missing ref');
}

// Haal inschrijving op
$stmt = $pdo->prepare('SELECT * FROM payments WHERE session_id = ?');
$stmt->execute([$ref]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    exit('Not found');
}

// Annuleren (admin)
if ($action === 'cancel') {
    $pdo->prepare('UPDATE payments SET status = ?, expires_at = NULL WHERE session_id = ?')
        ->execute(['cancelled', $ref]);
    $msg = 'Reservering geannuleerd.';
    goto respond;
}

// Idempotency & status-checks
if (($row['status'] ?? '') === 'paid') {
    $msg = 'Deze inschrijving was al als betaald gemarkeerd.';
    goto respond;
}
if (($row['status'] ?? '') === 'cancelled') {
    $msg = 'Deze inschrijving is eerder geannuleerd.';
    goto respond;
}

// Bij "paid": respecteer buslimiet (zet op wachtlijst indien vol)
$option = $row['option'] ?? 'zonder_bus';
if ($option === 'bus') {
    $alreadyPaidBus = bus_count_paid($pdo);
    if ($alreadyPaidBus >= $BUS_CAPACITY) {
        $option = 'bus_waitlist';
    }
}

// Update naar betaald
$pdo->prepare('UPDATE payments SET status = ?, option = ?, expires_at = NULL WHERE session_id = ?')
    ->execute(['paid', $option, $ref]);

$msg = ($option === 'bus_waitlist')
    ? 'Betaald gemarkeerd: BUS VOL – geplaatst op wachtlijst.'
    : 'Betaald gemarkeerd.';

// --- Eenvoudige HTML-respons ---
respond:
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Markeer betaald – <?= htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="style.css">

    <link rel="apple-touch-icon" sizes="180x180" href="img/favicon_io/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon_io/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="img/favicon_io/favicon-16x16.png">
</head>

<body>
    <main class="container">
        <div class="card">
            <h2>Admin-actie</h2>
            <p><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></p>
            <p class="muted">Ref: <code><?= htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') ?></code></p>
            <p><a class="btn" href="<?= htmlspecialchars(base_url('index.php'), ENT_QUOTES, 'UTF-8') ?>">Terug naar
                    aanmeldpagina</a></p>
        </div>
    </main>
</body>

</html>