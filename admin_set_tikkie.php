<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$token = $_POST['token'] ?? '';
$ref   = trim($_POST['ref'] ?? '');
$url   = trim($_POST['tikkie_url'] ?? '');

if ($token !== ($ADMIN_TOKEN ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}
if ($ref === '') {
    http_response_code(400);
    exit('Missing ref');
}

// Record ophalen (voor e-mail + controle)
$stmt = $pdo->prepare('SELECT * FROM payments WHERE session_id = ?');
$stmt->execute([$ref]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    exit('Onbekende referentie.');
}

// Simpele URL-validatie (leeg = link wissen)
if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
    header('Location: ' . base_url('admin_list.php?token=' . urlencode($ADMIN_TOKEN) . '&msg=' . urlencode('Ongeldige URL.')));
    exit;
}

// (Optioneel) alleen tikkie.me toestaan
if ($url !== '') {
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    $allowedHosts = ['tikkie.me', 'paymentlink.tikkie.me']; // eventueel uitbreiden
    if ($host && !in_array($host, $allowedHosts, true)) {
        header('Location: ' . base_url('admin_list.php?token=' . urlencode($ADMIN_TOKEN) . '&msg=' . urlencode('Alleen tikkie.me links toegestaan.')));
        exit;
    }
}

// Updaten
$pdo->prepare('UPDATE payments SET tikkie_url = ? WHERE session_id = ?')->execute([$url, $ref]);

// (Optioneel) Mail de deelnemer wanneer er een link is gezet
if ($url !== '') {
    $to    = (string)($row['contact_email'] ?? '');
    if ($to && filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $amountEur = ((int)$row['amount']) / 100;
        $title     = ($row['option'] === 'bus') ? 'Met bus + ticket (€45)' : 'Eigen vervoer – alleen ticket (€29)';
        $child     = (string)($row['child_name'] ?? '');

        $html = '<p>Beste ouder/deelnemer,</p>'
              . '<p>Voor de aanmelding van <strong>' . htmlspecialchars($child) . '</strong> is een betaalverzoek beschikbaar.</p>'
              . '<p><a href="' . htmlspecialchars($url) . '">Betaal via Tikkie</a></p>'
              . '<p>Bedrag: €' . number_format($amountEur, 2, ',', '.') . ' — ' . htmlspecialchars($title) . '<br>'
              . 'Zet in de beschrijving: <em>' . htmlspecialchars($child) . '</em></p>'
              . '<p>Lukt het niet? Je kunt ook handmatig overmaken naar IBAN '
              . htmlspecialchars($RECEIVER_IBAN) . ' (' . htmlspecialchars($RECEIVER_NAME) . ').</p>';

        // Stuur naar deelnemer (reply-to mag naar de admin)
        send_user_mail($to, 'Betaalverzoek – Bobbejaanland', $html, strip_tags($html));
    }
}

// Terug naar overzicht
header('Location: ' . base_url('admin_list.php?token=' . urlencode($ADMIN_TOKEN) . '&msg=' . urlencode('Tikkie-link opgeslagen.')));
exit;