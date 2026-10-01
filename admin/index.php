<?php
declare(strict_types=1);
require __DIR__ . '/../api/confirmation.php';
require __DIR__ . '/../api/digest.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

$hash = config()['adminPasswordHash'];
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$csrfOk = fn() => hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''));

if ($hash === '') {
    http_response_code(503);
    exit('Adminbereich ist nicht eingerichtet. Bitte adminPasswordHash in api/config.local.php setzen.');
}

if (isset($_POST['password'])) {
    if ($csrfOk() && password_verify((string) $_POST['password'], $hash)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
    } else {
        usleep(800000);
        $loginError = 'Passwort falsch.';
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ./');
    exit;
}

$authed = !empty($_SESSION['admin']);

// Banner-Download
if ($authed && isset($_GET['file'])) {
    $name = basename((string) $_GET['file']);
    $path = storage_path('uploads/' . $name);
    if (!preg_match('/^SW-[\w-]+\.(jpg|png)$/', $name) || !is_file($path)) {
        http_response_code(404);
        exit('Datei nicht gefunden.');
    }
    header('Content-Type: ' . (str_ends_with($name, '.png') ? 'image/png' : 'image/jpeg'));
    header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
    readfile($path);
    exit;
}

// Auftragsbestätigung als PDF ansehen
if ($authed && isset($_GET['pdf'])) {
    $id = (string) $_GET['pdf'];
    $b = with_bookings(fn(array $all) => array_values(array_filter($all, fn($x) => $x['id'] === $id))[0] ?? null);
    if (!$b) {
        http_response_code(404);
        exit('Buchung nicht gefunden.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Auftragsbestaetigung-' . $b['id'] . '.pdf"');
    echo confirmation_pdf($b);
    exit;
}

// Kennzahlen für den Kampagnenbericht speichern, optional Bericht sofort senden
if ($authed && isset($_POST['id'], $_POST['report_notes']) && $csrfOk()) {
    $id = (string) $_POST['id'];
    $notes = mb_substr(trim((string) $_POST['report_notes']), 0, 3000);
    $sendNow = isset($_POST['send_report']);
    $target = null;
    with_bookings(function (array $all) use ($id, $notes, $sendNow, &$target) {
        foreach ($all as &$b) {
            if ($b['id'] === $id) {
                $b['reportNotes'] = $notes;
                if ($sendNow && $b['status'] === 'bestaetigt') {
                    $b['reportSent'] = date('c');
                    $target = $b;
                }
                return $all;
            }
        }
        return null;
    }, true);
    $_SESSION['flash'] = $target
        ? (send_report($target) ? "Kampagnenbericht {$id} gesendet." : 'Bericht gespeichert, Mailversand fehlgeschlagen.')
        : 'Kennzahlen gespeichert.';
    header('Location: ./?f=' . urlencode((string) ($_GET['f'] ?? '')));
    exit;
}

// Bestandskunden als CSV für die Akquise-Liste, eine Zeile je Kunde
if ($authed && isset($_GET['export'])) {
    $rows = [];
    foreach (with_bookings(fn(array $b) => $b) as $b) {
        if ($b['status'] !== 'bestaetigt' || !$b['items']) {
            continue;
        }
        $k = mb_strtolower($b['customer']['email']);
        $end = booking_end($b);
        $r = $rows[$k] ?? ['count' => 0, 'total' => 0.0, 'end' => ''];
        $r['count']++;
        $r['total'] += $b['net'];
        if ($end >= $r['end']) {
            $r = array_merge($r, ['end' => $end, 'b' => $b]);
        }
        $rows[$k] = $r;
    }
    $de = fn(float $v) => number_format($v, 2, ',', '');
    $date = fn(?string $iso) => $iso ? (new DateTimeImmutable($iso))->format('d.m.Y') : '';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bestandskunden-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Firma', 'Ansprechperson', 'E-Mail', 'Branche', 'Letzte Buchung', 'Kampagnenende', 'Umsatz letzte Buchung netto', 'Buchungen gesamt', 'Umsatz gesamt netto', 'Bericht gesendet am'], ';');
    foreach ($rows as $r) {
        $b = $r['b'];
        fputcsv($out, [$b['customer']['company'], $b['customer']['name'], $b['customer']['email'], '', $b['id'], $date($r['end']),
            $de($b['net']), $r['count'], $de($r['total']), $date($b['reportSent'] ?? null)], ';');
    }
    fclose($out);
    exit;
}

// Systemcheck für den Livegang
$checks = [];
if ($authed && isset($_GET['check'])) {
    $c = config();
    $add = function (string $label, bool $ok, string $info = '') use (&$checks) {
        $checks[] = [$label, $ok, $info];
    };
    $add('PHP-Version ab 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION);
    foreach (['mbstring', 'fileinfo', 'json'] as $ext) {
        $add("PHP-Erweiterung $ext", extension_loaded($ext));
    }
    $add('mail() verfügbar', function_exists('mail') && !$c['mailToLog'], $c['mailToLog'] ? 'Testmodus mailToLog ist aktiv' : '');
    $add('Speicher beschreibbar', is_writable(storage_path()) && is_writable(storage_path('uploads')), storage_path());
    $add('Eigene Konfiguration api/config.local.php', is_file(__DIR__ . '/../api/config.local.php'));
    $add('Empfängeradresse gültig', (bool) filter_var($c['bookingEmail'], FILTER_VALIDATE_EMAIL), $c['bookingEmail'] . ' – Postfach muss existieren');
    $add('Absenderadresse eigener Domain', str_ends_with($c['mailFrom'], '@' . preg_replace('#^www\.#', '', (string) parse_url($c['siteUrl'], PHP_URL_HOST))), $c['mailFrom']);
    $add('Firmenanschrift für PDF', (bool) $c['company']['lines']);
    $add('siteUrl mit HTTPS', str_starts_with($c['siteUrl'], 'https://'), $c['siteUrl']);
    $add('cronKey gesetzt', $c['cronKey'] !== '');
    $sched = is_file(storage_path('scheduler.json')) ? (json_decode((string) file_get_contents(storage_path('scheduler.json')), true) ?: []) : [];
    $cronOk = isset($sched['cron']) && strtotime($sched['cron']) > time() - 2 * 3600;
    $add('Zeitgeber läuft (letzte 2 Stunden)', $cronOk, isset($sched['cron']) ? 'zuletzt ' . date('d.m.Y H:i', strtotime($sched['cron'])) : 'noch nie per Cron aufgerufen');
    // Schutz der Buchungsdaten von außen prüfen
    $real = realpath(storage_path());
    $root = realpath(__DIR__ . '/..');
    if ($real && $root && str_starts_with($real, $root)) {
        $url = rtrim($c['siteUrl'], '/') . substr($real, strlen($root)) . '/bookings.json';
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 4, 'method' => 'HEAD']]);
        @file_get_contents($url, false, $ctx);
        $code = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
        $add('Buchungsdaten von außen gesperrt', in_array($code, [403, 404], true), $code ? "HTTP $code für $url" : "nicht erreichbar: $url");
    } else {
        $add('Buchungsdaten außerhalb des Webroots', true, (string) $real);
    }
}

// Statuswechsel und erneuter Versand
if ($authed && isset($_POST['id']) && $csrfOk()) {
    $id = (string) $_POST['id'];
    $status = (string) ($_POST['status'] ?? '');
    $resend = isset($_POST['resend']);
    $result = ['msg' => '', 'booking' => null];
    if ($resend || in_array($status, ['angefragt', 'bestaetigt', 'storniert'], true)) {
        // Rückgabe null = nichts speichern, Ergebnis über $result
        with_bookings(function (array $all) use ($id, $status, $resend, &$result) {
            foreach ($all as $i => $b) {
                if ($b['id'] !== $id) {
                    continue;
                }
                if ($resend) {
                    $result['booking'] = $b;
                    return null;
                }
                // Reaktivieren nur, wenn Termine und Sidebar-Plätze noch frei sind
                if (!is_active($b) && $status !== 'storniert') {
                    $c = booking_conflicts($b, $all);
                    if ($c['dates'] || $c['months']) {
                        $result['msg'] = "{$b['id']} lässt sich nicht reaktivieren. " . conflict_message($c);
                        return null;
                    }
                }
                $b['status'] = $status;
                $b['updated'] = date('c');
                if ($status === 'angefragt') {
                    $b['heldSince'] = date('c');
                }
                if ($status === 'bestaetigt') {
                    $b['confirmedAt'] = date('c');
                    $b['uploadToken'] ??= bin2hex(random_bytes(16));
                    $result['booking'] = $b;
                }
                $all[$i] = $b;
                return $all;
            }
            $result['msg'] = 'Buchung nicht gefunden.';
            return null;
        }, true);
    }
    if ($result['booking']) {
        $ok = send_confirmation($result['booking']);
        $result['msg'] = $ok
            ? "Auftragsbestätigung {$result['booking']['id']} an {$result['booking']['customer']['email']} gesendet."
            : "Status gespeichert, aber der Mailversand ist fehlgeschlagen. PDF bitte manuell senden.";
    }
    $_SESSION['flash'] = $result['msg'];
    header('Location: ./?f=' . urlencode((string) ($_GET['f'] ?? '')));
    exit;
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$labels = ['angefragt' => 'Angefragt', 'bestaetigt' => 'Bestätigt', 'abgelaufen' => 'Abgelaufen', 'storniert' => 'Storniert'];
$filter = (string) ($_GET['f'] ?? '');
$bookings = $authed ? array_reverse(with_bookings(fn(array $b) => $b)) : [];
if ($filter !== '') {
    $bookings = array_values(array_filter($bookings, fn($b) => $b['status'] === $filter));
}
[$taken, $used] = $authed ? with_bookings(fn(array $b) => [taken_dates($b), sidebar_usage($b)]) : [[], []];
$slots = pricing()['products']['sidebar']['slots'];
$upcoming = $authed ? upcoming_issues(with_bookings(fn(array $b) => $b), 6) : [];
$digestSent = is_file(storage_path('digest.json')) ? (json_decode((string) file_get_contents(storage_path('digest.json')), true) ?: []) : [];
$actions = ['bestaetigt' => 'Bestätigen und PDF senden', 'angefragt' => 'Zurück auf angefragt', 'storniert' => 'Stornieren'];
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Mediabuchung Verwaltung</title>
<link rel="stylesheet" href="../assets/styles.css">
<style>
  .admin { padding: 32px 0 64px; }
  .admin table { width: 100%; border-collapse: collapse; font-size: .92rem; }
  .bk { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 18px; margin-bottom: 16px; }
  .bk-head { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: baseline; }
  .bk pre { white-space: pre-wrap; font: .88rem/1.5 ui-monospace, Menlo, monospace; margin: 12px 0; color: var(--text); }
  .badge { padding: 3px 10px; border-radius: 999px; font-size: .8rem; font-weight: 600; background: var(--surface-alt); }
  .badge.bestaetigt { background: var(--accent); color: var(--accent-ink); }
  .badge.storniert { text-decoration: line-through; color: var(--muted); }
  .actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .actions form { margin: 0; }
  .thumbs { display: flex; gap: 12px; flex-wrap: wrap; }
  .thumbs img { max-width: 260px; max-height: 160px; border: 1px solid var(--line); border-radius: 8px; display: block; }
  .filter { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
  .filter a { padding: 6px 14px; border-radius: 999px; border: 1px solid var(--line); text-decoration: none; color: var(--text); font-size: .9rem; }
  .filter a.on { background: var(--accent); color: var(--accent-ink); border-color: var(--accent); }
  .cal { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 28px; }
  .cal span { font-size: .8rem; padding: 4px 8px; border-radius: 6px; background: var(--surface); border: 1px solid var(--line); }
  .cal .angefragt { background: var(--accent-soft); }
  .cal .bestaetigt { background: var(--accent); color: var(--accent-ink); }
  .login { max-width: 360px; margin: 80px auto; }
  .flash { background: var(--accent-soft); border-left: 4px solid var(--accent); padding: 10px 14px; border-radius: 8px; }
  .badge.abgelaufen { color: var(--warm); }
  .next { width: 100%; border-collapse: collapse; margin-bottom: 28px; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); }
  .next td { padding: 10px 14px; border-bottom: 1px solid var(--line); font-size: .92rem; }
  .next .warn { color: var(--warm); font-weight: 600; }
  .checks .ok { color: var(--accent); font-weight: 600; }
  .report { margin-top: 16px; border-top: 1px solid var(--line); padding-top: 14px; }
  @media (max-width: 640px) { .next td { display: block; border: 0; padding: 4px 14px; } .next tr { display: block; border-bottom: 1px solid var(--line); padding: 8px 0; } }
</style>
</head>
<body>
<div class="wrap admin">
<?php if (!$authed): ?>
  <form method="post" class="login bk">
    <h2>Mediabuchung</h2>
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <label>Passwort<input type="password" name="password" autofocus required></label>
    <?php if (!empty($loginError)): ?><p class="error"><?= $h($loginError) ?></p><?php endif ?>
    <button class="btn btn-block">Anmelden</button>
  </form>
<?php else: ?>
  <div class="bk-head"><h2>Buchungsanfragen</h2><a href="?logout=1">Abmelden</a></div>
  <?php if ($flash): ?><p class="flash"><?= $h($flash) ?></p><?php endif ?>

  <p class="small"><a href="?check=1">Systemcheck ausführen</a> · <a href="?export=1">Bestandskunden als CSV</a></p>
  <?php if ($checks): ?>
    <table class="next checks">
      <?php foreach ($checks as [$label, $ok, $info]): ?>
        <tr><td><?= $ok ? '<span class="ok">OK</span>' : '<span class="warn">Offen</span>' ?></td><td><?= $h($label) ?></td><td class="muted small"><?= $h($info) ?></td></tr>
      <?php endforeach ?>
    </table>
  <?php endif ?>

  <h3>Nächste Newsletter-Ausgaben</h3>
  <table class="next">
    <?php foreach ($upcoming as $i): $b = $i['booking']; $it = $i['item']; ?>
      <tr>
        <td><strong><?= $h(de_date($i['date'])) ?></strong></td>
        <?php if (!$b): ?>
          <td colspan="3" class="muted">kein Partner gebucht</td>
        <?php else: ?>
          <td><?= $h($b['customer']['company']) ?> <span class="muted small"><?= $h($b['id']) ?></span></td>
          <td><span class="badge <?= $h($b['status']) ?>"><?= $h($labels[$b['status']]) ?></span></td>
          <td><?php if (!empty($it['file'])): ?><a href="?file=<?= $h(urlencode($it['file']['stored'])) ?>" target="_blank">Banner liegt vor</a><?php else: ?><span class="warn">Banner fehlt</span><?php endif ?>
            <?php if (isset($digestSent[$i['date']])): ?><span class="muted small"> · Übersicht gesendet</span><?php endif ?></td>
        <?php endif ?>
      </tr>
    <?php endforeach ?>
  </table>

  <h3>Newsletter-Belegung</h3>
  <div class="cal">
    <?php foreach (newsletter_dates() as $d): ?>
      <span class="<?= $h($taken[$d] ?? '') ?>" title="<?= $h($labels[$taken[$d] ?? ''] ?? 'Frei') ?>"><?= $h(de_date($d)) ?></span>
    <?php endforeach ?>
  </div>

  <h3>Sidebar-Auslastung (<?= (int) $slots ?> Plätze pro Monat)</h3>
  <div class="cal">
    <?php for ($i = 0; $i < 12; $i++): $m = (new DateTimeImmutable('first day of this month'))->modify("+$i months")->format('Y-m'); $u = $used[$m] ?? 0; ?>
      <span class="<?= $u >= $slots ? 'bestaetigt' : ($u ? 'angefragt' : '') ?>"><?= $h(de_month($m)) ?>: <?= $u ?>/<?= (int) $slots ?></span>
    <?php endfor ?>
  </div>

  <nav class="filter">
    <a href="./" class="<?= $filter === '' ? 'on' : '' ?>">Alle</a>
    <?php foreach ($labels as $k => $l): ?>
      <a href="?f=<?= $k ?>" class="<?= $filter === $k ? 'on' : '' ?>"><?= $l ?></a>
    <?php endforeach ?>
  </nav>

  <?php if (!$bookings): ?><p class="muted">Keine Anfragen.</p><?php endif ?>
  <?php foreach ($bookings as $b): ?>
    <article class="bk">
      <div class="bk-head">
        <strong><?= $h($b['customer']['company']) ?> · <?= $h($b['id']) ?></strong>
        <span class="badge <?= $h($b['status']) ?>"><?= $h($labels[$b['status']]) ?></span>
      </div>
      <?php if ($b['status'] === 'angefragt'): ?><p class="muted small">Vorgemerkt bis <?= $h(hold_until($b)) ?>, danach automatisch abgelaufen.</p><?php endif ?>
      <pre><?= $h(booking_text($b)) ?></pre>
      <div class="thumbs">
        <?php foreach ($b['items'] as $it): if (empty($it['file'])) continue; $f = $it['file']['stored']; ?>
          <a href="?file=<?= $h(urlencode($f)) ?>&dl=1" title="Herunterladen"><img src="?file=<?= $h(urlencode($f)) ?>" alt="<?= $h($it['name']) ?>"></a>
        <?php endforeach ?>
      </div>
      <div class="actions" style="margin-top:14px">
        <?php foreach ($actions as $k => $l): if ($k === $b['status']) continue; ?>
          <form method="post" action="?f=<?= $h($filter) ?>"<?= $k === 'storniert' ? ' onsubmit="return confirm(\'Buchung wirklich stornieren?\')"' : '' ?>>
            <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
            <input type="hidden" name="id" value="<?= $h($b['id']) ?>">
            <input type="hidden" name="status" value="<?= $k ?>">
            <button class="btn btn-small <?= $k === 'bestaetigt' ? '' : 'btn-ghost' ?>"><?= $h($l) ?></button>
          </form>
        <?php endforeach ?>
        <?php if ($b['items']): ?>
          <a class="btn btn-small btn-ghost" href="?pdf=<?= $h(urlencode($b['id'])) ?>" target="_blank">PDF ansehen</a>
        <?php endif ?>
        <?php if ($b['status'] === 'bestaetigt'): ?>
          <form method="post" action="?f=<?= $h($filter) ?>">
            <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
            <input type="hidden" name="id" value="<?= $h($b['id']) ?>">
            <input type="hidden" name="resend" value="1">
            <button class="btn btn-small btn-ghost">Bestätigung erneut senden</button>
          </form>
        <?php endif ?>
        <a class="btn btn-small btn-ghost" href="mailto:<?= $h($b['customer']['email']) ?>?subject=<?= $h(rawurlencode('Ihre Buchung ' . $b['id'])) ?>">Kunde anschreiben</a>
      </div>
      <?php if ($b['status'] === 'bestaetigt' && $b['items']): ?>
        <form method="post" action="?f=<?= $h($filter) ?>" class="report">
          <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
          <input type="hidden" name="id" value="<?= $h($b['id']) ?>">
          <label>Kennzahlen für den Kampagnenbericht (optional)
            <textarea name="report_notes" rows="3" placeholder="z. B. Öffnungsrate je Ausgabe, Klicks auf das Banner"><?= $h($b['reportNotes'] ?? '') ?></textarea>
          </label>
          <p class="muted small"><?= !empty($b['reportSent'])
              ? 'Bericht gesendet am ' . $h(date('d.m.Y', strtotime($b['reportSent'])))
              : 'Automatischer Bericht am ' . $h((new DateTimeImmutable(booking_end($b)))->modify('+' . (int) config()['reportDaysAfter'] . ' days')->format('d.m.Y')) ?></p>
          <div class="actions">
            <button class="btn btn-small btn-ghost">Kennzahlen speichern</button>
            <button class="btn btn-small btn-ghost" name="send_report" value="1"><?= empty($b['reportSent']) ? 'Bericht jetzt senden' : 'Bericht erneut senden' ?></button>
          </div>
        </form>
      <?php endif ?>
    </article>
  <?php endforeach ?>
<?php endif ?>
</div>
</body>
</html>
