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

/** Erster Termin einer Werbeform als Y-m-d. */
function item_first_day(array $it): string
{
    return !empty($it['dates']) ? $it['dates'][0] : $it['start'] . '-01';
}

/** Letzter Tag der gesamten Kampagne als Y-m-d. */
function booking_end(array $b): string
{
    $end = '';
    foreach ($b['items'] as $it) {
        $last = !empty($it['dates'])
            ? $it['dates'][count($it['dates']) - 1]
            : (new DateTimeImmutable($it['start'] . '-01'))->modify('+' . ($it['qty'] - 1) . ' months')->format('Y-m-t');
        $end = max($end, $last);
    }
    return $end;
}

/** Erinnert Kunden an fehlende Banner, einmal pro Werbeform. */
function run_reminders(): array
{
    $c = config();
    $limit = (new DateTimeImmutable('today'))->modify('+' . (int) $c['reminderDaysBefore'] . ' days')->format('Y-m-d');
    $today = date('Y-m-d');
    $fresh = date('c', time() - 86400);
    $due = [];
    with_bookings(function (array $all) use ($limit, $today, $fresh, &$due) {
        foreach ($all as &$b) {
            // frische Buchungen haben den Link gerade erst mit der Eingangsmail erhalten
            if (!is_active($b) || empty($b['uploadToken']) || $b['created'] > $fresh) {
                continue;
            }
            $missing = [];
            foreach ($b['items'] as &$it) {
                $first = item_first_day($it);
                if (empty($it['file']) && empty($it['reminded']) && $first <= $limit && $first >= $today) {
                    $it['reminded'] = date('c');
                    $missing[] = $it;
                }
            }
            unset($it);
            if ($missing) {
                $due[] = [$b, $missing];
            }
        }
        unset($b);
        return $due ? $all : null;
    }, true);

    foreach ($due as [$b, $missing]) {
        $list = implode("\n", array_map(fn($it) => "- {$it['name']}, Format {$it['format']}, erster Termin " .
            (!empty($it['dates']) ? de_date($it['dates'][0]) : de_month($it['start'])), $missing));
        send_mail(
            $b['customer']['email'],
            "Erinnerung: Banner für Ihre Buchung {$b['id']} fehlt noch",
            "Guten Tag {$b['customer']['name']},\n\nfür Ihre Kampagne bei SINGLEWANDERN® fehlt uns noch folgendes Banner:\n$list\n\n" .
            "Bitte laden Sie es über Ihren persönlichen Link hoch:\n" . upload_url($b) . "\n\n" .
            "Vorgaben: JPG oder PNG, max. " . round(pricing()['uploadMaxBytes'] / 1024) . " KB, keine Schrift kleiner als 18 px im Bild.\n\nVielen Dank.\nIhr SINGLEWANDERN® Team",
            config()['bookingEmail']
        );
    }
    return array_map(fn($x) => $x[0]['id'], $due);
}

function report_text(array $b): string
{
    $o = ["Guten Tag {$b['customer']['name']},", '', "Ihre Kampagne {$b['id']} bei SINGLEWANDERN® ist abgeschlossen. Hier die Übersicht:", ''];
    foreach ($b['items'] as $it) {
        $o[] = $it['name'] . " ({$it['format']})";
        if (!empty($it['dates'])) {
            $o[] = '  ' . count($it['dates']) . ' Newsletter-Versände an ca. 5.000 Abonnentinnen und Abonnenten:';
            foreach ($it['dates'] as $d) {
                $o[] = '  - ' . de_date($d);
            }
        }
        if (!empty($it['start'])) {
            $o[] = '  Sichtbar auf singlewandern.de: ' . de_month($it['start']) . ($it['qty'] > 1 ? ' bis ' . de_month($it['start'], $it['qty'] - 1) : '');
        }
        $o[] = '';
    }
    if (!empty($b['reportNotes'])) {
        $o[] = 'Kennzahlen';
        $o[] = $b['reportNotes'];
        $o[] = '';
    }
    $o[] = 'Verlinkte Zielseite: ' . ($b['campaign']['target_url'] ?: '–');
    if ($b['campaign']['utm'] !== '') {
        $o[] = "Klicks und Conversions finden Sie in Ihrem Analytics-Tool unter utm_source=singlewandern und utm_campaign={$b['campaign']['utm']}.";
    } else {
        $o[] = 'Tipp für die nächste Kampagne: Mit einer UTM-Kennung können Sie die Klicks aus unserem Newsletter in Ihrem Analytics-Tool genau zuordnen.';
    }
    $o[] = '';
    $o[] = 'Wir freuen uns, wenn wir Ihre nächste Kampagne begleiten dürfen. Freie Termine finden Sie jederzeit unter ' . config()['siteUrl'];
    $o[] = '';
    $o[] = 'Vielen Dank.';
    $o[] = 'Ihr SINGLEWANDERN® Team';
    return implode("\n", $o);
}

/** Sendet den Abschlussbericht für bestätigte Kampagnen nach Ablauf der Wartezeit. */
function send_report(array $b): bool
{
    $c = config();
    $ok = send_mail($b['customer']['email'], "Ihr Kampagnenbericht {$b['id']} – SINGLEWANDERN®", report_text($b), $c['bookingEmail']);
    send_mail($c['bookingEmail'], "Kopie: Kampagnenbericht {$b['id']} an {$b['customer']['company']}", report_text($b));
    return $ok;
}

function run_reports(): array
{
    $cut = (new DateTimeImmutable('today'))->modify('-' . (int) config()['reportDaysAfter'] . ' days')->format('Y-m-d');
    $due = [];
    with_bookings(function (array $all) use ($cut, &$due) {
        foreach ($all as &$b) {
            if ($b['status'] === 'bestaetigt' && $b['items'] && empty($b['reportSent']) && booking_end($b) <= $cut) {
                $b['reportSent'] = date('c');
                $due[] = $b;
            }
        }
        unset($b);
        return $due ? $all : null;
    }, true);
    foreach ($due as $b) {
        send_report($b);
    }
    return array_column($due, 'id');
}

/** Alle zeitgesteuerten Aufgaben. $source: cron oder page. */
function run_scheduled(string $source): array
{
    $result = ['uebersicht' => run_digest(), 'erinnerung' => run_reminders(), 'bericht' => run_reports()];
    $f = storage_path('scheduler.json');
    $state = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $state[$source] = date('c');
    file_put_contents($f, json_encode($state), LOCK_EX);
    return $result;
}
