<?php
declare(strict_types=1);

// ────────────────────────────────────────────────────────────────
// Autoload (Composer) – voor PHPMailer, etc.
require __DIR__ . '/vendor/autoload.php';

// ────────────────────────────────────────────────────────────────
// .env inladen (eenvoudige parser met quotes + inline comments)
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        [$k, $v] = array_map('trim', $parts);

        $wasQuoted = false;
        if (
            (str_starts_with($v, '"') && str_ends_with($v, '"')) ||
            (str_starts_with($v, "'") && str_ends_with($v, "'"))
        ) {
            $v = substr($v, 1, -1);
            $wasQuoted = true;
        }
        // Inline comment alleen wegknippen als het NIET gequote was
        if (!$wasQuoted) {
            $hashPos = strpos($v, ' #');
            if ($hashPos !== false) {
                $v = rtrim(substr($v, 0, $hashPos));
            }
        }
        $_ENV[$k] = $v;
    }
}

// ────────────────────────────────────────────────────────────────
/** App & event-instellingen */
$TIMEZONE = $_ENV['APP_TIMEZONE'] ?? 'Europe/Amsterdam';
$BASE_URL = rtrim($_ENV['APP_BASE_URL'] ?? '', '/');
$APP_ENV  = $_ENV['APP_ENV'] ?? 'development';

$BUS_CAPACITY = 40; // max busplekken
// t/m 1 oktober 2025 open (sluit na 1 okt 23:59:59)
$DEADLINE = new DateTimeImmutable('2025-10-01 23:59:59', new DateTimeZone($TIMEZONE));

// Prijzen (in eurocent)
$PRICE_WITH_BUS    = 4500; // €45,00
$PRICE_WITHOUT_BUS = 2900; // €29,00

// ────────────────────────────────────────────────────────────────
/** Ontvanger (bank) – gebruikt in pay.php */
$RECEIVER_NAME = $_ENV['RECEIVER_NAME'] ?? '';
$RECEIVER_IBAN = isset($_ENV['RECEIVER_IBAN']) ? preg_replace('/\s+/', '', $_ENV['RECEIVER_IBAN']) : '';
$RECEIVER_BIC  = $_ENV['RECEIVER_BIC'] ?? ''; // optioneel

// ────────────────────────────────────────────────────────────────
/** Pending-reservering: voorkeur in minuten, fallback vanaf uren */
$PENDING_HOLD_MINUTES = (int)($_ENV['PENDING_HOLD_MINUTES'] ?? 0);
if ($PENDING_HOLD_MINUTES <= 0 && isset($_ENV['RESERVATION_HOURS'])) {
    $PENDING_HOLD_MINUTES = (int)$_ENV['RESERVATION_HOURS'] * 60;
}
if ($PENDING_HOLD_MINUTES <= 0) {
    $PENDING_HOLD_MINUTES = 1440; // default 24 uur
}

// ────────────────────────────────────────────────────────────────
/** Admin token (voor admin_list/mark_paid/etc.) */
$ADMIN_TOKEN = $_ENV['ADMIN_TOKEN'] ?? '';

// ────────────────────────────────────────────────────────────────
/** Helpers (met guards) */
if (!function_exists('base_url')) {
    function base_url(string $p = ''): string {
        global $BASE_URL;
        return rtrim($BASE_URL, '/') . ($p ? '/' . ltrim($p, '/') : '');
    }
}
if (!function_exists('now')) {
    function now(): DateTimeImmutable {
        global $TIMEZONE;
        return new DateTimeImmutable('now', new DateTimeZone($TIMEZONE));
    }
}
/** Eenvoudige file-lock (mutex) */
if (!function_exists('with_lock')) {
    function with_lock(string $name, callable $fn) {
        $dir = __DIR__ . '/data/locks';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $file = $dir . '/' . preg_replace('/[^a-z0-9_\-]/i', '_', $name) . '.lock';
        $fp = fopen($file, 'c');
        if (!$fp) throw new RuntimeException('Kon lock-bestand niet openen');
        try {
            if (!flock($fp, LOCK_EX)) throw new RuntimeException('Kon lock niet pakken');
            return $fn();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}

// ────────────────────────────────────────────────────────────────
/** Mail (PHPMailer via SMTP) */
$SMTP = [
    'host'         => $_ENV['SMTP_HOST'] ?? '',
    'port'         => (int)($_ENV['SMTP_PORT'] ?? 587),
    'secure'       => $_ENV['SMTP_SECURE'] ?? 'tls',  // tls|ssl|none
    'user'         => $_ENV['SMTP_USER'] ?? '',
    'pass'         => $_ENV['SMTP_PASS'] ?? '',
    'from_email'   => $_ENV['SMTP_FROM_EMAIL'] ?? '',
    'from_name'    => $_ENV['SMTP_FROM_NAME'] ?? 'Aanmeldingen',
    'admin_email'  => $_ENV['ADMIN_EMAIL'] ?? '',
    'admin_emails' => $_ENV['ADMIN_EMAILS'] ?? '',
];

if (!function_exists('admin_recipients')) {
    function admin_recipients(): array {
        global $SMTP;
        $list = $SMTP['admin_emails'] ?: $SMTP['admin_email'];
        return array_values(array_filter(array_map('trim', explode(',', (string)$list))));
    }
}

if (!function_exists('send_mail')) {
    function send_mail(string|array $to, string $subject, string $html, string $alt = '', ?string $replyToEmail = null, ?string $replyToName = null): bool {
        global $SMTP, $APP_ENV;
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            if ($SMTP['host'] && $SMTP['user'] && $SMTP['pass']) {
                $mail->isSMTP();
                $mail->Host = $SMTP['host'];
                $mail->SMTPAuth = true;
                $mail->Username = $SMTP['user'];
                $mail->Password = $SMTP['pass'];
                $sec = strtolower((string)$SMTP['secure']);
                if ($sec === 'ssl') {
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                    $mail->Port = $SMTP['port'] ?: 465;
                } elseif ($sec === 'tls') {
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = $SMTP['port'] ?: 587;
                } else {
                    $mail->SMTPSecure = false;
                    $mail->Port = $SMTP['port'] ?: 25;
                }
            } else {
                $mail->isMail(); // fallback zonder SMTP-config
            }

            $mail->CharSet  = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->setFrom($SMTP['from_email'] ?: $SMTP['user'], $SMTP['from_name'] ?: 'Aanmeldingen');

            if ($replyToEmail && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($replyToEmail, $replyToName ?: $replyToEmail);
            }

            foreach ((array)$to as $addr) {
                if ($addr && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $mail->addAddress($addr);
                }
            }
            if (empty($mail->getToAddresses())) {
                throw new \RuntimeException('Geen geldige ontvanger(s).');
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $alt ?: strip_tags($html);

            if (strtolower($APP_ENV) === 'development') {
                $mail->SMTPDebug = 0; // zet op 2 voor lokale debug
            }

            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('Mail error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('send_admin_mail')) {
    function send_admin_mail(string $subject, string $html, string $alt = '', ?string $replyToEmail = null, ?string $replyToName = null): bool {
        return send_mail(admin_recipients(), $subject, $html, $alt, $replyToEmail, $replyToName);
    }
}

if (!function_exists('send_user_mail')) {
    function send_user_mail(string $toEmail, string $subject, string $html, string $alt = '', ?string $replyToEmail = null, ?string $replyToName = null): bool {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;
        return send_mail([$toEmail], $subject, $html, $alt, $replyToEmail, $replyToName);
    }
}

// ────────────────────────────────────────────────────────────────
/** (Optioneel) Tikkie – globale links per bedrag */
$TIKKIE_URL_29 = $_ENV['TIKKIE_URL_29'] ?? '';
$TIKKIE_URL_45 = $_ENV['TIKKIE_URL_45'] ?? '';