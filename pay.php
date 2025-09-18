<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Endroid\QrCode\Encoding\Encoding;

// Ref ophalen
$ref = $_GET['ref'] ?? '';
$stmt = $pdo->prepare('SELECT * FROM payments WHERE session_id = ?');
$stmt->execute([$ref]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    exit('Onbekende referentie.');
}

$amountEur = ((int) $row['amount']) / 100.0;
$option = (string) ($row['option'] ?? 'zonder_bus');

// Omschrijving en RF opbouwen
$refClean = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $ref));
$remittance = 'BJB-' . substr($refClean, 0, 20); // altijd letters + kort
$rf = make_rf_creditor_reference($refClean);

// Beide payloads maken
$payload_unstructured = make_epc_payload_unstructured(
    $RECEIVER_NAME,
    $RECEIVER_IBAN,
    $amountEur,
    $remittance,
    $RECEIVER_BIC
);
$payload_structured = make_epc_payload_structured(
    $RECEIVER_NAME,
    $RECEIVER_IBAN,
    $amountEur,
    $rf,
    $RECEIVER_BIC
);

// Kies modus via query
$mode = (($_GET['mode'] ?? '') === 'rf') ? 'rf' : 'free';
$payload = ($mode === 'rf') ? $payload_structured : $payload_unstructured;

// QR (SVG, geen GD vereist)
$qr = Builder::create()
    ->writer(new SvgWriter())
    ->data($payload)
    ->encoding(new Encoding('UTF-8'))
    ->size(320)
    ->build();
$dataUri = $qr->getDataUri();

// Betaald?
$isPaid = isset($row['status']) && $row['status'] === 'paid';

// Optionele vervaldatum tonen (als je die kolom hebt)
$expiresTxt = '';
if (!empty($row['expires_at'] ?? '')) {
    try {
        $expiresTxt = (new DateTimeImmutable($row['expires_at']))->format('d-m-Y H:i');
    } catch (\Throwable $e) {
    }
}
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Betaalinstructies – <?= htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars(base_url('style.css'), ENT_QUOTES, 'UTF-8') ?>">

    <link rel="apple-touch-icon" sizes="180x180" href="img/favicon_io/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon_io/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="img/favicon_io/favicon-16x16.png">
</head>

<body>
    <main class="container">
        <div class="card">
            <h2>Betaalinstructies</h2>

            <?php if ($isPaid): ?>
                <p class="tag tag-amber">We hebben je betaling al geregistreerd. Je hoeft niets meer te doen.</p>
            <?php else: ?>
                <p><strong>Stap 1.</strong> Open je bankapp en scan de QR hieronder (werkt met de meeste NL/BE banken).
                    Lukt dat niet? Probeer de <em>andere</em> QR-modus of kopieer de gegevens onderaan.</p>

                <p>
                    <a class="btn" style="background:#3b82f6"
                        href="<?= htmlspecialchars(base_url('pay.php?ref=' . $ref . '&mode=free')) ?>">
                        QR met omschrijving
                    </a>
                    <a class="btn" style="background:#6366f1"
                        href="<?= htmlspecialchars(base_url('pay.php?ref=' . $ref . '&mode=rf')) ?>">
                        QR met betalingskenmerk (RF)
                    </a>
                </p>

                <p><img src="<?= $dataUri ?>" alt="SEPA/EPC QR" /></p>

                <ul>
                    <li><strong>Bedrag:</strong> €<?= number_format($amountEur, 2, ',', '.') ?></li>
                    <li><strong>IBAN ontvanger:</strong> <?= htmlspecialchars($RECEIVER_IBAN) ?>
                        (<?= htmlspecialchars($RECEIVER_NAME) ?>)</li>
                    <li><strong><?= $mode === 'rf' ? 'Betalingskenmerk (RF):' : 'Omschrijving:' ?></strong>
                        <?= htmlspecialchars($mode === 'rf' ? $rf : $remittance) ?></li>
                    <?php if ($expiresTxt): ?>
                        <li><span class="muted">Reservering geldig t/m <?= htmlspecialchars($expiresTxt) ?></span></li>
                    <?php endif; ?>
                </ul>

                <p>
                    <button class="btn" style="background:#374151"
                        onclick="navigator.clipboard.writeText('<?= htmlspecialchars($RECEIVER_IBAN, ENT_QUOTES, 'UTF-8') ?>')">Kopieer
                        IBAN</button>
                    <button class="btn" style="background:#374151"
                        onclick="navigator.clipboard.writeText('<?= number_format($amountEur, 2, '.', '') ?>')">Kopieer
                        bedrag</button>
                    <button class="btn" style="background:#374151"
                        onclick="navigator.clipboard.writeText('<?= htmlspecialchars($mode === 'rf' ? $rf : $remittance, ENT_QUOTES, 'UTF-8') ?>')">
                        Kopieer <?= $mode === 'rf' ? 'betalingskenmerk' : 'omschrijving' ?>
                    </button>
                </p>

                <?php if (isset($_GET['debug'])): ?>
                    <pre class="muted" style="white-space:pre-wrap"><?= htmlspecialchars($payload) ?></pre>
                <?php endif; ?>
            <?php endif; ?>

            <p><a class="btn" href="<?= htmlspecialchars(base_url('index.php'), ENT_QUOTES, 'UTF-8') ?>">Terug naar
                    aanmeldpagina</a></p>
        </div>
    </main>
</body>

</html>