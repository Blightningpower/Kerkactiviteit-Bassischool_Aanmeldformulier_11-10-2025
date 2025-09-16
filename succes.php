<?php
require_once __DIR__ . '/config.php';
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Betaling gelukt</title>
<link rel="stylesheet" href="styles.css" />
</head>
<body>
<main class="container">
<div class="card">
<h2>Bedankt! 🎉</h2>
<p>Je betaling is gelukt. Je ontvangt een bevestiging per mail vanuit Stripe. Tot bij de activiteit!</p>
<p><a class="btn" href="<?= htmlspecialchars(base_url('index.php')) ?>">Terug naar aanmeldpagina</a></p>
</div>
</main>
</body>
</html>