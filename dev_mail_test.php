<?php
require __DIR__ . '/config.php';
$ok = send_user_mail($_ENV['SMTP_USER'] ?? '', 'Testmail', '<p>Hallo, dit is een test.</p>', 'Hallo, dit is een test.');
var_dump($ok ? 'MAIL VERSTUURD' : 'MAIL MISLUKT (check error_log)');