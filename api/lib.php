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
    foreach ([$dir, "$dir/uploads", "$dir/confirmations"] as $d) {
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
    // Antwort sofort ausliefern, nachgelagerte Aufgaben laufen danach
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit;
}

/** Rückfallebene ohne Cronjob: Versandübersicht höchstens einmal pro Stunde bei Seitenaufrufen. */
function digest_on_shutdown(): void
{
    $f = storage_path('digest.json');
    if (is_file($f) && filemtime($f) > time() - 3600) {
        return;
    }
    register_shutdown_function(function () {
        require_once __DIR__ . '/digest.php';
        run_digest();
    });
}

/**
 * Liest und schreibt die Buchungsdatei unter exklusiver Sperre.
 * Abgelaufene Vormerkungen werden dabei jedes Mal ausgebucht.
 * Gibt $fn bei $write ein Array zurück, wird das Ergebnis gespeichert.
 */
function with_bookings(callable $fn, bool $write = false): mixed
{
    $fh = fopen(storage_path('bookings.json'), 'c+');
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $bookings = $raw ? json_decode($raw, true) : [];
    $expired = expire_bookings($bookings);
    $result = $fn($bookings);
    $save = $write && is_array($result) ? $result : ($expired ? $bookings : null);
    if ($save !== null) {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($save, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    foreach ($expired as $b) {
        notify_expired($b);
    }
    return $result;
}

function is_active(array $b): bool
{
    return in_array($b['status'], ['angefragt', 'bestaetigt'], true);
}

/** Setzt unbestätigte Anfragen nach Ablauf der Vormerkfrist auf "abgelaufen". */
function expire_bookings(array &$bookings): array
{
    $limit = new DateTimeImmutable('-' . (int) pricing()['holdDays'] . ' days');
    $expired = [];
    foreach ($bookings as &$b) {
        if ($b['status'] === 'angefragt' && new DateTimeImmutable($b['heldSince'] ?? $b['created']) < $limit) {
            $b['status'] = 'abgelaufen';
            $b['updated'] = date('c');
            $expired[] = $b;
        }
    }
    unset($b);
    return $expired;
}

function hold_until(array $b): string
{
    return (new DateTimeImmutable($b['heldSince'] ?? $b['created']))
        ->modify('+' . (int) pricing()['holdDays'] . ' days')->format('d.m.Y');
}

function notify_expired(array $b): void
{
    $c = config();
    $text = "Guten Tag {$b['customer']['name']},\n\nIhre Vormerkung {$b['id']} ist nach " . pricing()['holdDays'] .
        " Tagen ohne Bestätigung abgelaufen. Die Termine sind wieder freigegeben.\n\n" .
        "Wenn Sie weiterhin Interesse haben, antworten Sie einfach auf diese Mail oder stellen Sie eine neue Anfrage.\n\n" .
        booking_text($b) . "\n\nVielen Dank.\nIhr SINGLEWANDERN® Team";
    send_mail($b['customer']['email'], "Ihre Vormerkung {$b['id']} ist abgelaufen", $text, $c['bookingEmail']);
    send_mail($c['bookingEmail'], "Vormerkung abgelaufen: {$b['id']} {$b['customer']['company']}", booking_text($b));
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
        if (!is_active($b)) {
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

/** Monate einer Sidebar-Laufzeit als Y-m. */
function month_range(string $start, int $qty): array
{
    $d = new DateTimeImmutable($start . '-01');
    $out = [];
    for ($i = 0; $i < $qty; $i++) {
        $out[] = $d->modify("+$i months")->format('Y-m');
    }
    return $out;
}

/** Belegte Sidebar-Plätze je Monat. */
function sidebar_usage(array $bookings, ?string $exceptId = null): array
{
    $used = [];
    foreach ($bookings as $b) {
        if (!is_active($b) || $b['id'] === $exceptId) {
            continue;
        }
        foreach ($b['items'] as $it) {
            if ($it['key'] === 'sidebar') {
                foreach (month_range($it['start'], $it['qty']) as $m) {
                    $used[$m] = ($used[$m] ?? 0) + 1;
                }
            }
        }
    }
    return $used;
}

/** Konflikte einer Buchung mit dem Bestand: belegte Ausgaben und volle Sidebar-Monate. */
function booking_conflicts(array $booking, array $all): array
{
    $others = array_filter($all, fn($b) => $b['id'] !== $booking['id']);
    $taken = taken_dates($others);
    $used = sidebar_usage($others);
    $slots = pricing()['products']['sidebar']['slots'];
    $c = ['dates' => [], 'months' => []];
    foreach ($booking['items'] as $it) {
        foreach ($it['dates'] ?? [] as $d) {
            if (isset($taken[$d])) {
                $c['dates'][] = $d;
            }
        }
        if ($it['key'] === 'sidebar') {
            foreach (month_range($it['start'], $it['qty']) as $m) {
                if (($used[$m] ?? 0) >= $slots) {
                    $c['months'][] = $m;
                }
            }
        }
    }
    return $c;
}

function conflict_message(array $c): string
{
    $parts = [];
    if ($c['dates']) {
        $parts[] = 'Newsletter-Ausgaben bereits vergeben: ' . implode(', ', array_map('de_date', $c['dates']));
    }
    if ($c['months']) {
        $parts[] = 'Sidebar ausgebucht im ' . implode(', ', array_map('de_month', $c['months']));
    }
    return implode('. ', $parts) . '.';
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

/** Versendet eine Textmail, optional mit Anhängen [Dateiname => Inhalt]. */
function send_mail(string $to, string $subject, string $body, ?string $replyTo = null, array $attachments = []): bool
{
    $c = config();
    $headers = [
        'From: =?UTF-8?B?' . base64_encode('SINGLEWANDERN® Mediabuchung') . '?= <' . $c['mailFrom'] . '>',
        'MIME-Version: 1.0',
    ];
    if ($replyTo) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    if ($attachments) {
        $boundary = 'sw' . bin2hex(random_bytes(12));
        $headers[] = "Content-Type: multipart/mixed; boundary=\"$boundary\"";
        $parts = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" .
            chunk_split(base64_encode($body)) . "\r\n";
        foreach ($attachments as $name => $data) {
            $type = str_ends_with($name, '.pdf') ? 'application/pdf' : 'application/octet-stream';
            $parts .= "--$boundary\r\nContent-Type: $type; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n" .
                "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n" . chunk_split(base64_encode($data)) . "\r\n";
        }
        $body = $parts . "--$boundary--";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
    }
    $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    if ($c['mailToLog']) {
        $log = $attachments ? '[Anhänge: ' . implode(', ', array_keys($attachments)) . ']' : $body;
        file_put_contents(storage_path('mail.log'), "To: $to\n" . implode("\n", $headers) . "\nSubject: $subject\n\n$log\n-----\n", FILE_APPEND);
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

/**
 * Prüft einen hochgeladenen Banner.
 * Rückgabe: [] wenn keine Datei, ['error' => …] oder Metadaten für store_upload().
 */
function check_upload(?array $f, string $label): array
{
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
        return [];
    }
    $max = pricing()['uploadMaxBytes'];
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return ['error' => "Upload für $label fehlgeschlagen."];
    }
    if ($f['size'] > $max) {
        return ['error' => "Banner für $label ist größer als " . round($max / 1024) . ' KB.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $size = @getimagesize($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
    if (!$ext || !$size) {
        return ['error' => "Banner für $label muss JPG oder PNG sein."];
    }
    return ['tmp' => $f['tmp_name'], 'ext' => $ext, 'original' => mb_substr(basename($f['name']), 0, 120), 'w' => $size[0], 'h' => $size[1]];
}

function store_upload(string $bookingId, string $key, array $u): array
{
    $stored = $bookingId . '-' . $key . '-' . bin2hex(random_bytes(4)) . '.' . $u['ext'];
    move_uploaded_file($u['tmp'], storage_path('uploads/' . $stored));
    return ['stored' => $stored, 'original' => $u['original'], 'w' => $u['w'], 'h' => $u['h'], 'uploaded' => date('c')];
}

/** Persönlicher Link zum Nachreichen und Ersetzen der Banner. */
function upload_url(array $b): string
{
    return rtrim(config()['siteUrl'], '/') . '/banner/?b=' . rawurlencode($b['id']) . '&t=' . $b['uploadToken'];
}

function upload_hint(array $b): string
{
    if (!$b['items'] || empty($b['uploadToken'])) {
        return '';
    }
    $missing = array_filter($b['items'], fn($it) => empty($it['file']));
    return ($missing ? 'Banner nachreichen' : 'Banner ansehen oder ersetzen') . ":\n" . upload_url($b) . "\n\n";
}

