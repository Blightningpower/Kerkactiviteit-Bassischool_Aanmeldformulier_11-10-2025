<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\Writer\SvgWriter; // SVG → werkt zonder GD-extensie

$ref = $_GET['ref'] ?? '';
$stmt = $pdo->prepare('SELECT * FROM payments WHERE session_id = ?');
$stmt->execute([$ref]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    exit('Onbekende referentie.');
}

$amountEur = ($row['amount'] ?? 0) / 100.0;
$kind      = ($row['option'] === 'bus') ? 'BUS' : 'TICKET';
$child     = trim((string)($row['child_name'] ?? ''));
$group     = trim((string)($row['group_class'] ?? ''));
$remittance = trim("Bobbejaanland {$kind} {$child} {$group}");

// EPC-QR payload + QR afbeelding als data: URI (SVG, dus geen GD nodig)
$payload = make_epc_payload($RECEIVER_NAME, $RECEIVER_IBAN, $amountEur, $remittance, $RECEIVER_BIC);
$qr = Builder::create()
    ->writer(new SvgWriter())
    ->data($payload)
    ->encoding(new Encoding('UTF-8'))
    ->size(320)
    ->build();
$dataUri = $qr->getDataUri();

$expiresTxt = '';
if (!empty($row['expires_at'])) {
    try {
        $expiresTxt = (new DateTimeImmutable($row['expires_at']))->format('d-m-Y H:i');
    } catch (Throwable $e) { /* ignore */ }
}

$isPaid = isset($row['status']) && $row['status'] === 'paid';
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Betaalinstructies – <?= htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">
  <div class="card">
    <h2>Betaalinstructies</h2>

    <?php if ($isPaid): ?>
      <p class="tag tag-amber">We hebben je betaling al geregistreerd. Je hoeft niets meer te doen.</p>
    <?php else: ?>
      <p><strong>Stap 1.</strong> Open je bankapp en scan de QR hieronder (werkt met de meeste NL/BE banken).<br>
         Je kunt ook handmatig overmaken met de gegevens eronder.</p>
      <p><img src="<?= $dataUri ?>" alt="SEPA/EPC QR"></p>
      <ul>
        <li><strong>Bedrag:</strong> €<?= number_format($amountEur, 2, ',', '.') ?></li>
        <li><strong>IBAN ontvanger:</strong> <?= htmlspecialchars($RECEIVER_IBAN, ENT_QUOTES, 'UTF-8') ?>
            (<?= htmlspecialchars($RECEIVER_NAME, ENT_QUOTES, 'UTF-8') ?>)</li>
        <li><strong>Omschrijving:</strong> <?= htmlspecialchars($remittance, ENT_QUOTES, 'UTF-8') ?></li>
      </ul>
      <?php if ($expiresTxt): ?>
        <p class="muted">Jouw referentie: <code><?= htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') ?></code>.
          De reservering blijft geldig tot <strong><?= htmlspecialchars($expiresTxt, ENT_QUOTES, 'UTF-8') ?></strong>.</p>
      <?php else: ?>
        <p class="muted">Jouw referentie: <code><?= htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') ?></code>.</p>
      <?php endif; ?>
    <?php endif; ?>

    <p><a class="btn" href="<?= htmlspecialchars(base_url('index.php'), ENT_QUOTES, 'UTF-8') ?>">Terug naar aanmeldpagina</a></p>
  </div>
</main>
</body>
</html>