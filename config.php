<?php
// config.php


require __DIR__ . '/vendor/autoload.php';


// Load .env
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
if (str_starts_with(trim($line), '#')) continue;
[$k, $v] = array_map('trim', explode('=', $line, 2));
$_ENV[$k] = $v;
}
}


// Settings
$TIMEZONE = $_ENV['APP_TIMEZONE'] ?? 'Europe/Amsterdam';
$BASE_URL = rtrim($_ENV['APP_BASE_URL'] ?? '', '/');
$APP_ENV = $_ENV['APP_ENV'] ?? 'development';
$STRIPE_SECRET = $_ENV['STRIPE_SECRET_KEY'] ?? '';
$WEBHOOK_SECRET = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';


// Event gegevens & regels
$BUS_CAPACITY = 40; // max busplekken
$DEADLINE = new DateTimeImmutable('2025-10-01 23:59:59', new DateTimeZone($TIMEZONE)); // tot en met 1 okt 2025


// Prijzen (in eurocent)
$PRICE_WITH_BUS = 4500; // €45,00
$PRICE_WITHOUT_BUS = 2900; // €29,00


// Stripe init
\Stripe\Stripe::setApiKey($STRIPE_SECRET);


// Helpers
function base_url(string $path = ''): string {
global $BASE_URL;
return $BASE_URL . ($path ? '/' . ltrim($path, '/') : '');
}


function now(): DateTimeImmutable {
global $TIMEZONE; return new DateTimeImmutable('now', new DateTimeZone($TIMEZONE));
}


function app_env(string $env): bool { global $APP_ENV; return strtolower($APP_ENV) === strtolower($env); }