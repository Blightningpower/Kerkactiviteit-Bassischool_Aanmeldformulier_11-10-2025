<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$now = now();
$closed = $now > $DEADLINE;

$activeBus = active_bus_count($pdo);   // uit db.php
$busFull = $activeBus >= $BUS_CAPACITY;

?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Dagje Bobbejaanland – Aanmelden & Betalen</title>
    <link rel="stylesheet" href="style.css" />

    <!-- Favicons -->
    <link rel="apple-touch-icon" sizes="180x180"
        href="<?= htmlspecialchars(base_url('img/favicon_io/apple-touch-icon.png'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" type="image/png" sizes="32x32"
        href="<?= htmlspecialchars(base_url('img/favicon_io/favicon-32x32.png'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" type="image/png" sizes="16x16"
        href="<?= htmlspecialchars(base_url('img/favicon_io/favicon-16x16.png'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="manifest" href="<?= htmlspecialchars(base_url('site.webmanifest'), ENT_QUOTES, 'UTF-8') ?>">
    <meta name="theme-color" content="#0f172a">
</head>

<body>
    <main class="container">
        <h1>Dagje Bobbejaanland – Aanmelden & Betalen</h1>

        <section class="notice">
            <p><strong>Zaterdag 11 oktober 2025</strong> vertrekken we om <strong>09:00 uur</strong> vanaf de kerk en
                komen rond <strong>10:00 uur</strong> aan bij het park. We reizen met een grote bus met maximaal
                <strong>40 kinderen</strong>. Het park sluit om <strong>17:00 uur</strong>.
                <br>
                We vertrekken direct daarna en zijn terug in Eindhoven tussen <strong>18:00 – 18:30 uur</strong>.
            </p>
            <ul>
                <li>Met bus + ticket = <strong>€45</strong>.</li>
                <li>Eigen vervoer + alleen ticket = <strong>€29</strong> (parkeren voor eigen rekening
                    <strong>€13</strong>).
                </li>
                <li>Groepen 1–4 moeten met ouders mee. Groepen 5–8: mogen zonder ouders mee in
                    groepjes met begeleiders.</li>
                <li>Iedereen krijgt een hesje (verplicht dragen) en neemt eigen eten mee.</li>
                <li>Aanmelden kan t/m <strong>1 oktober 2025</strong>.</li>
                <li>Vragen of lukt betalen niet? Neem contact op met <strong>Gina Armanyous (0640746017)</strong>.</li>
            </ul>
        </section>

        <section class="status">
            <p><strong>Busplekken:</strong>
                <?= htmlspecialchars((string) min($activeBus, $BUS_CAPACITY)) ?> / <?= $BUS_CAPACITY ?> bezet
                <?= $busFull ? '— <span class="tag tag-red">Bus vol</span>' : '' ?>
            </p>
            <?php if ($closed): ?>
                <p class="tag tag-red">Inschrijving gesloten (na 1 oktober 2025).</p>
            <?php else: ?>
                <?php if ($busFull): ?>
                    <p class="tag tag-amber">Bus vol — je kunt nog wel kiezen voor <strong>Eigen vervoer (€29)</strong> tot en
                        met 1 oktober.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <?php if ($closed): ?>
            <p>De betaalopties zijn gesloten. Bedankt voor jullie interesse!</p>
        <?php else: ?>
            <form class="card" method="post" action="create_bank.php" id="signupForm">
                <fieldset>
                    <legend>Gegevens deelnemer</legend>
                    <label>Naam kind <input type="text" name="child_name" required></label>
                    <label>Groep (1–8)
                        <input type="number" name="group_class" min="1" max="8" required>
                    </label>
                    <label>Telefoon deelnemer of contact <input type="tel" name="phone" required></label>
                    <label>E-mail<input type="email" name="contact_email" required></label>
                </fieldset>

                <fieldset>
                    <legend>Ouder/begeleider</legend>
                    <label class="check">
                        <input type="checkbox" name="parent_accompany" id="parent_accompany" value="1">
                        Ouder/begeleider gaat mee
                    </label>
                    <div id="parentFields" style="display:none; margin-top:8px">
                        <label>Naam ouder/begeleider <input type="text" name="parent_name"></label>
                        <label>Telefoon ouder/begeleider <input type="tel" name="parent_phone"></label>
                        <label>E-mail ouder/begeleider <input type="email" name="parent_email"></label>
                    </div>
                    <p class="muted" id="parentHint" style="display:none">Voor <strong>groepen 1–4</strong> is het meenemen
                        van een ouder verplicht.</p>
                </fieldset>

                <fieldset>
                    <legend>Kies je optie</legend>
                    <?php if (!$busFull): ?>
                        <label class="option"><input type="radio" name="option" value="bus" required> Met bus + ticket —
                            <strong>€45</strong></label>
                    <?php else: ?>
                        <div class="muted">Met bus + ticket — €45 (bus is vol)</div>
                    <?php endif; ?>
                    <label class="option"><input type="radio" name="option" value="zonder_bus" required <?= $busFull ? 'checked' : '' ?>> Eigen vervoer (alleen ticket) — <strong>€29</strong></label>
                </fieldset>

                <label class="check">
                    <input type="checkbox" name="consent" value="1" required>
                    Ik ga akkoord met de voorwaarden hierboven en begrijp dat bij eigen vervoer parkeerkosten voor eigen
                    rekening zijn.
                </label>

                <button type="submit" class="btn" style="cursor:pointer;">Verder naar betalen</button>
            </form>
        <?php endif; ?>
    </main>

    <script>
        (function () {
            const groupEl = document.querySelector('input[name="group_class"]');
            const cb = document.getElementById('parent_accompany');
            const fields = document.getElementById('parentFields');
            const hint = document.getElementById('parentHint');
            const req = [document.querySelector('input[name="parent_name"]'), document.querySelector('input[name="parent_phone"]'), document.querySelector('input[name="parent_email"]')];
            function needsParent() { const v = parseInt(groupEl.value || '0', 10); return !isNaN(v) && v >= 1 && v <= 4; }
            function update() {
                const forced = needsParent();
                if (forced) { cb.checked = true; cb.disabled = true; hint.style.display = 'block'; } else { cb.disabled = false; hint.style.display = 'none'; }
                const show = cb.checked || forced; fields.style.display = show ? 'block' : 'none';
                req.forEach(el => show ? el.setAttribute('required', 'required') : el.removeAttribute('required'));
            }
            groupEl.addEventListener('input', update); cb.addEventListener('change', update); update();
        })();
    </script>
</body>

</html>