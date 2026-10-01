<?php
declare(strict_types=1);
require __DIR__ . '/digest.php';

// Aufruf per Kommandozeile (IONOS-Cronjob) oder per URL mit Schlüssel:
//   php api/cron.php
//   https://…/api/cron.php?key=…
if (PHP_SAPI !== 'cli') {
    $key = config()['cronKey'];
    if ($key === '' || !hash_equals($key, (string) ($_GET['key'] ?? ''))) {
        json_out(['error' => 'Nicht erlaubt.'], 403);
    }
}

$done = run_scheduled('cron');
$msg = implode('; ', array_map(fn($k, $v) => "$k: " . ($v ? implode(', ', $v) : '–'), array_keys($done), $done));
PHP_SAPI === 'cli' ? print($msg . "\n") : json_out(['ok' => true] + $done);
