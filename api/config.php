<?php
// Standardwerte. Eigene Werte gehören in api/config.local.php (nicht im Repository).
$config = [
    // Empfänger der Buchungsanfragen
    'bookingEmail' => 'werbung@singlewandern.de',
    // Absenderadresse, muss auf IONOS zur eigenen Domain gehören
    'mailFrom' => 'noreply@singlewandern.de',
    // Bestätigungsmail an den Kunden senden
    'confirmCustomer' => true,
    // Admin-Passwort als Hash: php -r 'echo password_hash("geheim", PASSWORD_DEFAULT), "\n";'
    // Leer = Adminbereich gesperrt
    'adminPasswordHash' => '',
    // Ablage für Buchungen und Banner, per .htaccess vor Webzugriff geschützt
    'storageDir' => __DIR__ . '/../storage',
    'pricingFile' => __DIR__ . '/../assets/pricing.json',
    // Absolute URL zum Adminbereich für den Link in der Benachrichtigung
    'adminUrl' => 'https://www.singlewandern.de/werbung/admin/',
    // Absender auf der PDF-Auftragsbestätigung
    'company' => [
        'name' => 'SINGLEWANDERN®',
        'lines' => [],          // z. B. ['Musterstraße 1', '12345 Musterstadt']
        'email' => 'werbung@singlewandern.de',
        'web' => 'www.singlewandern.de',
        'vatId' => '',
    ],
    'paymentTerms' => 'Die Rechnung erhalten Sie gesondert.',
    // Mails nicht versenden, sondern in storage/mail.log schreiben (Test)
    'mailToLog' => false,
];

if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_merge($config, require __DIR__ . '/config.local.php');
}

return $config;
