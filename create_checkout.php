<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';


// Basic validation
$childName = trim($_POST['child_name'] ?? '');
$groupClass = trim($_POST['group_class'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$option = $_POST['option'] ?? '';
$consent = isset($_POST['consent']);


if (!$consent || !$childName || !preg_match('/^[1-8]$/', $groupClass) || !$phone) {
http_response_code(400);
exit('Ongeldige invoer. Ga terug en controleer je gegevens.');
}


$now = now();
if ($now > $DEADLINE) {
exit('Inschrijving gesloten (na 1 oktober 2025).');
}


$paidBus = bus_count_paid($pdo);
$busFull = $paidBus >= $BUS_CAPACITY;


if ($option === 'bus' && $busFull) {
exit('De bus is inmiddels vol. Kies svp "Eigen vervoer (€29)".');
}


// Bepaal bedrag & omschrijving
if ($option === 'bus') {
$amount = $PRICE_WITH_BUS;
$title = 'Bobbejaanland – Met bus + ticket';
} elseif ($option === 'zonder_bus') {
$amount = $PRICE_WITHOUT_BUS;
$title = 'Bobbejaanland – Eigen vervoer (alleen ticket)';
} else {
http_response_code(400); exit('Ongeldige optie.');
}


try {
$session = \Stripe\Checkout\Session::create([
'mode' => 'payment',
'payment_method_types' => ['ideal','bancontact','card'],
'line_items' => [[
'price_data' => [
'currency' => 'eur',
'unit_amount' => $amount,
'product_data' => [
'name' => $title,
],
],
'quantity' => 1,
]],
'success_url' => base_url('success.php') . '?session_id={CHECKOUT_SESSION_ID}',
'cancel_url' => base_url('cancel.php'),
'metadata' => [
'option' => $option,
'child_name' => $childName,
'group_class' => $groupClass,
'phone' => $phone,
],
]);


// Optioneel: pre‑registratie als pending (we finaliseren pas via webhook bij betaling)
$stmt = $pdo->prepare('INSERT OR IGNORE INTO payments (session_id, status, option, amount, child_name, group_class, phone, created_at) VALUES (?,?,?,?,?,?,?,?)');
$stmt->execute([
$session->id,
'pending',
$option,
$amount,
$childName,
$groupClass,
$phone,
now()->format(DateTimeInterface::ATOM),
]);


header('Location: ' . $session->url);
exit;
} catch (Exception $e) {
http_response_code(500);
echo 'Fout bij aanmaken betaalpagina: ' . htmlspecialchars($e->getMessage());
}