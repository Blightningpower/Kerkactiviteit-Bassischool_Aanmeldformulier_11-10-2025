<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$childName     = trim($_POST['child_name'] ?? '');
$groupClass    = trim($_POST['group_class'] ?? '');
$phone         = trim($_POST['phone'] ?? '');
$option        = $_POST['option'] ?? '';
$consent       = isset($_POST['consent']);

$parentAccompany = isset($_POST['parent_accompany']);
$parentName    = trim($_POST['parent_name'] ?? '');
$parentPhone   = trim($_POST['parent_phone'] ?? '');
$parentEmail   = trim($_POST['parent_email'] ?? '');
$contactEmail  = trim($_POST['contact_email'] ?? ''); // optioneel: voor betaallink naar deelnemer

// --- Validatie baseline ---
if (!$consent || !$childName || !preg_match('/^[1-8]$/', $groupClass) || !$phone) {
  http_response_code(400);
  exit('Ongeldige invoer. Ga terug en controleer je gegevens.');
}

$requiresParent = ((int)$groupClass >= 1 && (int)$groupClass <= 4) || $parentAccompany;
if ($requiresParent) {
  if (!$parentName || !$parentPhone || !filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    exit('Vul de ouder/begeleider volledig en correct in.');
  }
}

// --- Deadline ---
if (now() > $DEADLINE) {
  exit('Inschrijving gesloten (na 1 oktober 2025).');
}

// --- Capaciteit ---
$activeBus = active_bus_count($pdo);             // paid + niet-verlopen pending
$busFull   = $activeBus >= $BUS_CAPACITY;
if ($option === 'bus' && $busFull) {
  exit('De bus is inmiddels vol. Kies svp "Eigen vervoer (€29)".');
}

// --- Bedrag & titel ---
if ($option === 'bus') {
  $amount = $PRICE_WITH_BUS;       // in eurocent
  $title  = 'Bobbejaanland – Met bus + ticket';
} elseif ($option === 'zonder_bus') {
  $amount = $PRICE_WITHOUT_BUS;
  $title  = 'Bobbejaanland – Eigen vervoer (alleen ticket)';
} else {
  http_response_code(400);
  exit('Ongeldige optie.');
}

// --- Referentie + reservering ---
try {
  $ref = 'BJB-' . date('Ymd') . '-' . substr(bin2hex(random_bytes(8)), 0, 8);
} catch (Throwable $e) {
  $ref = 'BJB-' . date('Ymd') . '-' . uniqid();
}

$hours = max(1, (int)$RESERVATION_HOURS);
$expiresAt = now()->add(new DateInterval('PT' . $hours . 'H'));

// --- Opslaan als pending (houdt plek vast) ---
$ins = $pdo->prepare(
  'INSERT INTO payments (
      session_id, status, option, amount,
      child_name, group_class, phone,
      parent_required, parent_name, parent_phone, parent_email,
      contact_email, expires_at, created_at
   ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
);
$ins->execute([
  $ref, 'pending', $option, $amount,
  $childName, $groupClass, $phone,
  $requiresParent ? 1 : 0, $parentName, $parentPhone, $parentEmail,
  $contactEmail ?: null, $expiresAt->format(DateTimeInterface::ATOM), now()->format(DateTimeInterface::ATOM)
]);

// --- Admin-mail met markeer/annuleer links ---
$markUrl   = base_url('mark_paid.php?ref=' . urlencode($ref) . '&token=' . urlencode($ADMIN_TOKEN));
$cancelUrl = base_url('mark_paid.php?action=cancel&ref=' . urlencode($ref) . '&token=' . urlencode($ADMIN_TOKEN));

$busText = $option === 'bus' ? 'Met bus + ticket (€45)' : 'Eigen vervoer (€29)';
$html = '<h3>Nieuwe aanmelding (pending) – ' . htmlspecialchars($busText) . '</h3>'
      . '<p><strong>Ref:</strong> ' . htmlspecialchars($ref) . '</p>'
      . '<p><strong>Deelnemer:</strong> ' . htmlspecialchars($childName) . ' (groep ' . htmlspecialchars($groupClass) . ')</p>'
      . '<p><strong>Contact tel.:</strong> ' . htmlspecialchars($phone) . '</p>'
      . ($requiresParent
          ? '<p><strong>Ouder/begeleider:</strong> ' . htmlspecialchars($parentName)
            . ' – ' . htmlspecialchars($parentPhone)
            . ' – ' . htmlspecialchars($parentEmail) . '</p>'
          : '<p><strong>Ouder/begeleider:</strong> niet opgegeven</p>')
      . '<p><a href="' . htmlspecialchars($markUrl) . '">Markeer betaald</a> • '
      .   '<a href="' . htmlspecialchars($cancelUrl) . '">Annuleer</a></p>'
      . '<p><em>Reservering vervalt automatisch op ' . htmlspecialchars($expiresAt->format('d-m-Y H:i')) . '.</em></p>';

// reply-to handig naar ouder of contactpersoon
$replyToEmail = $requiresParent ? $parentEmail : ($contactEmail ?: null);
$replyToName  = $requiresParent ? $parentName  : ($childName ?: null);

send_admin_mail('Nieuwe aanmelding (pending) – ' . $childName, $html, '', $replyToEmail, $replyToName);

// --- Optioneel: mail betaallink naar deelnemer/contact ---
if (filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
  $payLink = base_url('pay.php?ref=' . urlencode($ref));
  send_user_mail(
    $contactEmail,
    'Aanmelding ontvangen – betaallink',
    '<p>Bedankt voor je aanmelding.</p>'
    . '<p>Betaal via deze link: <a href="' . htmlspecialchars($payLink) . '">' . htmlspecialchars($payLink) . '</a></p>'
    . '<p>Referentie: <code>' . htmlspecialchars($ref) . '</code></p>'
  );
}

// --- Door naar betaalinstructies (met QR) ---
header('Location: ' . base_url('pay.php?ref=' . urlencode($ref)));
exit;