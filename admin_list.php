<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';
if ($token !== ($ADMIN_TOKEN ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

$search = trim($_GET['q'] ?? '');
$sql = 'SELECT * FROM payments';
$params = [];
if ($search !== '') {
    $sql .= ' WHERE child_name LIKE ? OR phone LIKE ? OR contact_email LIKE ? OR session_id LIKE ?';
    $needle = '%' . $search . '%';
    $params = [$needle, $needle, $needle, $needle];
}
$sql .= ' ORDER BY id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$paidBus = bus_count_paid($pdo); // betaald=definitief; voor reserveringen kun je ook active_bus_count($pdo) tonen
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin – Aanmeldingen</title>
    <link rel="stylesheet" href="style.css">

    <!-- Favicons -->
    <link rel="apple-touch-icon" sizes="180x180"
        href="<?= htmlspecialchars(base_url('img/favicon_io/apple-touch-icon.png'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" type="image/png" sizes="32x32"
        href="<?= htmlspecialchars(base_url('img/favicon_io/favicon-32x32.png'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" type="image/png" sizes="16x16"
        href="<?= htmlspecialchars(base_url('img/favicon_io/favicon-16x16.png'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="manifest" href="<?= htmlspecialchars(base_url('site.webmanifest'), ENT_QUOTES, 'UTF-8') ?>">
    <meta name="theme-color" content="#0f172a">

    <style>
        table {
            width: 100%;
            border-collapse: collapse
        }

        th,
        td {
            padding: 8px;
            border-bottom: 1px solid rgba(255, 255, 255, .08)
        }

        .actions a {
            margin-right: 8px
        }

        .pill {
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 12px;
            display: inline-block
        }

        .paid {
            background: rgba(34, 197, 94, .15);
            color: #bbf7d0
        }

        .pending {
            background: rgba(245, 158, 11, .15);
            color: #fde68a
        }

        .cancelled {
            background: rgba(239, 68, 68, .15);
            color: #fecaca
        }

        .actions form {
            display: inline;
            margin-left: 6px
        }

        .actions input[type=url] {
            width: 260px;
            padding: 6px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, .15);
            background: #0b1220;
            color: #e5e7eb
        }
    </style>
</head>

<body>
    <main>
        <div class="card">
            <h2>Admin – Aanmeldingen</h2>

            <?php if (!empty($_GET['msg'])): ?>
                <p class="tag tag-amber"><?= htmlspecialchars((string) $_GET['msg'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <p><strong>Busplekken (betaald):</strong> <?= (int) $paidBus ?> / <?= (int) $BUS_CAPACITY ?></p>
            <!-- Wil je ook reserveringen tonen? Voeg ernaast toe: Active (paid + geldige pending): <?= (function_exists('active_bus_count') ? active_bus_count($pdo) : $paidBus); ?> -->

            <form method="get" style="margin-bottom:12px">
                <input type="hidden" name="token" value="<?= htmlspecialchars($ADMIN_TOKEN, ENT_QUOTES, 'UTF-8') ?>">
                <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Zoek op naam/telefoon/e-mail/ref"
                    style="width:60%;padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,.1);background:#0b1220;color:#e5e7eb">
                <button class="btn" type="submit">Zoeken</button>
                <a class="btn"
                    href="<?= htmlspecialchars(base_url('admin_list.php?token=' . urlencode($ADMIN_TOKEN)), ENT_QUOTES, 'UTF-8') ?>">Reset</a>
            </form>

            <div style="overflow:auto">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Status</th>
                            <th>Optie</th>
                            <th>Kind</th>
                            <th>Groep</th>
                            <th>Telefoon</th>
                            <th>E-mail</th>
                            <th>Bedrag</th>
                            <th>Ref</th>
                            <th>Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?= (int) $r['id'] ?></td>
                                <td><span
                                        class="pill <?= htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td><?= htmlspecialchars((string) $r['option'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $r['child_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $r['group_class'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $r['phone'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) ($r['contact_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>€<?= number_format(((int) $r['amount']) / 100, 2, ',', '.') ?></td>
                                <td><?= htmlspecialchars((string) $r['session_id'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="actions">
                                    <a class="btn" style="background:#22c55e"
                                        href="<?= htmlspecialchars(base_url('mark_paid.php?ref=' . urlencode((string) $r['session_id']) . '&action=confirm&token=' . urlencode($ADMIN_TOKEN)), ENT_QUOTES, 'UTF-8') ?>">
                                        Bevestig
                                    </a>
                                    <a class="btn" style="background:#ef4444"
                                        href="<?= htmlspecialchars(base_url('mark_paid.php?ref=' . urlencode((string) $r['session_id']) . '&action=cancel&token=' . urlencode($ADMIN_TOKEN)), ENT_QUOTES, 'UTF-8') ?>">
                                        Annuleer
                                    </a>
                                    <a class="btn" style="background:#334155"
                                        href="<?= htmlspecialchars(base_url('pay.php?ref=' . urlencode((string) $r['session_id'])), ENT_QUOTES, 'UTF-8') ?>">
                                        Instructies
                                    </a>

                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>

</html>