<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/pdf.php';

/** Erzeugt die Auftragsbestätigung als PDF. */
function confirmation_pdf(array $b): string
{
    $co = config()['company'];
    $green = [0.184, 0.42, 0.247];
    $muted = [0.36, 0.4, 0.365];
    $L = 56.0;
    $R = SimplePdf::W - 56;
    $bottom = SimplePdf::H - 70;

    $pdf = new SimplePdf();
    $y = 0.0;

    $footer = function () use ($pdf, $co, $L, $R, $muted) {
        $parts = array_filter([$co['name'], ...$co['lines'], $co['email'], $co['web'], $co['vatId'] ? 'USt-IdNr. ' . $co['vatId'] : '']);
        $pdf->line($L, SimplePdf::H - 50, $R, SimplePdf::H - 50);
        $pdf->text($L, SimplePdf::H - 36, implode(' · ', $parts), 7.5, false, $muted);
        $pdf->text($R, SimplePdf::H - 36, 'Seite ' . $pdf->pageCount(), 7.5, false, $muted, 'R');
    };
    $newPage = function () use ($pdf, &$y, $footer, $b, $L, $R, $muted) {
        $pdf->addPage();
        $footer();
        if ($pdf->pageCount() > 1) {
            $pdf->text($L, 50, 'Auftragsbestätigung ' . $b['id'] . ' (Fortsetzung)', 9, false, $muted);
            $y = 80;
        }
    };
    $need = function (float $h) use (&$y, $bottom, $newPage) {
        if ($y + $h > $bottom) {
            $newPage();
        }
    };

    // Kopf
    $newPage();
    $pdf->text($L, 70, 'SINGLEWANDERN®', 20, true, $green);
    $pdf->text($L, 86, 'Mediabuchung', 9, false, $muted);
    $ry = 62;
    foreach (array_filter([...$co['lines'], $co['email'], $co['web']]) as $l) {
        $pdf->text($R, $ry, $l, 8.5, false, $muted, 'R');
        $ry += 12;
    }

    // Empfänger
    $y = 150;
    $k = $b['customer'];
    $addr = array_merge([$k['company'], $k['name']], array_map('trim', preg_split('/\R|,/u', $k['address'])));
    foreach (array_filter($addr) as $l) {
        $pdf->text($L, $y, $l, 10);
        $y += 14;
    }

    // Metadaten rechts
    $meta = [
        'Auftrag' => $b['id'],
        'Datum' => (new DateTimeImmutable($b['confirmedAt'] ?? 'now'))->format('d.m.Y'),
        'Anfrage vom' => (new DateTimeImmutable($b['created']))->format('d.m.Y'),
    ];
    if ($k['po'] !== '') $meta['Ihre Referenz'] = $k['po'];
    if ($k['vat_id'] !== '') $meta['Ihre USt-IdNr.'] = $k['vat_id'];
    $my = 150;
    foreach ($meta as $lab => $v) {
        $pdf->text($R - 120, $my, $lab, 9, false, $muted, 'R');
        $pdf->text($R, $my, $v, 9, true, [0, 0, 0], 'R');
        $my += 14;
    }

    $y = max($y, $my) + 30;
    $pdf->text($L, $y, 'Auftragsbestätigung', 16, true);
    $y += 24;
    foreach ($pdf->wrap("Guten Tag {$k['name']},\nvielen Dank für Ihren Auftrag. Wir bestätigen die folgenden Werbeschaltungen bei SINGLEWANDERN®.", $R - $L, 10) as $l) {
        $pdf->text($L, $y, $l, 10);
        $y += 14;
    }
    $y += 12;

    // Tabelle
    $cx = ['pos' => $L, 'desc' => $L + 28, 'qty' => $R - 170, 'unit' => $R - 75, 'sum' => $R];
    $descW = $cx['qty'] - $cx['desc'] - 40;
    $head = function () use ($pdf, &$y, $cx, $L, $R, $muted) {
        $pdf->rect($L, $y - 12, $R - $L, 18, [0.93, 0.94, 0.9]);
        $pdf->text($cx['pos'] + 4, $y, 'Pos.', 8.5, true, $muted);
        $pdf->text($cx['desc'], $y, 'Leistung', 8.5, true, $muted);
        $pdf->text($cx['qty'], $y, 'Menge', 8.5, true, $muted, 'R');
        $pdf->text($cx['unit'], $y, 'Einzelpreis', 8.5, true, $muted, 'R');
        $pdf->text($cx['sum'] - 4, $y, 'Gesamt', 8.5, true, $muted, 'R');
        $y += 22;
    };
    $head();

    $pos = 0;
    foreach ($b['items'] as $it) {
        $pos++;
        $details = ["Format: {$it['format']}"];
        if (!empty($it['dates'])) {
            // geschütztes Leerzeichen hält Wochentag und Datum beim Umbruch zusammen
            $details[] = 'Ausgaben: ' . implode(', ', array_map(fn($d) => str_replace(' ', "\u{00A0}", de_date($d)), $it['dates']));
        }
        if (!empty($it['notes'])) {
            $details[] = 'Ihre Hinweise: ' . $it['notes'];
        }
        if (!empty($it['start'])) {
            $details[] = 'Laufzeit: ' . de_month($it['start']) . ($it['qty'] > 1 ? ' bis ' . de_month($it['start'], $it['qty'] - 1) : '');
        }
        $details[] = 'Banner: ' . (!empty($it['file']) ? 'liegt vor (' . $it['file']['w'] . ' × ' . $it['file']['h'] . ' px)' : 'bitte noch zusenden');
        $lines = [];
        foreach ($details as $d) {
            array_push($lines, ...$pdf->wrap($d, $descW, 8.5));
        }
        $unit = pricing()['products'][$it['key']];
        $need(16 + count($lines) * 11.5);
        $pdf->text($cx['pos'] + 4, $y, (string) $pos, 10);
        $pdf->text($cx['desc'], $y, $it['name'], 10, true);
        $pdf->text($cx['qty'], $y, $it['qty'] . ' ' . ($it['qty'] === 1 ? $unit['unit'] : $unit['unitPlural']), 10, false, [0, 0, 0], 'R');
        $pdf->text($cx['unit'], $y, eur($it['unitPrice']), 10, false, [0, 0, 0], 'R');
        $pdf->text($cx['sum'] - 4, $y, eur($it['net']), 10, false, [0, 0, 0], 'R');
        $y += 14;
        foreach ($lines as $l) {
            $need(12);
            $pdf->text($cx['desc'], $y, $l, 8.5, false, $muted);
            $y += 11.5;
        }
        $y += 6;
        $pdf->line($L, $y - 4, $R, $y - 4);
        $y += 8;
    }

    // Summen
    $need(90);
    $rows = [];
    if ($b['comboDiscount'] > 0) {
        $rows[] = ['Zwischensumme', eur($b['net'] + $b['comboDiscount']), false];
        $rows[] = ['Kombirabatt ' . round(pricing()['comboDiscount'] * 100) . ' %', '– ' . eur($b['comboDiscount']), false];
    }
    $rows[] = ['Summe netto', eur($b['net']), false];
    $rows[] = ['zzgl. ' . round(pricing()['vatRate'] * 100) . ' % MwSt.', eur($b['vat']), false];
    $rows[] = ['Gesamtbetrag', eur($b['gross']), true];
    foreach ($rows as [$lab, $val, $bold]) {
        if ($bold) {
            $pdf->line($R - 220, $y - 10, $R, $y - 10, [0.2, 0.2, 0.2], 0.8);
            $y += 4;
        }
        $pdf->text($cx['unit'], $y, $lab, $bold ? 11 : 10, $bold, [0, 0, 0], 'R');
        $pdf->text($cx['sum'] - 4, $y, $val, $bold ? 11 : 10, $bold, [0, 0, 0], 'R');
        $y += 16;
    }
    $y += 14;

    // Abschnitte
    $section = function (string $title, array $paras) use ($pdf, &$y, $need, $L, $R) {
        $lines = [];
        foreach ($paras as $p) {
            array_push($lines, ...$pdf->wrap($p, $R - $L, 9.5));
        }
        $need(20 + min(3, count($lines)) * 13);
        $pdf->text($L, $y, $title, 10.5, true);
        $y += 15;
        foreach ($lines as $l) {
            $need(13);
            $pdf->text($L, $y, $l, 9.5);
            $y += 13;
        }
        $y += 10;
    };

    if ($b['requests']) {
        $section('Weitere Anfragen', array_map(fn($r) => $r['name'] . ': Wir senden Ihnen hierzu ein gesondertes Angebot.', $b['requests']));
    }
    $c = $b['campaign'];
    $camp = ['Ziel-URL: ' . ($c['target_url'] ?: '–')];
    if ($c['utm']) $camp[] = 'UTM-Kampagne: ' . $c['utm'];
    if ($c['alt_text']) $camp[] = 'ALT-Text: ' . $c['alt_text'];
    $section('Kampagne', $camp);

    $missing = array_filter($b['items'], fn($it) => empty($it['file']));
    $hints = [];
    if ($missing) {
        $hints[] = 'Bitte laden Sie die fehlenden Banner rechtzeitig vor dem ersten Termin über Ihren persönlichen Link hoch: ' . upload_url($b);
    } elseif (!empty($b['uploadToken'])) {
        $hints[] = 'Banner ansehen oder ersetzen: ' . upload_url($b);
    }
    $hints[] = 'Technische Vorgaben: JPG oder PNG, max. 150 KB, 72 dpi, keine Schrift kleiner als 18 px im Bild.';
    $hints[] = config()['paymentTerms'];
    $section('Hinweise', array_filter($hints));

    $need(40);
    $pdf->text($L, $y, 'Wir freuen uns auf die Zusammenarbeit.', 10);
    $y += 14;
    $pdf->text($L, $y, 'Ihr SINGLEWANDERN® Team', 10);

    return $pdf->output(['Title' => 'Auftragsbestätigung ' . $b['id'], 'Author' => 'SINGLEWANDERN®']);
}

/** Erzeugt, speichert und versendet die Auftragsbestätigung. Gibt Erfolg zurück. */
function send_confirmation(array $b): bool
{
    $c = config();
    $pdf = confirmation_pdf($b);
    $file = 'Auftragsbestaetigung-' . $b['id'] . '.pdf';
    file_put_contents(storage_path('confirmations/' . $b['id'] . '.pdf'), $pdf);

    $text = "Guten Tag {$b['customer']['name']},\n\nvielen Dank für Ihren Auftrag. Anbei erhalten Sie die Auftragsbestätigung {$b['id']} als PDF.\n\n" .
        booking_text($b) . "\n\n" . upload_hint($b) . "Bei Fragen antworten Sie einfach auf diese Mail.\n\nVielen Dank.\nIhr SINGLEWANDERN® Team";
    $ok = send_mail($b['customer']['email'], "Auftragsbestätigung {$b['id']} – SINGLEWANDERN®", $text, $c['bookingEmail'], [$file => $pdf]);
    send_mail($c['bookingEmail'], "Kopie: Auftragsbestätigung {$b['id']} an {$b['customer']['company']}", $text, null, [$file => $pdf]);
    return $ok;
}
