<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');

function config(): array
{
    static $c;
    return $c ??= require __DIR__ . '/config.php';
}

function pricing(): array
{
    static $p;
    return $p ??= json_decode(file_get_contents(config()['pricingFile']), true, 32, JSON_THROW_ON_ERROR);
}

function storage_path(string $rel = ''): string
{
    $dir = rtrim(config()['storageDir'], '/');
    foreach ([$dir, "$dir/uploads"] as $d) {
        if (!is_dir($d)) {
            mkdir($d, 0750, true);
        }
    }
    return $rel === '' ? $dir : "$dir/$rel";
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Liest und schreibt die Buchungsdatei unter exklusiver Sperre.
 * Gibt $fn ein Array zurück, wird das Ergebnis gespeichert.
 */
function with_bookings(callable $fn, bool $write = false): mixed
{
    $fh = fopen(storage_path('bookings.json'), 'c+');
    flock($fh, $write ? LOCK_EX : LOCK_SH);
    $raw = stream_get_contents($fh);
    $bookings = $raw ? json_decode($raw, true) : [];
    $result = $fn($bookings);
    if ($write && is_array($result)) {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

/** Alle buchbaren Newsletter-Ausgaben im Buchungsfenster. */
function newsletter_dates(): array
{
    $p = pricing()['products']['newsletter'];
    $d = new DateTimeImmutable('today');
    $from = $d->modify('+' . $p['leadDays'] . ' days');
    $to = $d->modify('+' . $p['horizonDays'] . ' days');
    $out = [];
    for ($x = $from; $x <= $to; $x = $x->modify('+1 day')) {
        if (in_array((int) $x->format('w'), $p['weekdays'], true)) {
            $out[] = $x->format('Y-m-d');
        }
    }
    return $out;
}

function taken_dates(array $bookings): array
{
    $taken = [];
    foreach ($bookings as $b) {
        if ($b['status'] === 'storniert') {
            continue;
        }
        foreach ($b['items'] as $it) {
            foreach ($it['dates'] ?? [] as $d) {
                $taken[$d] = $b['status'];
            }
        }
    }
    return $taken;
}

function unit_price(array $product, int $qty): int
{
    foreach ($product['tiers'] as $t) {
        if ($qty >= $t['min']) {
            return $t['price'];
        }
    }
    return $product['basePrice'];
}

function eur(float $v): string
{
    return number_format($v, 2, ',', '.') . ' €';
}

function de_date(string $iso): string
{
    $wd = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    $d = new DateTimeImmutable($iso);
    return $wd[(int) $d->format('w')] . ' ' . $d->format('d.m.Y');
}

function de_month(string $ym, int $add = 0): string
{
    $m = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    $d = (new DateTimeImmutable($ym . '-01'))->modify("+$add months");
    return $m[(int) $d->format('n') - 1] . ' ' . $d->format('Y');
}

function send_mail(string $to, string $subject, string $body, ?string $replyTo = null): bool
{
    $c = config();
    $headers = [
        'From: =?UTF-8?B?' . base64_encode('SINGLEWANDERN® Mediabuchung') . '?= <' . $c['mailFrom'] . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    if ($replyTo) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    if ($c['mailToLog']) {
        file_put_contents(storage_path('mail.log'), "To: $to\n" . implode("\n", $headers) . "\nSubject: $subject\n\n$body\n-----\n", FILE_APPEND);
        return true;
    }
    return mail($to, $subject, $body, implode("\r\n", $headers));
}

/** Klartext-Zusammenfassung einer Buchung für Mails und Admin. */
function booking_text(array $b): string
{
    $o = ["Anfrage {$b['id']} vom " . (new DateTimeImmutable($b['created']))->format('d.m.Y H:i'), ''];
    foreach ($b['items'] as $it) {
        $o[] = $it['name'];
        $o[] = "  {$it['qty']} × " . eur($it['unitPrice']) . ' = ' . eur($it['net']) . ' netto';
        $o[] = "  Format: {$it['format']}";
        if (!empty($it['dates'])) {
            $o[] = '  Ausgaben: ' . implode(', ', array_map('de_date', $it['dates']));
        }
        if (!empty($it['notes'])) {
            $o[] = "  Hinweise: {$it['notes']}";
        }
        if (!empty($it['start'])) {
            $o[] = '  Laufzeit: ' . de_month($it['start']) . ($it['qty'] > 1 ? ' bis ' . de_month($it['start'], $it['qty'] - 1) : '');
        }
        $o[] = '  Banner: ' . (!empty($it['file']) ? "{$it['file']['original']} ({$it['file']['w']} × {$it['file']['h']} px)" : 'wird nachgereicht');
        $o[] = '';
    }
    foreach ($b['requests'] as $r) {
        $o[] = $r['name'] . ' (Preis auf Anfrage)';
        foreach ($r['fields'] as $label => $v) {
            if ($v !== '') {
                $o[] = "  $label: $v";
            }
        }
        $o[] = '';
    }
    if ($b['items']) {
        if ($b['comboDiscount'] > 0) {
            $o[] = 'Kombirabatt: − ' . eur($b['comboDiscount']);
        }
        $o[] = 'Summe netto: ' . eur($b['net']);
        $o[] = 'zzgl. MwSt.: ' . eur($b['vat']);
        $o[] = 'Gesamt brutto: ' . eur($b['gross']);
        $o[] = '';
    }
    $c = $b['campaign'];
    $o[] = 'Kampagne';
    $o[] = '  Ziel-URL: ' . ($c['target_url'] ?: '–');
    if ($c['utm']) $o[] = "  UTM: {$c['utm']}";
    if ($c['alt_text']) $o[] = "  ALT-Text: {$c['alt_text']}";
    $o[] = '';
    $k = $b['customer'];
    $o[] = 'Kunde';
    $prefix = ['vat_id' => 'USt-IdNr.: ', 'po' => 'Referenz: '];
    foreach (['company', 'name', 'email', 'phone', 'address', 'vat_id', 'po'] as $f) {
        if ($k[$f] !== '') {
            $o[] = '  ' . ($prefix[$f] ?? '') . str_replace("\n", ', ', $k[$f]);
        }
    }
    if ($b['message'] !== '') {
        $o[] = '';
        $o[] = 'Nachricht';
        $o[] = $b['message'];
    }
    return implode("\n", $o);
}
