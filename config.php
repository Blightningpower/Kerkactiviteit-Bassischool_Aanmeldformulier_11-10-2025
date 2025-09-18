<?php
declare(strict_types=1);

// ────────────────────────────────────────────────────────────────
// Autoload (Composer) – voor PHPMailer en Endroid QR
require __DIR__ . '/vendor/autoload.php';

// ────────────────────────────────────────────────────────────────
// .env inladen (eenvoudige parser)
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#')
            continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if (
            (str_starts_with($v, '"') && str_ends_with($v, '"')) ||
            (str_starts_with($v, "'") && str_ends_with($v, "'"))
        ) {
            $v = substr($v, 1, -1);
        }
        $_ENV[$k] = $v;
    }
}

// ────────────────────────────────────────────────────────────────
// App & event-instellingen
$TIMEZONE = $_ENV['APP_TIMEZONE'] ?? 'Europe/Amsterdam';
$BASE_URL = rtrim($_ENV['APP_BASE_URL'] ?? '', '/');
$APP_ENV = $_ENV['APP_ENV'] ?? 'development';

$BUS_CAPACITY = 40; // max busplekken
$DEADLINE = new DateTimeImmutable('2025-10-01 23:59:59', new DateTimeZone($TIMEZONE));

// Prijzen (in eurocent)
$PRICE_WITH_BUS = 4500; // €45,00
$PRICE_WITHOUT_BUS = 2900; // €29,00

// Reserveringstijd (voor pending inschrijvingen)
$RESERVATION_HOURS = (int) ($_ENV['RESERVATION_HOURS'] ?? 24);

// Bankontvanger voor QR
$RECEIVER_NAME = $_ENV['RECEIVER_NAME'] ?? '';
$RECEIVER_IBAN = strtoupper(preg_replace('/\s+/', '', $_ENV['RECEIVER_IBAN'] ?? ''));
$RECEIVER_BIC = strtoupper(trim($_ENV['RECEIVER_BIC'] ?? '')); // mag leeg zijn

// Admin token (voor mark_paid.php)
$ADMIN_TOKEN = $_ENV['ADMIN_TOKEN'] ?? '';

// ────────────────────────────────────────────────────────────────
// Helpers (globaal houden – met guards tegen dubbele declaratie)
if (!function_exists('base_url')) {
    function base_url(string $p = ''): string
    {
        global $BASE_URL;
        return rtrim($BASE_URL, '/') . ($p ? '/' . ltrim($p, '/') : '');
    }
}
if (!function_exists('now')) {
    function now(): DateTimeImmutable
    {
        global $TIMEZONE;
        return new DateTimeImmutable('now', new DateTimeZone($TIMEZONE));
    }
}

// Eenvoudige file-lock (zoals “slot”/mutex)
if (!function_exists('with_lock')) {
    function with_lock(string $name, callable $fn)
    {
        $dir = __DIR__ . '/data/locks';
        if (!is_dir($dir))
            mkdir($dir, 0775, true);
        $file = $dir . '/' . preg_replace('/[^a-z0-9_\-]/i', '_', $name) . '.lock';
        $fp = fopen($file, 'c');
        if (!$fp)
            throw new RuntimeException('Kon lock-bestand niet openen');
        try {
            if (!flock($fp, LOCK_EX))
                throw new RuntimeException('Kon lock niet pakken');
            return $fn();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}

// ────────────────────────────────────────────────────────────────
// EPC/SEPA QR helpers
if (!function_exists('epc_sanitize_text')) {
    function epc_sanitize_text(string $s, int $max): string
    {
        $s = preg_replace("/[\r\n]+/", ' ', $s);
        $s = preg_replace('/[^A-Z0-9 \-\_\.\:\/]/i', '', $s);
        return mb_substr(trim($s), 0, $max);
    }
}
if (!function_exists('make_epc_payload_unstructured')) {
    // EPC v002 – vrije omschrijving (ING/BEL meest compatibel)
    function make_epc_payload_unstructured(string $name, string $iban, float $amountEuro, string $remittance, string $bic = ''): string
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban));
        $bic = strtoupper(trim($bic));
        $name = epc_sanitize_text($name, 70);
        $amount = number_format(max(0, $amountEuro), 2, '.', '');   // 29.00

        $rem = epc_sanitize_text($remittance, 70);
        if ($rem === '' || preg_match('/^\d+$/', $rem))
            $rem = 'BJB ' . $rem; // nooit puur numeriek

        return implode("\n", [
            'BCD',
            '002',
            '1',
            'SCT',
            $bic,
            $name,
            $iban,
            'EUR' . $amount,
            '',              // Purpose leeg  => unstructured
            $rem
        ]);
    }
}
if (!function_exists('make_rf_creditor_reference')) {
    // ISO 11649 “RF…” referentie (gestructureerd)
    function make_rf_creditor_reference(string $base): string
    {
        $ref = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $base));
        $ref = substr($ref, 0, 21);
        if ($ref === '')
            $ref = 'REF';
        $tmp = $ref . 'RF00';
        $conv = '';
        for ($i = 0, $n = strlen($tmp); $i < $n; $i++) {
            $c = $tmp[$i];
            $conv .= ctype_alpha($c) ? (string) (ord($c) - 55) : $c; // A->10 … Z->35
        }
        // mod97 in brokken
        $mod = 0;
        $pos = 0;
        $len = strlen($conv);
        while ($pos < $len) {
            $chunk = substr($conv, $pos, 7);
            $mod = (int) ($mod . $chunk) % 97;
            $pos += 7;
        }
        $check = str_pad((string) (98 - $mod), 2, '0', STR_PAD_LEFT);
        return 'RF' . $check . $ref;
    }
}
if (!function_exists('make_epc_payload_structured')) {
    // EPC v002 – structured (SCOR + RF…)
    function make_epc_payload_structured(string $name, string $iban, float $amountEuro, string $rf, string $bic = ''): string
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban));
        $bic = strtoupper(trim($bic));
        $name = epc_sanitize_text($name, 70);
        $amount = number_format(max(0, $amountEuro), 2, '.', '');
        $rf = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $rf));

        return implode("\n", [
            'BCD',
            '002',
            '1',
            'SCT',
            $bic,
            $name,
            $iban,
            'EUR' . $amount,
            'SCOR',         // => structured reference
            $rf
        ]);
    }
}

// ────────────────────────────────────────────────────────────────
// (Optioneel) Mail via SMTP (admin bevestigingen)
$SMTP = [
    'host' => $_ENV['SMTP_HOST'] ?? '',
    'port' => (int) ($_ENV['SMTP_PORT'] ?? 587),
    'secure' => $_ENV['SMTP_SECURE'] ?? 'tls',  // tls|ssl|none
    'user' => $_ENV['SMTP_USER'] ?? '',
    'pass' => $_ENV['SMTP_PASS'] ?? '',
    'from_email' => $_ENV['SMTP_FROM_EMAIL'] ?? '',
    'from_name' => $_ENV['SMTP_FROM_NAME'] ?? 'Aanmeldingen',
    'admin_email' => $_ENV['ADMIN_EMAIL'] ?? '',
    'admin_emails' => $_ENV['ADMIN_EMAILS'] ?? '',
];

if (!function_exists('admin_recipients')) {
    function admin_recipients(): array
    {
        global $SMTP;
        $list = $SMTP['admin_emails'] ?: $SMTP['admin_email'];
        return array_values(array_filter(array_map('trim', explode(',', (string) $list))));
    }
}
if (!function_exists('send_mail')) {
    function send_mail(string|array $to, string $subject, string $html, string $alt = '', ?string $replyToEmail = null, ?string $replyToName = null): bool
    {
        global $SMTP, $APP_ENV;
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            if ($SMTP['host'] && $SMTP['user'] && $SMTP['pass']) {
                $mail->isSMTP();
                $mail->Host = $SMTP['host'];
                $mail->SMTPAuth = true;
                $mail->Username = $SMTP['user'];
                $mail->Password = $SMTP['pass'];
                $sec = strtolower((string) $SMTP['secure']);
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
                $mail->isMail();
            }
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($SMTP['from_email'] ?: $SMTP['user'], $SMTP['from_name'] ?: 'Aanmeldingen');
            if ($replyToEmail && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($replyToEmail, $replyToName ?: $replyToEmail);
            }
            foreach ((array) $to as $addr)
                if ($addr && filter_var($addr, FILTER_VALIDATE_EMAIL))
                    $mail->addAddress($addr);
            if (empty($mail->getToAddresses()))
                throw new \RuntimeException('Geen geldige ontvanger(s).');
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $alt ?: strip_tags($html);
            if (strtolower($APP_ENV) === 'development')
                $mail->SMTPDebug = 0;
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('Mail error: ' . $e->getMessage());
            return false;
        }
    }
}
if (!function_exists('send_admin_mail')) {
    function send_admin_mail(string $subject, string $html, string $alt = '', ?string $replyToEmail = null, ?string $replyToName = null): bool
    {
        return send_mail(admin_recipients(), $subject, $html, $alt, $replyToEmail, $replyToName);
    }
}
if (!function_exists('send_user_mail')) {
    function send_user_mail(string $toEmail, string $subject, string $html, string $alt = '', ?string $replyToEmail = null, ?string $replyToName = null): bool
    {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL))
            return false;
        return send_mail([$toEmail], $subject, $html, $alt, $replyToEmail, $replyToName);
    }
}