<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';


$payload = @file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';


try {
$event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $WEBHOOK_SECRET);
} catch (Exception $e) {
http_response_code(400);
echo 'Webhook signature verification failed.';
exit;
}


if ($event->type === 'checkout.session.completed') {
/** @var \Stripe\Checkout\Session $session */
$session = $event->data->object;


// Controleer betalingstatus (zekerheid)
if (($session->payment_status ?? '') === 'paid') {
$option = $session->metadata->option ?? 'onbekend';


// Capaciteit safeguards (zeldzaam race‑condition): als bus vol raakt, markeer als paid maar je kunt handmatig opvolgen
if ($option === 'bus') {
$current = bus_count_paid($pdo);
if ($current >= $BUS_CAPACITY) {
// Bus is al vol – markeer als paid (waitlist) of handel handmatig (refund via Stripe Dashboard)
$option = 'bus_waitlist';
}
}


$stmt = $pdo->prepare('UPDATE payments SET status=?, option=? WHERE session_id=?');
$stmt->execute(['paid', $option, $session->id]);
}
}


http_response_code(200);