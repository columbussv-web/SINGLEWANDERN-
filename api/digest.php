<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

/** Ziel-URL mit UTM-Parametern, falls eine Kampagne angegeben ist. */
function tracking_url(array $b, string $medium): string
{
    $url = $b['campaign']['target_url'];
    if ($url === '' || $b['campaign']['utm'] === '') {
        return $url;
    }
    $q = http_build_query(['utm_source' => 'singlewandern', 'utm_medium' => $medium, 'utm_campaign' => $b['campaign']['utm']]);
    return $url . (str_contains($url, '?') ? '&' : '?') . $q;
}

/** Newsletter-Ausgaben der nächsten Tage mit gebuchtem Partner (oder null). */
function upcoming_issues(array $bookings, int $count): array
{
    $byDate = [];
    foreach ($bookings as $b) {
        if (!is_active($b)) {
            continue;
        }
        foreach ($b['items'] as $it) {
            foreach ($it['dates'] ?? [] as $d) {
                $byDate[$d] = ['booking' => $b, 'item' => $it];
            }
        }
    }
    $out = [];
    $p = pricing()['products']['newsletter'];
    for ($x = new DateTimeImmutable('today'); count($out) < $count; $x = $x->modify('+1 day')) {
        if (in_array((int) $x->format('w'), $p['weekdays'], true)) {
            $d = $x->format('Y-m-d');
            $out[] = ['date' => $d] + ($byDate[$d] ?? ['booking' => null, 'item' => null]);
        }
    }
    return $out;
}

function digest_text(string $date, array $b, array $it): string
{
    $o = ['Newsletter-Ausgabe ' . de_date($date), ''];
    $o[] = 'Partner: ' . $b['customer']['company'] . " ({$b['id']})";
    $o[] = 'Status: ' . ($b['status'] === 'bestaetigt' ? 'bestätigt' : 'NUR ANGEFRAGT, noch nicht bestätigt (vorgemerkt bis ' . hold_until($b) . ')');
    $o[] = 'Format: ' . $it['format'];
    $o[] = 'Banner: ' . (!empty($it['file']) ? "{$it['file']['original']} ({$it['file']['w']} × {$it['file']['h']} px), im Anhang" : 'FEHLT');
    $o[] = 'Link im Banner: ' . (tracking_url($b, 'newsletter') ?: '–');
    $o[] = 'ALT-Text: ' . ($b['campaign']['alt_text'] ?: '–');
    if (!empty($it['notes'])) {
        $o[] = 'Hinweise des Kunden: ' . $it['notes'];
    }
    $n = array_search($date, $it['dates'], true);
    $o[] = 'Schaltung ' . ($n + 1) . ' von ' . count($it['dates']);
    $o[] = '';
    $o[] = 'Kontakt: ' . $b['customer']['name'] . ', ' . $b['customer']['email'] . ($b['customer']['phone'] ? ', ' . $b['customer']['phone'] : '');
    if (empty($it['file']) && !empty($b['uploadToken'])) {
        $o[] = 'Upload-Link für den Kunden: ' . upload_url($b);
    }
    $o[] = '';
    $o[] = 'Verwaltung: ' . config()['adminUrl'];
    return implode("\n", $o);
}

/**
 * Versendet die Übersicht für jede gebuchte Ausgabe, die in den nächsten
 * digestDaysBefore Tagen ansteht. Jede Ausgabe wird genau einmal gemeldet.
 * Gibt die gemeldeten Daten zurück.
 */
function run_digest(): array
{
    $c = config();
    $fh = fopen(storage_path('digest.json'), 'c+');
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return []; // läuft bereits
    }
    $raw = stream_get_contents($fh);
    $sent = $raw ? json_decode($raw, true) : [];
    $bookings = with_bookings(fn(array $b) => $b);
    $limit = (new DateTimeImmutable('today'))->modify('+' . (int) $c['digestDaysBefore'] . ' days')->format('Y-m-d');
    $done = [];

    foreach (upcoming_issues($bookings, 8) as $i) {
        if ($i['date'] > $limit || !$i['booking'] || isset($sent[$i['date']])) {
            continue;
        }
        $b = $i['booking'];
        $it = $i['item'];
        $att = [];
        if (!empty($it['file']) && is_file(storage_path('uploads/' . $it['file']['stored']))) {
            $att[$it['file']['stored']] = file_get_contents(storage_path('uploads/' . $it['file']['stored']));
        }
        $flags = [];
        if (empty($it['file'])) $flags[] = 'Banner fehlt';
        if ($b['status'] !== 'bestaetigt') $flags[] = 'nicht bestätigt';
        $subject = 'Newsletter ' . de_date($i['date']) . ': ' . $b['customer']['company'] . ($flags ? ' – ' . implode(', ', $flags) : ' – bereit');
        if (send_mail($c['bookingEmail'], $subject, digest_text($i['date'], $b, $it), $b['customer']['email'], $att)) {
            $sent[$i['date']] = ['id' => $b['id'], 'sent' => date('c')];
            $done[] = $i['date'];
        }
    }

    // Einträge älter als 60 Tage verwerfen
    $cut = (new DateTimeImmutable('-60 days'))->format('Y-m-d');
    $sent = array_filter($sent, fn($k) => $k >= $cut, ARRAY_FILTER_USE_KEY);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($sent, JSON_PRETTY_PRINT));
    flock($fh, LOCK_UN);
    fclose($fh);
    return $done;
}
