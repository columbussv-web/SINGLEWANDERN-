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
        $latest = (new DateTimeImmutable('first day of this month'))->modify('+' . $p['horizonMonths'] . ' months')->format('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $start) || $start < date('Y-m') || $start > $latest) {
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
    $u = check_upload($_FILES[$key . '_file'] ?? null, $p['short']);
    if (isset($u['error'])) {
        $errors[] = $u['error'];
    } elseif ($u) {
        $uploads[$key] = $u;
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
    'uploadToken' => bin2hex(random_bytes(16)),
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
$conflicts = ['dates' => [], 'months' => []];
with_bookings(function (array $all) use (&$booking, &$conflicts, $uploads) {
    $conflicts = booking_conflicts($booking, $all);
    if ($conflicts['dates'] || $conflicts['months']) {
        return null;
    }
    foreach ($booking['items'] as &$it) {
        if (isset($uploads[$it['key']])) {
            $it['file'] = store_upload($booking['id'], $it['key'], $uploads[$it['key']]);
        }
    }
    unset($it);
    $all[] = $booking;
    return $all;
}, true);

if ($conflicts['dates'] || $conflicts['months']) {
    json_out([
        'error' => conflict_message($conflicts) . ' Bitte wählen Sie andere Termine.',
        'conflicts' => $conflicts['dates'],
        'months' => $conflicts['months'],
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
        "Guten Tag {$customer['name']},\n\nvielen Dank für Ihre Anfrage. Wir haben die gewählten Termine bis zum " . hold_until($booking) . " für Sie vorgemerkt und melden uns vorher mit der Auftragsbestätigung.\n\n$text\n\n" . upload_hint($booking) . "Vielen Dank.\nIhr SINGLEWANDERN® Team",
        $c['bookingEmail']
    );
}

json_out(['ok' => true, 'id' => $booking['id'], 'holdUntil' => hold_until($booking)]);
