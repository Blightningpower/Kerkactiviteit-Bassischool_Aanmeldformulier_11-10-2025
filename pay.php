<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$ref = $_GET['ref'] ?? '';
$stmt = $pdo->prepare('SELECT * FROM payments WHERE session_id = ?');
$stmt->execute([$ref]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    exit('Onbekende referentie.');
}

$amountCents = (int) ($row['amount'] ?? 0);
$amountEur = $amountCents / 100.0;
$option = (string) ($row['option'] ?? '');
$title = $option === 'bus' ? 'Met bus + ticket' : 'Eigen vervoer – alleen ticket';
$child = (string) ($row['child_name'] ?? '');
$isPaid = ((string) ($row['status'] ?? '')) === 'paid';

// Bepaal Tikkie-link: eerst per aanmelding (tikkie_url), anders uit .env per bedrag
$tikkieUrl = (string) ($row['tikkie_url'] ?? '');
if ($tikkieUrl === '') {
    if ($amountCents === 4500 && !empty($TIKKIE_URL_45)) {
        $tikkieUrl = $TIKKIE_URL_45;
    } elseif ($amountCents === 2900 && !empty($TIKKIE_URL_29)) {
        $tikkieUrl = $TIKKIE_URL_29;
    }
}
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Betaalinstructies – <?= htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') ?></title>

    <link rel="stylesheet" href="<?= htmlspecialchars(base_url('style.css'), ENT_QUOTES, 'UTF-8') ?>">

    <!-- Favicons -->
    <link rel="apple-touch-icon" sizes="180x180"
        href="<?= htmlspecialchars(base_url('img/favicon_io/apple-touch-icon.png'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" type="image/png" sizes="32x32"
        href="<?= htmlspecialchars(base_url('img/favicon_io/favicon-32x32.png'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" type="image/png" sizes="16x16"
        href="<?= htmlspecialchars(base_url('img/favicon_io/favicon-16x16.png'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="manifest" href="<?= htmlspecialchars(base_url('site.webmanifest'), ENT_QUOTES, 'UTF-8') ?>">
    <meta name="theme-color" content="#0f172a">
</head>

<body>
    <main class="container">
        <div class="card">
            <h2>Betaalinstructies</h2>

            <?php if ($isPaid): ?>
                <p class="tag tag-amber">
                    Deze inschrijving is al <strong>bevestigd</strong>. Er is niets meer nodig.
                </p>
            <?php else: ?>
                <?php if ($tikkieUrl): ?>
                    <p class="tag tag-amber" style="display:inline-block;margin-bottom:8px; font-size:1.1em;">
                        Zet in de beschrijving van je betaling <strong>de naam van het kind</strong>:
                        <em><?= htmlspecialchars($child, ENT_QUOTES, 'UTF-8') ?></em>.
                    </p>
                    <p>
                        <a class="btn" style="background:#0ea5e9"
                            href="<?= htmlspecialchars($tikkieUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                            Betaal via Tikkie
                        </a>
                    </p>
                    <p class="muted">Lukt Tikkie niet? Je kunt ook handmatig overmaken:</p>
                <?php endif; ?>

                <ul>
                    <li><strong>Bedrag:</strong> €<?= number_format($amountEur, 2, ',', '.') ?>
                        (<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>)
                    </li>
                    <li><strong>IBAN:</strong> <?= htmlspecialchars($RECEIVER_IBAN, ENT_QUOTES, 'UTF-8') ?>
                        (<?= htmlspecialchars($RECEIVER_NAME, ENT_QUOTES, 'UTF-8') ?>)
                    </li>
                    <li><strong>Omschrijving:</strong>
                        <?= htmlspecialchars($child, ENT_QUOTES, 'UTF-8') ?>
                        <strong>(zet dit exact zo in de omschrijving bij de tikkie)</strong>
                    </li>
                </ul>
                <p>
                    <button class="btn" style="background:#374151"
                        onclick="navigator.clipboard.writeText('<?= htmlspecialchars($RECEIVER_IBAN, ENT_QUOTES, 'UTF-8') ?>')">
                        Kopieer IBAN
                    </button>
                    <button class="btn" style="background:#374151"
                        onclick="navigator.clipboard.writeText('<?= number_format($amountEur, 2, '.', '') ?>')">
                        Kopieer bedrag
                    </button>
                    <button class="btn" style="background:#374151"
                        onclick="navigator.clipboard.writeText('<?= htmlspecialchars($child, ENT_QUOTES, 'UTF-8') ?>')">
                        Kopieer omschrijving
                    </button>
                </p>
                <p><strong>Stap 2.</strong> Zodra de betaling binnen is, bevestigen we de aanmelding per e-mail.</p>
            <?php endif; ?>

            <p><a class="btn" href="<?= htmlspecialchars(base_url('index.php'), ENT_QUOTES, 'UTF-8') ?>">
                    Terug naar aanmeldpagina</a></p>
        </div>
    </main>
</body>

</html>