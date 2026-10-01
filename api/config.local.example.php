<?php
// Vorlage: als config.local.php speichern und Werte eintragen.
// Diese Datei überschreibt die Standardwerte aus config.php.
return [
    'bookingEmail' => 'werbung@singlewandern.de',
    'mailFrom' => 'noreply@singlewandern.de',
    // php -r 'echo password_hash("IhrPasswort", PASSWORD_DEFAULT), "\n";'
    'adminPasswordHash' => '',
    // php -r 'echo bin2hex(random_bytes(16)), "\n";'
    'cronKey' => '',
    'siteUrl' => 'https://www.singlewandern.de/werbung/',
    'adminUrl' => 'https://www.singlewandern.de/werbung/admin/',
    'company' => [
        'name' => 'SINGLEWANDERN®',
        'lines' => ['Straße Nr.', 'PLZ Ort'],
        'email' => 'werbung@singlewandern.de',
        'web' => 'www.singlewandern.de',
        'vatId' => '',
    ],
    'paymentTerms' => 'Die Rechnung erhalten Sie gesondert.',
    // Empfohlen: Ablage außerhalb des Webroots, z. B. __DIR__ . '/../../werbung-daten'
    // 'storageDir' => __DIR__ . '/../../werbung-daten',
];
