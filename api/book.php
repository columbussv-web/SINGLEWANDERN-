<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'Nur POST erlaubt.'], 405);
}

// Spamschutz: verstecktes Feld muss leer bleiben
if (($_POST['website'] ?? '') !== '') {
    json_out(['ok' => true, 'id' => 'SW-0']);
}

$P = pricing();
$in = fn(string $k, int $max = 500): string => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
$errors = [];

$customer = [];
foreach (['company' => 200, 'name' => 200, 'email' => 200, 'phone' => 60, 'address' => 500, 'vat_id' => 40, 'po' => 100] as $k => $max) {
    $customer[$k] = $in($k, $max);
}
foreach (['company' => 'Firma', 'name' => 'Ansprechperson', 'address' => 'Rechnungsanschrift'] as $k => $label) {
    if ($customer[$k] === '') {
        $errors[] = "$label fehlt.";
    }
}
if (!filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'E-Mail-Adresse ist ungültig.';
}
if (($_POST['consent'] ?? '') === '') {
    $errors[] = 'Einwilligung fehlt.';
}

$campaign = ['target_url' => $in('target_url', 500), 'utm' => $in('utm', 100), 'alt_text' => $in('alt_text', 200)];

// Werbeformen mit Festpreis, Preise rechnet ausschließlich der Server
$items = [];
$uploads = [];
$allowedDates = array_flip(newsletter_dates());

foreach ($P['products'] as $key => $p) {
    if (($_POST[$key . '_on'] ?? '') === '') {
        continue;
    }
    $format = $in($key . '_format', 100);
    if (!in_array($format, array_column($p['formats'], 'label'), true)) {
        $errors[] = "Ungültiges Format für {$p['short']}.";
        continue;
    }
    $item = ['key' => $key, 'name' => $p['name'], 'format' => $format];

    if ($key === 'newsletter') {
        $dates = array_values(array_unique(array_filter(explode(',', $in('newsletter_dates', 2000)))));
        sort($dates);
        foreach ($dates as $d) {
            if (!isset($allowedDates[$d])) {
                $errors[] = "Ausgabe " . (preg_match("/^\\d{4}-\\d{2}-\\d{2}$/", $d) ? de_date($d) : "") . " ist nicht buchbar.";
            }
        }
        if (!$dates) {
            $errors[] = 'Bitte mindestens eine Newsletter-Ausgabe wählen.';
        }
        $item['dates'] = $dates;
        $qty = count($dates);
        $item['notes'] = $in('newsletter_notes', 500);
    } else {
        $qty = (int) ($_POST[$key . '_qty'] ?? 0);
        $start = $in($key . '_start', 7);
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $start) || $start < date('Y-m')) {
            $errors[] = 'Startmonat ist ungültig.';
        }
        $item['start'] = $start;
    }
    if ($qty < 1 || $qty > $p['max']) {
        $errors[] = "Menge für {$p['short']} ist ungültig.";
        continue;
    }
    $item['qty'] = $qty;
    $item['unitPrice'] = unit_price($p, $qty);
    $item['net'] = $qty * $item['unitPrice'];

    // Banner prüfen, gespeichert wird erst nach der Belegungsprüfung
    $f = $_FILES[$key . '_file'] ?? null;
    if ($f && $f['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $errors[] = "Upload für {$p['short']} fehlgeschlagen.";
        } elseif ($f['size'] > $P['uploadMaxBytes']) {
            $errors[] = "Banner für {$p['short']} ist größer als " . round($P['uploadMaxBytes'] / 1024) . ' KB.';
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            $size = @getimagesize($f['tmp_name']);
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
            if (!$ext || !$size) {
                $errors[] = "Banner für {$p['short']} muss JPG oder PNG sein.";
            } else {
                $uploads[$key] = ['tmp' => $f['tmp_name'], 'ext' => $ext, 'original' => mb_substr(basename($f['name']), 0, 120), 'w' => $size[0], 'h' => $size[1]];
            }
        }
    }
    $items[] = $item;
}

if ($items && $campaign['target_url'] === '') {
    $errors[] = 'Ziel-URL fehlt.';
}
if ($campaign['target_url'] !== '' && !preg_match('#^https?://#i', $campaign['target_url'])) {
    $errors[] = 'Ziel-URL muss mit http:// oder https:// beginnen.';
}

// Werbeformen auf Anfrage
$requests = [];
foreach ($P['requestProducts'] as $key => $rp) {
    if (($_POST[$key . '_on'] ?? '') === '') {
        continue;
    }
    $fields = [];
    foreach ($rp['fields'] as $fd) {
        $fields[$fd['label']] = $in($key . '_' . $fd['key'], 2000);
    }
    $requests[] = ['key' => $key, 'name' => $rp['name'], 'fields' => $fields];
}

if (!$items && !$requests) {
    $errors[] = 'Bitte mindestens eine Werbeform wählen.';
}
if ($errors) {
    json_out(['error' => implode(' ', array_unique($errors))], 422);
}

$keys = array_column($items, 'key');
// in Cent rechnen, identisch zur Logik im Browser
$pct = fn(int $cents, float $rate): int => (int) round($cents * (int) round($rate * 100) / 100);
$sumC = array_sum(array_column($items, 'net')) * 100;
$comboC = (in_array('newsletter', $keys, true) && in_array('sidebar', $keys, true)) ? $pct($sumC, $P['comboDiscount']) : 0;
$netC = $sumC - $comboC;
$vatC = $pct($netC, $P['vatRate']);
$combo = $comboC / 100;
$net = $netC / 100;
$vat = $vatC / 100;

$booking = [
    'id' => 'SW-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2))),
    'created' => date('c'),
    'status' => 'angefragt',
    'items' => $items,
    'requests' => $requests,
    'comboDiscount' => $combo,
    'net' => $net,
    'vat' => $vat,
    'gross' => $net + $vat,
    'campaign' => $campaign,
    'customer' => $customer,
    'message' => $in('message', 3000),
];

// Belegung unter Sperre prüfen und Buchung speichern
$conflicts = [];
with_bookings(function (array $all) use (&$booking, &$conflicts, $uploads) {
    $taken = taken_dates($all);
    foreach ($booking['items'] as $it) {
        foreach ($it['dates'] ?? [] as $d) {
            if (isset($taken[$d])) {
                $conflicts[] = $d;
            }
        }
    }
    if ($conflicts) {
        return null;
    }
    foreach ($booking['items'] as &$it) {
        if (isset($uploads[$it['key']])) {
            $u = $uploads[$it['key']];
            $stored = $booking['id'] . '-' . $it['key'] . '-' . bin2hex(random_bytes(4)) . '.' . $u['ext'];
            move_uploaded_file($u['tmp'], storage_path('uploads/' . $stored));
            $it['file'] = ['stored' => $stored, 'original' => $u['original'], 'w' => $u['w'], 'h' => $u['h']];
        }
    }
    unset($it);
    $all[] = $booking;
    return $all;
}, true);

if ($conflicts) {
    json_out([
        'error' => 'Diese Ausgaben wurden gerade vergeben: ' . implode(', ', array_map('de_date', $conflicts)) . '. Bitte wählen Sie andere Termine.',
        'conflicts' => $conflicts,
    ], 409);
}

$text = booking_text($booking);
$c = config();
send_mail(
    $c['bookingEmail'],
    "Neue Buchungsanfrage {$booking['id']}: {$customer['company']}",
    $text . "\n\nVerwaltung: " . $c['adminUrl'],
    $customer['email']
);
if ($c['confirmCustomer']) {
    send_mail(
        $customer['email'],
        "Ihre Buchungsanfrage bei SINGLEWANDERN® ({$booking['id']})",
        "Guten Tag {$customer['name']},\n\nvielen Dank für Ihre Anfrage. Wir haben die gewählten Termine für Sie vorgemerkt und melden uns mit der Bestätigung.\n\n$text\n\nVielen Dank.\nIhr SINGLEWANDERN® Team",
        $c['bookingEmail']
    );
}

json_out(['ok' => true, 'id' => $booking['id']]);
