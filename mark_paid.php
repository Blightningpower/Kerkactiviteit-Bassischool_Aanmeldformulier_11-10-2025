<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$token  = $_GET['token']  ?? '';
$ref    = $_GET['ref']    ?? '';
$action = $_GET['action'] ?? 'confirm'; // confirm | cancel

if ($token !== ($ADMIN_TOKEN ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

$stmt = $pdo->prepare('SELECT * FROM payments WHERE session_id = ?');
$stmt->execute([$ref]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    exit('Onbekende referentie.');
}

$status = (string)($row['status']       ?? 'pending');
$option = (string)($row['option']       ?? 'zonder_bus');
$child  = (string)($row['child_name']   ?? '');
$email  = (string)($row['contact_email'] ?? ''); // let op: kolomnaam is contact_email

// Handige redirect helper met melding
$back = function (string $msg) use ($ADMIN_TOKEN): never {
    header('Location: ' . base_url('admin_list.php?token=' . urlencode($ADMIN_TOKEN) . '&msg=' . urlencode($msg)));
    exit;
};

if ($action === 'confirm') {

    // Idempotent: als al betaald, niet nogmaals schrijven
    if ($status === 'paid') {
        $back('Deze aanmelding was al bevestigd.');
    }

    // Capaciteit bewaken met file-lock om race-conditions te vermijden
    with_lock('mark_paid_bus', function () use ($pdo, $ref, $option, $back, $child, $email) {
        // Haal globale instellingen binnen (lost VS Code warning op)
        global $BUS_CAPACITY, $TIMEZONE;

        // Als het om de bus gaat: check betaalde plekken
        if ($option === 'bus') {
            $paid = bus_count_paid($pdo);
            if ($paid >= $BUS_CAPACITY) {
                $back('Bus zit vol — niet bevestigd.');
            }
        }

        // Markeer als betaald
        $pdo->prepare('UPDATE payments SET status = ? WHERE session_id = ?')->execute(['paid', $ref]);

        // Mail naar deelnemer/ouder
        if ($email) {
            $html = '<p>Beste ouder/deelnemer,</p>'
                  . '<p>De aanmelding voor <strong>' . htmlspecialchars($child) . '</strong> is '
                  . '<strong>bevestigd</strong>. Tot bij de activiteit!</p>';
            send_user_mail($email, 'Aanmelding bevestigd – Bobbejaanland', $html, strip_tags($html));
        }
    });

    $back('Aanmelding bevestigd.');

} elseif ($action === 'cancel') {

    // Idempotent: als al geannuleerd, niet nogmaals schrijven
    if ($status === 'cancelled') {
        $back('Deze aanmelding was al geannuleerd.');
    }

    $pdo->prepare('UPDATE payments SET status = ? WHERE session_id = ?')->execute(['cancelled', $ref]);

    if ($email) {
        $html = '<p>Beste ouder/deelnemer,</p>'
              . '<p>De aanmelding voor <strong>' . htmlspecialchars($child) . '</strong> is '
              . '<strong>geannuleerd</strong>.</p>';
        send_user_mail($email, 'Aanmelding geannuleerd – Bobbejaanland', $html, strip_tags($html));
    }

    $back('Aanmelding geannuleerd.');

} else {
    http_response_code(400);
    exit('Ongeldige actie.');
}