<?php
declare(strict_types=1);

// ────────────────────────────────────────────────────────────────
// Autoload (Composer)
require __DIR__ . '/vendor/autoload.php';

// ────────────────────────────────────────────────────────────────
// .env inladen (eenvoudige parser)
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        // strip omringende quotes
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) ||
            (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            $v = substr($v, 1, -1);
        }
        $_ENV[$k] = $v;
    }
}

// ────────────────────────────────────────────────────────────────
// App & event-instellingen
$TIMEZONE   = $_ENV['APP_TIMEZONE'] ?? 'Europe/Amsterdam';
$BASE_URL   = rtrim($_ENV['APP_BASE_URL'] ?? '', '/');
$APP_ENV    = $_ENV['APP_ENV'] ?? 'development';

$BUS_CAPACITY = 40; // max busplekken
// t/m 1 okt 2025 open (sluit na 1 okt 23:59:59 lokale tijd)
$DEADLINE = new DateTimeImmutable('2025-10-01 23:59:59', new DateTimeZone($TIMEZONE));

// Prijzen in eurocent
$PRICE_WITH_BUS    = 4500; // €45,00
$PRICE_WITHOUT_BUS = 2900; // €29,00

// Reserveringen
$RESERVATION_HOURS = (int)($_ENV['RESERVATION_HOURS'] ?? 24);

// Bankontvanger (voor EPC-QR)
$RECEIVER_NAME = $_ENV['RECEIVER_NAME'] ?? '';
$RECEIVER_IBAN = strtoupper(preg_replace('/\s+/', '', $_ENV['RECEIVER_IBAN'] ?? ''));
$RECEIVER_BIC  = strtoupper(trim($_ENV['RECEIVER_BIC'] ?? '')); // bij ING NL: INGBNL2A

// Admin-token voor mark_paid.php
$ADMIN_TOKEN = $_ENV['ADMIN_TOKEN'] ?? '';

// ────────────────────────────────────────────────────────────────
// Helpers (globaal, niet in een functie nestelen)
function base_url(string $p = ''): string {
    global $BASE_URL;
    return rtrim($BASE_URL, '/') . ($p ? '/' . ltrim($p, '/') : '');
}
function now(): DateTimeImmutable {
    global $TIMEZONE;
    return new DateTimeImmutable('now', new DateTimeZone($TIMEZONE));
}
function make_epc_payload(string $name, string $iban, float $amountEuro, string $remittance, string $bic = ''): string {
    $iban   = strtoupper(preg_replace('/\s+/', '', $iban));
    $bic    = strtoupper(trim($bic));
    $amount = number_format($amountEuro, 2, '.', ''); // 12.34
    // BCD v001, charset 1, SCT
    return implode("\n", ['BCD','001','1','SCT',$bic,$name,$iban,'EUR'.$amount,'',$remittance]);
}

// ────────────────────────────────────────────────────────────────
// Mail config (PHPMailer via SMTP)
$SMTP = [
    'host'         => $_ENV['SMTP_HOST'] ?? '',
    'port'         => (int)($_ENV['SMTP_PORT'] ?? 587),
    'secure'       => $_ENV['SMTP_SECURE'] ?? 'tls',   // tls | ssl | none
    'user'         => $_ENV['SMTP_USER'] ?? '',
    'pass'         => $_ENV['SMTP_PASS'] ?? '',
    'from_email'   => $_ENV['SMTP_FROM_EMAIL'] ?? '',
    'from_name'    => $_ENV['SMTP_FROM_NAME'] ?? 'Aanmeldingen',
    'admin_email'  => $_ENV['ADMIN_EMAIL'] ?? '',
    'admin_emails' => $_ENV['ADMIN_EMAILS'] ?? '',     // optioneel: komma-gescheiden
];

function admin_recipients(): array {
    global $SMTP;
    $list = $SMTP['admin_emails'] ?: $SMTP['admin_email'];
    $arr  = array_filter(array_map('trim', explode(',', (string)$list)));
    return $arr ?: [];
}

function send_mail(string|array $to, string $subject, string $html, string $alt = '', ?string $replyToEmail = null, ?string $replyToName = null): bool {
    global $SMTP, $APP_ENV;

    $useSmtp = $SMTP['host'] && $SMTP['user'] && $SMTP['pass'];
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        if ($useSmtp) {
            $mail->isSMTP();
            $mail->Host = $SMTP['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $SMTP['user'];
            $mail->Password = $SMTP['pass'];

            $secure = strtolower((string)$SMTP['secure']);
            if ($secure === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port = $SMTP['port'] ?: 465;
            } elseif ($secure === 'tls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = $SMTP['port'] ?: 587;
            } else {
                $mail->SMTPSecure = false;
                $mail->Port = $SMTP['port'] ?: 25;
            }
        } else {
            $mail->isMail(); // fallback (alleen als je host dat toestaat)
        }

        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->setFrom($SMTP['from_email'] ?: $SMTP['user'], $SMTP['from_name'] ?: 'Aanmeldingen');

        if ($replyToEmail && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($replyToEmail, $replyToName ?: $replyToEmail);
        }

        foreach ((array)$to as $addr) {
            if ($addr && filter_var($addr, FILTER_VALIDATE_EMAIL)) $mail->addAddress($addr);
        }
        if (empty($mail->getToAddresses())) {
            throw new \RuntimeException('Geen geldige ontvanger(s).');
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $alt ?: strip_tags($html);

        if (strtolower((string)$APP_ENV) === 'development') {
            $mail->SMTPDebug = 0; // zet op 2 voor lokale debug
        }

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        error_log('Mail error: '.$e->getMessage());
        return false;
    }
}

function send_admin_mail(string $subject, string $html, string $alt = '', ?string $replyToEmail = null, ?string $replyToName = null): bool {
    return send_mail(admin_recipients(), $subject, $html, $alt, $replyToEmail, $replyToName);
}
function send_user_mail(string $toEmail, string $subject, string $html, string $alt = '', ?string $replyToEmail = null, ?string $replyToName = null): bool {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;
    return send_mail([$toEmail], $subject, $html, $alt, $replyToEmail, $replyToName);
}