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
    </article>
  <?php endforeach ?>
<?php endif ?>
</div>
</body>
</html>
