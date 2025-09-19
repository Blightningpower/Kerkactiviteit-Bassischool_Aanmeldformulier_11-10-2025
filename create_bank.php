<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ────────────────────────────────────────────────────────────────
// Invoer ophalen
$childName  = trim($_POST['child_name']  ?? '');
$groupClass = trim($_POST['group_class'] ?? '');
$phone      = trim($_POST['phone']       ?? '');
$email      = trim($_POST['contact_email']       ?? '');
$option     = $_POST['option']           ?? '';
$consent    = isset($_POST['consent']);

// Ouder/begeleider (optioneel, maar verplicht voor groep 1–4)
$parentAccompany = isset($_POST['parent_accompany']);
$parentName  = trim($_POST['parent_name']  ?? '');
$parentPhone = trim($_POST['parent_phone'] ?? '');
$parentEmail = trim($_POST['parent_email'] ?? '');

// ────────────────────────────────────────────────────────────────
if (
    !$consent ||
    $childName === '' ||
    !preg_match('/^[1-8]$/', $groupClass) ||
    $phone === '' ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    http_response_code(400);
    exit('Ongeldige invoer.');
}

$requiresParent = ((int)$groupClass >= 1 && (int)$groupClass <= 4) || $parentAccompany;
if ($requiresParent) {
    if ($parentName === '' || $parentPhone === '' || !filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        exit('Vul de ouder/begeleider-gegevens volledig en correct in.');
    }
}

// Deadline
if (now() > $DEADLINE) {
    exit('Inschrijving gesloten (na 1 oktober 2025).');
}

// ────────────────────────────────────────────────────────────────
// Capaciteit bewaken met actieve reserveringen
if ($option === 'bus' && function_exists('active_bus_count') && active_bus_count($pdo) >= $BUS_CAPACITY) {
    exit('De bus is inmiddels vol. Kies svp "Eigen vervoer (€29)".');
}

// Bedrag/omschrijving bepalen
if ($option === 'bus') {
    $amount = $PRICE_WITH_BUS;         // 4500
    $title  = 'Met bus + ticket (€45)';
} elseif ($option === 'zonder_bus') {
    $amount = $PRICE_WITHOUT_BUS;      // 2900
    $title  = 'Eigen vervoer – alleen ticket (€29)';
} else {
    http_response_code(400);
    exit('Ongeldige optie.');
}

// ────────────────────────────────────────────────────────────────
// Unieke referentie en vervaltijd (pending reservering)
$ref       = 'BJB-' . date('Ymd') . '-' . substr(bin2hex(random_bytes(8)), 0, 10);
$createdAt = now()->format(DateTimeInterface::ATOM);
$expiresAt = now()->modify('+' . (int)$PENDING_HOLD_MINUTES . ' minutes')->format(DateTimeInterface::ATOM);

// Opslaan als pending
$stmt = $pdo->prepare('
  INSERT INTO payments
  (session_id, status, option, amount, child_name, group_class, phone,
   parent_required, parent_name, parent_phone, parent_email,
   contact_email, emailed, expires_at, created_at)
  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
');
$stmt->execute([
    $ref,
    'pending',
    $option,
    $amount,
    $childName,
    $groupClass,
    $phone,
    $requiresParent ? 1 : 0,
    $parentName ?: null,
    $parentPhone ?: null,
    $parentEmail ?: null,
    $email,
    0,
    $expiresAt,
    $createdAt,
]);

// ────────────────────────────────────────────────────────────────
// Links en presentatiewaarden
$amountEur = $amount / 100;
$payUrl    = base_url('pay.php?ref=' . urlencode($ref));
$adminUrl  = base_url('admin_list.php?token=' . urlencode($ADMIN_TOKEN));
$confirmUrl= base_url('mark_paid.php?ref=' . urlencode($ref) . '&action=confirm&token=' . urlencode($ADMIN_TOKEN));
$cancelUrl = base_url('mark_paid.php?ref=' . urlencode($ref) . '&action=cancel&token=' . urlencode($ADMIN_TOKEN));

$parentBlock = $requiresParent
  ? ('<p><strong>Ouder/begeleider:</strong> ' . htmlspecialchars($parentName, ENT_QUOTES, 'UTF-8')
      . ' – ' . htmlspecialchars($parentPhone, ENT_QUOTES, 'UTF-8')
      . ' – ' . htmlspecialchars($parentEmail, ENT_QUOTES, 'UTF-8') . '</p>')
  : '<p><strong>Ouder/begeleider:</strong> niet vereist</p>';

$holdNoteHtml = '';
if ($option === 'bus') {
    try {
        $dtLocal = (new DateTimeImmutable($expiresAt))->setTimezone(new DateTimeZone($TIMEZONE));
        $holdNoteHtml = '<p style="color:#6b7280;margin:8px 0 0 0">Busplek voorlopig gereserveerd tot <strong>'
                      . $dtLocal->format('d-m-Y H:i') . ' uur</strong>.</p>';
    } catch (\Throwable $e) { /* ignore */ }
}

// ────────────────────────────────────────────────────────────────
// Admin mail
$adminHtml = '<h3>Nieuwe aanmelding (pending)</h3>'
  . '<p><strong>Deelnemer:</strong> ' . htmlspecialchars($childName, ENT_QUOTES, 'UTF-8')
  . ' (groep ' . htmlspecialchars($groupClass, ENT_QUOTES, 'UTF-8') . ')</p>'
  . '<p><strong>Optie:</strong> ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>'
  . $parentBlock
  . '<p><strong>Telefoon:</strong> ' . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . '<br>'
  . '<strong>E-mail:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>'
  . '<p><strong>Overschrijving:</strong><br>'
  . 'Naam ontvanger: ' . htmlspecialchars($RECEIVER_NAME, ENT_QUOTES, 'UTF-8') . '<br>'
  . 'IBAN: ' . htmlspecialchars($RECEIVER_IBAN, ENT_QUOTES, 'UTF-8') . '<br>'
  . 'Bedrag: €' . number_format($amountEur, 2, ',', '.') . '<br>'
  . 'Omschr.: <strong>' . htmlspecialchars($childName, ENT_QUOTES, 'UTF-8') . '</strong></p>'
  . $holdNoteHtml
  . '<p>'
  . '<a href="' . htmlspecialchars($payUrl, ENT_QUOTES, 'UTF-8') . '">Instructies voor deelnemer</a><br>'
  . '<a href="' . htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8') . '">Bevestig betaling</a> — '
  . '<a href="' . htmlspecialchars($cancelUrl, ENT_QUOTES, 'UTF-8') . '">Annuleer</a><br>'
  . '<a href="' . htmlspecialchars($adminUrl, ENT_QUOTES, 'UTF-8') . '">Overzicht</a>'
  . '</p>';

send_admin_mail(
    'Nieuwe aanmelding: ' . $childName,
    $adminHtml,
    strip_tags($adminHtml),
    $email,
    $childName
);

// ────────────────────────────────────────────────────────────────
// Deelnemer/ouder mail – bevestigt ontvangst + duidelijke instructie
$userHtml = '<h3>We hebben je aanmelding ontvangen</h3>'
  . '<p>Bedankt voor je aanmelding voor <strong>' . htmlspecialchars($childName, ENT_QUOTES, 'UTF-8') . '</strong>. '
  . 'Je aanmelding is <strong>voorlopig</strong>. Het is pas <strong>compleet</strong> nadat wij je betaling hebben ontvangen '
  . 'én dit per e-mail hebben bevestigd.</p>'
  . $holdNoteHtml
  . '<br><br>'
  . 'Was betalen nog niet gelukt? Hieronder staan de betaalinstructies:'
  . '<h4>Betaalinstructies</h4>'
  . '<ul>'
  . '<li><strong>Bedrag:</strong> €' . number_format($amountEur, 2, ',', '.') . ' (' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ')</li>'
  . '<li><strong>IBAN:</strong> ' . htmlspecialchars($RECEIVER_IBAN, ENT_QUOTES, 'UTF-8')
  . ' (' . htmlspecialchars($RECEIVER_NAME, ENT_QUOTES, 'UTF-8') . ')</li>'
  . '<li><strong>Omschrijving:</strong> <em>' . htmlspecialchars($childName, ENT_QUOTES, 'UTF-8') . '</em></li>'
  . '</ul>'
  . '<p>Je kunt de instructies ook altijd terugvinden op je persoonlijke betaalpagina: '
  . '<a href="' . htmlspecialchars($payUrl, ENT_QUOTES, 'UTF-8') . '">betaalpagina</a>.</p>'
  . '<p>Na ontvangst van je betaling sturen we een bevestiging. Tot dan is je inschrijving nog niet compleet.</p>';

send_user_mail(
    $email,
    'Aanmelding ontvangen – wacht op bevestiging betaling',
    $userHtml,
    strip_tags($userHtml)
);

// ────────────────────────────────────────────────────────────────
// Door naar de (persoonlijke) betaalpagina
header('Location: ' . $payUrl);
exit;