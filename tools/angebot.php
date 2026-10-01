<?php
declare(strict_types=1);
// Einseitiges Angebot als PDF, Preise aus assets/pricing.json
//   php tools/angebot.php tools/angebot-wikinger.json angebot.pdf
require __DIR__ . '/../api/mediadaten.php';

[$_, $specFile, $outFile] = $argv + [null, null, null];
if (!$specFile || !$outFile) {
    fwrite(STDERR, "Aufruf: php tools/angebot.php angebot.json ausgabe.pdf\n");
    exit(1);
}
$spec = json_decode(file_get_contents($specFile), true, 16, JSON_THROW_ON_ERROR);
$P = pricing();
$m = $P['media'];
$co = config()['company'];

/** Paketpreis in Cent, gleiche Logik wie Buchungsseite und Server. */
function quote(int $nl, int $sb): array
{
    $P = pricing();
    $pct = fn(int $c, float $r): int => (int) round($c * (int) round($r * 100) / 100);
    $nlU = $nl ? unit_price($P['products']['newsletter'], $nl) : 0;
    $sbU = $sb ? unit_price($P['products']['sidebar'], $sb) : 0;
    $sumC = ($nl * $nlU + $sb * $sbU) * 100;
    $comboC = ($nl && $sb) ? $pct($sumC, $P['comboDiscount']) : 0;
    $netC = $sumC - $comboC;
    $vatC = $pct($netC, $P['vatRate']);
    $nlNetC = ($nl && $sb) ? $nl * $nlU * 100 - $pct($nl * $nlU * 100, $P['comboDiscount']) : $nl * $nlU * 100;
    $opens = (int) round($nl * $P['media']['subscribers'] * $P['media']['openRate'] / 100);
    return ['nl' => $nl, 'sb' => $sb, 'nlU' => $nlU, 'sbU' => $sbU, 'sum' => $sumC / 100, 'combo' => $comboC / 100,
        'net' => $netC / 100, 'vat' => $vatC / 100, 'gross' => ($netC + $vatC) / 100, 'opens' => $opens,
        'tkp' => $opens ? $nlNetC / 100 / $opens * 1000 : 0];
}

$green = [0.184, 0.42, 0.247];
$soft = [0.875, 0.918, 0.875];
$muted = [0.36, 0.4, 0.365];
$L = 48.0;
$R = SimplePdf::W - 48;
$W = $R - $L;
$pdf = new SimplePdf();
$pdf->addPage();

$para = function (string $s, float $y, float $size = 9.5, array $rgb = [0, 0, 0], ?float $w = null, ?float $x = null) use ($pdf, $L, $W): float {
    foreach ($pdf->wrap($s, $w ?? $W, $size) as $l) {
        $pdf->text($x ?? $L, $y, $l, $size, false, $rgb);
        $y += $size * 1.45;
    }
    return $y;
};

// Kopf
$pdf->rect(0, 0, SimplePdf::W, 74, $green);
$pdf->text($L, 44, 'SINGLEWANDERN®', 20, true, [1, 1, 1]);
$pdf->text($R, 44, 'Angebot ' . $spec['number'], 10, true, [1, 1, 1], 'R');
$pdf->text($R, 58, date('d.m.Y') . ' · gültig bis ' . $spec['validUntil'], 8.5, false, [1, 1, 1], 'R');

$y = 104;
foreach ($spec['recipient'] as $l) {
    $pdf->text($L, $y, $l, 10);
    $y += 13;
}
$y += 14;
$pdf->text($L, $y, $spec['subject'], 13, true);
$pdf->text($L, $y + 18, $spec['salutation'], 9.5);
$y = $para($spec['intro'], $y + 36, 9.5);
$y += 6;

// Kennzahlen in einer Zeile
$pdf->rect($L, $y, $W, 24, $soft);
$kp = implode('   ·   ', ['ca. ' . number_format($m['subscribers'], 0, ',', '.') . ' Abonnenten', $m['sendsPerWeek'] . ' Versände pro Woche', 'bis ' . $m['openRate'] . ' % Öffnungsrate', '1 Partner pro Ausgabe']);
$pdf->text($L + $W / 2, $y + 15.5, $kp, 8.5, false, [0, 0, 0], 'C');
$y += 42;

// Pakete nebeneinander
$n = count($spec['packages']);
$gap = 14;
$bw = ($W - ($n - 1) * $gap) / $n;
$top = $y;
$bottom = $y;
foreach ($spec['packages'] as $i => $pk) {
    $q = quote($pk['newsletter'], $pk['sidebar']);
    $x = $L + $i * ($bw + $gap);
    $cy = $top;
    $hl = !empty($pk['recommended']);
    $pdf->rect($x, $cy, $bw, 22, $hl ? $green : $soft);
    $pdf->text($x + 10, $cy + 15, $pk['name'] . ($hl ? ' · Empfehlung' : ''), 10, true, $hl ? [1, 1, 1] : [0, 0, 0]);
    $cy += 38;
    $rows = [];
    if ($q['nl']) $rows[] = ["{$q['nl']} × Newsletter-Banner à " . eur($q['nlU']), eur($q['nl'] * $q['nlU'])];
    if ($q['sb']) $rows[] = ["{$q['sb']} Monate Sidebar-Banner à " . eur($q['sbU']), eur($q['sb'] * $q['sbU'])];
    if ($q['combo']) $rows[] = ['Kombirabatt ' . round($P['comboDiscount'] * 100) . ' %', '– ' . eur($q['combo'])];
    $rows[] = ['Summe netto', eur($q['net'])];
    $rows[] = ['zzgl. ' . round($P['vatRate'] * 100) . ' % MwSt.', eur($q['vat'])];
    foreach ($rows as $k => [$a, $b]) {
        $isNet = $a === 'Summe netto';
        if ($isNet) {
            $pdf->line($x, $cy - 10, $x + $bw, $cy - 10, [0.3, 0.3, 0.3], 0.6);
        }
        $pdf->text($x + 2, $cy, $a, 8.8, $isNet);
        $pdf->text($x + $bw - 2, $cy, $b, 8.8, $isNet, [0, 0, 0], 'R');
        $cy += 14;
    }
    $cy += 6;
    $pdf->text($x + 2, $cy, 'Newsletter-Öffnungen bis zu', 8.5, false, $muted);
    $pdf->text($x + $bw - 2, $cy, number_format($q['opens'], 0, ',', '.'), 8.5, true, $green, 'R');
    $cy += 13;
    $pdf->text($x + 2, $cy, 'Preis pro 1.000 Öffnungen', 8.5, false, $muted);
    $pdf->text($x + $bw - 2, $cy, eur($q['tkp']), 8.5, true, $green, 'R');
    $cy += 13;
    if (!empty($pk['note'])) {
        $cy = $para($pk['note'], $cy + 4, 8.3, $muted, $bw - 4, $x + 2);
    }
    $bottom = max($bottom, $cy);
}
$y = $bottom + 16;

// Zusätze
foreach ($spec['extras'] as $ex) {
    $pdf->rect($L, $y - 11, 3, 14, $green);
    $pdf->text($L + 10, $y, $ex['title'], 10, true);
    $pdf->text($R, $y, $ex['price'], 8.5, false, $muted, 'R');
    $y = $para($ex['text'], $y + 14, 9, [0, 0, 0], $W - 10, $L + 10) + 8;
}

// Nächste Schritte
$y += 4;
$pdf->text($L, $y, 'So geht es weiter', 10.5, true);
$y += 16;
foreach ($spec['nextSteps'] as $k => $s) {
    $pdf->rect($L, $y - 10, 14, 14, $green);
    $pdf->text($L + 7, $y, (string) ($k + 1), 8.5, true, [1, 1, 1], 'C');
    $y = $para($s, $y, 9, [0, 0, 0], $W - 24, $L + 24) + 4;
}
$y += 6;
$para('Alle Preise netto zzgl. gesetzlicher MwSt. Termine nach Verfügbarkeit, jede Newsletter-Ausgabe hat einen exklusiven Werbeplatz.', $y, 8, $muted);

// Fuß
$pdf->line($L, SimplePdf::H - 46, $R, SimplePdf::H - 46);
$pdf->text($L, SimplePdf::H - 32, implode(' · ', array_filter([$co['name'], ...$co['lines'], $co['email'], $co['web']])), 7.5, false, $muted);

if ($y > SimplePdf::H - 60) {
    fwrite(STDERR, "Warnung: Inhalt reicht in den Fußbereich, Texte kürzen.\n");
}
file_put_contents($outFile, $pdf->output(['Title' => $spec['subject'], 'Author' => 'SINGLEWANDERN®']));
echo $outFile, "\n";
