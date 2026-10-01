<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/pdf.php';

/** Kennzahlen für Web und PDF, aus pricing.json abgeleitet. */
function media_kpis(): array
{
    $m = pricing()['media'];
    return [
        ['ca. ' . number_format($m['subscribers'], 0, ',', '.'), 'Newsletter-Abonnentinnen und -Abonnenten'],
        [$m['sendsPerWeek'] . ' × pro Woche', 'Newsletter-Versand'],
        ['bis ' . $m['openRate'] . ' %', 'Öffnungsrate'],
        ['1 Partner', 'exklusiver Werbeplatz pro Ausgabe'],
    ];
}

/** Erzeugt die Mediadaten als PDF. */
function mediadaten_pdf(): string
{
    $P = pricing();
    $m = $P['media'];
    $co = config()['company'];
    $green = [0.184, 0.42, 0.247];
    $soft = [0.875, 0.918, 0.875];
    $muted = [0.36, 0.4, 0.365];
    $L = 48.0;
    $R = SimplePdf::W - 48;
    $W = $R - $L;
    $pdf = new SimplePdf();

    $footer = function () use ($pdf, $co, $L, $R, $muted, $m) {
        $pdf->line($L, SimplePdf::H - 46, $R, SimplePdf::H - 46);
        $pdf->text($L, SimplePdf::H - 32, implode(' · ', array_filter([$co['name'], $co['email'], $co['web']])), 7.5, false, $muted);
        $pdf->text($R, SimplePdf::H - 32, 'Mediadaten ' . $m['edition'] . ' · Seite ' . $pdf->pageCount(), 7.5, false, $muted, 'R');
    };
    $para = function (string $s, float $y, float $size = 10, array $rgb = [0, 0, 0], ?float $w = null, float $x = 0) use ($pdf, $L, $W): float {
        foreach ($pdf->wrap($s, $w ?? $W, $size) as $l) {
            $pdf->text($x ?: $L, $y, $l, $size, false, $rgb);
            $y += $size * 1.45;
        }
        return $y;
    };

    // Seite 1
    $pdf->addPage();
    $pdf->rect(0, 0, SimplePdf::W, 118, $green);
    $pdf->text($L, 62, 'SINGLEWANDERN®', 26, true, [1, 1, 1]);
    $pdf->text($L, 88, 'Mediadaten ' . $m['edition'] . ' · Werben in einer aktiven, naturverbundenen Community', 11, false, [1, 1, 1]);
    $footer();

    $y = $para($m['profile'], 152, 10.5);

    // Kennzahlen
    $y += 12;
    $bw = ($W - 3 * 10) / 4;
    foreach (media_kpis() as $i => [$val, $lab]) {
        $x = $L + $i * ($bw + 10);
        $pdf->rect($x, $y, $bw, 66, $soft);
        $pdf->text($x + 12, $y + 26, $val, 15, true, $green);
        $ly = $y + 42;
        foreach ($pdf->wrap($lab, $bw - 24, 8) as $l) {
            $pdf->text($x + 12, $ly, $l, 8, false, $muted);
            $ly += 10.5;
        }
    }
    $y += 66 + 26;

    $pdf->text($L, $y, 'Zielgruppe', 12, true);
    $y = $para(implode(' · ', $m['audience']), $y + 17, 10, $green);
    $y = $para($m['credibility'] . ' Ideal für ' . $m['idealFor'], $y + 2, 10);
    $y += 18;

    // Werbeformen
    $pdf->text($L, $y, 'Werbeformen und Preise', 12, true);
    $y += 22;
    $cw = ($W - 20) / 2;
    $bullets = [
        'newsletter' => ['Exklusiver Werbeplatz pro Ausgabe', 'Verlinkung auf Ihre Zielseite', 'Versand dienstags und sonntags'],
        'sidebar' => ['Sichtbar auf Buchungs- und Informationsseiten', 'Konstante Präsenz in der Community', 'Max. ' . $P['products']['sidebar']['slots'] . ' Partner pro Monat'],
    ];
    $colEnd = $y;
    $i = 0;
    foreach ($P['products'] as $key => $p) {
        $x = $L + $i++ * ($cw + 20);
        $cy = $y;
        $pdf->text($x, $cy, $p['name'], 10.5, true);
        $cy += 18;
        $pdf->text($x, $cy, eur($p['basePrice']), 16, true, $green);
        $pdf->text($x + $pdf->width(eur($p['basePrice']), 16, true) + 6, $cy, 'pro ' . $p['unit'], 9, false, $muted);
        $cy += 18;
        foreach ($bullets[$key] as $bl) {
            $pdf->text($x, $cy, '· ' . $bl, 8.5, false, $muted);
            $cy += 12;
        }
        $cy += 6;
        $pdf->rect($x, $cy - 11, $cw, 16, $soft);
        $pdf->text($x + 6, $cy, 'Staffel', 8, true, $muted);
        $pdf->text($x + $cw - 6, $cy, 'Preis pro ' . $p['unit'], 8, true, $muted, 'R');
        $cy += 18;
        $tiers = $p['tiers'];
        usort($tiers, fn($a, $b) => $a['min'] <=> $b['min']);
        foreach ($tiers as $t) {
            $pdf->text($x + 6, $cy, $t['min'] === 1 ? '1 ' . $p['unit'] : 'ab ' . $t['min'] . ' ' . $p['tierLabel'], 9);
            $pdf->text($x + $cw - 6, $cy, eur($t['price']), 9, false, [0, 0, 0], 'R');
            $pdf->line($x, $cy + 5, $x + $cw, $cy + 5, [0.88, 0.88, 0.86], 0.4);
            $cy += 16;
        }
        $colEnd = max($colEnd, $cy);
    }
    $y = $colEnd + 8;
    $pdf->rect($L, $y, $W, 26, $soft);
    $pdf->rect($L, $y, 3, 26, $green);
    $pdf->text($L + 12, $y + 17, 'Kombirabatt: ' . round($P['comboDiscount'] * 100) . ' % zusätzlich auf den Staffelpreis bei Buchung von Newsletter- und Sidebar-Banner.', 9.5, true);
    $y += 44;

    foreach ($P['requestProducts'] as $r) {
        $pdf->text($L, $y, $r['name'], 10, true);
        $pdf->text($R, $y, 'Preis auf Anfrage', 9, false, $muted, 'R');
        $y = $para($r['description'], $y + 14, 9, $muted) + 6;
    }

    // Seite 2
    $pdf->addPage();
    $footer();
    $y = 64;
    $pdf->text($L, $y, 'Technische Angaben', 12, true);
    $y += 24;
    $i = 0;
    $rowY = $y;
    $rowEnd = $y;
    foreach ($m['specs'] as $title => $lines) {
        $x = $L + ($i % 2) * ($cw + 20);
        if ($i % 2 === 0 && $i > 0) {
            $rowY = $rowEnd + 16;
        }
        $cy = $rowY;
        $pdf->text($x, $cy, $title, 10, true, $green);
        $cy += 16;
        foreach ($lines as $l) {
            foreach ($pdf->wrap('· ' . $l, $cw, 9) as $wl) {
                $pdf->text($x, $cy, $wl, 9);
                $cy += 13;
            }
        }
        $rowEnd = max($rowEnd, $cy);
        $i++;
    }
    $y = $rowEnd + 24;

    $pdf->text($L, $y, 'So buchen Sie', 12, true);
    $y += 18;
    $steps = [
        'Online unter ' . preg_replace('#^https?://#', '', rtrim(config()['siteUrl'], '/')) . ': Termine wählen, Preis sofort sehen, Banner direkt hochladen.',
        'Ihre Termine sind ' . $P['holdDays'] . ' Tage für Sie vorgemerkt. Mit unserer Auftragsbestätigung als PDF wird die Buchung verbindlich.',
        'Vor dem Start erinnern wir an fehlende Banner. Nach der Kampagne erhalten Sie einen Bericht mit allen Versanddaten.',
    ];
    foreach ($steps as $n => $s) {
        $pdf->rect($L, $y - 11, 16, 16, $green);
        $pdf->text($L + 8, $y, (string) ($n + 1), 9, true, [1, 1, 1], 'C');
        $y = $para($s, $y, 9.5, [0, 0, 0], $W - 28, $L + 28) + 6;
    }
    $y += 14;
    $pdf->text($L, $y, 'Kontakt', 12, true);
    $y += 18;
    foreach (array_filter([$co['name'], ...$co['lines'], $co['email'], $co['web']]) as $l) {
        $pdf->text($L, $y, $l, 10);
        $y += 14;
    }
    $y += 10;
    $pdf->text($L, $y, 'Alle Preise netto zzgl. gesetzlicher MwSt. Stand: ' . date('d.m.Y') . '.', 8.5, false, $muted);

    return $pdf->output(['Title' => 'SINGLEWANDERN® Mediadaten ' . $m['edition'], 'Author' => 'SINGLEWANDERN®']);
}
