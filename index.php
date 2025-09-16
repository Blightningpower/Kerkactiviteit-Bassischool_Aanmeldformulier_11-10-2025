<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';


$now = now();
$closed = $now > $DEADLINE; // na deadline dicht
$paidBus = bus_count_paid($pdo);
$busFull = $paidBus >= $BUS_CAPACITY;


?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Dagje Bobbejaanland – Aanmelden & Betalen</title>
<link rel="stylesheet" href="style.css" />
</head>
<body>
<main class="container">
<h1>Dagje Bobbejaanland – Aanmelden & Betalen</h1>


<section class="notice">
<p><strong>Zaterdag 11 oktober 2025</strong> vertrekken we om <strong>09:00</strong> vanaf de kerk en komen rond <strong>10:00</strong> aan bij het park. We reizen met een grote bus met maximaal <strong>40 kinderen</strong>. Het park sluit om <strong>17:00</strong>; we vertrekken direct daarna en zijn terug in Eindhoven rond <strong>18:00–18:30</strong>.</p>
<ul>
<li>Met bus + ticket: <strong>€45</strong>.</li>
<li>Eigen vervoer (meestal voor wie in België woont) – alleen ticket: <strong>€29</strong> (parkeren voor eigen rekening).</li>
<li>Groepen 1–4: met ouders mee. Groepen 5–8: mogen zonder ouders mee in groepjes met begeleiders.</li>
<li>Iedereen krijgt een hesje (verplicht dragen) en neemt eigen eten mee.</li>
<li>Aanmelden kan t/m <strong>1 oktober 2025</strong>.</li>
<li>Vragen of lukt betalen niet? Neem contact op met <strong>Gina Armanyous (0640746017)</strong>.</li>
</ul>
</section>


<section class="status">
<p><strong>Busplekken:</strong> <?= htmlspecialchars((string)min($paidBus, $BUS_CAPACITY)) ?> / <?= $BUS_CAPACITY ?> bezet <?= $busFull ? '— <span class="tag tag-red">Bus vol</span>' : '' ?></p>
<?php if ($closed): ?>
<p class="tag tag-red">Inschrijving gesloten (na 1 oktober 2025).</p>
<?php else: ?>
<?php if ($busFull): ?>
<p class="tag tag-amber">Bus vol — je kunt nog wel kiezen voor <strong>Eigen vervoer (€29)</strong> tot en met 1 oktober.</p>
<?php endif; ?>
<?php endif; ?>
</section>


<?php if ($closed): ?>
<p>De betaalopties zijn gesloten. Bedankt voor jullie interesse!</p>
<?php else: ?>
<form class="card" method="post" action="create_checkout.php">
<fieldset>
<legend>Gegevens deelnemer</legend>
<label>Naam kind <input type="text" name="child_name" required></label>
<label>Groep (1–8) <input type="text" name="group_class" pattern="[1-8]" required placeholder="1 t/m 8"></label>
<label>Telefoon ouder/begeleider <input type="tel" name="phone" required></label>
</fieldset>


<fieldset>
<legend>Kies je optie</legend>
<?php if (!$busFull): ?>
<label class="option">
<input type="radio" name="option" value="bus" required>
Met bus + ticket — <strong>€45</strong>
</label>
<?php else: ?>
<div class="muted">Met bus + ticket — €45 (bus is vol)</div>
<?php endif; ?>


<label class="option">
<input type="radio" name="option" value="zonder_bus" required <?= $busFull ? 'checked' : '' ?>>
Eigen vervoer (alleen ticket) — <strong>€29</strong>
</label>
</fieldset>


<label class="check">
<input type="checkbox" name="consent" value="1" required>
Ik ga akkoord met de voorwaarden hierboven en begrijp dat bij eigen vervoer parkeerkosten voor eigen rekening zijn.
</label>


<button type="submit" class="btn">Verder naar betalen</button>
</form>
<?php endif; ?>
</main>
</body>
</html>