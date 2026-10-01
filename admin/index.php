<?php
declare(strict_types=1);
require __DIR__ . '/../api/lib.php';

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

// Statuswechsel
if ($authed && isset($_POST['id'], $_POST['status']) && $csrfOk()) {
    $id = (string) $_POST['id'];
    $status = (string) $_POST['status'];
    if (in_array($status, ['angefragt', 'bestaetigt', 'storniert'], true)) {
        with_bookings(function (array $all) use ($id, $status) {
            foreach ($all as &$b) {
                if ($b['id'] === $id) {
                    $b['status'] = $status;
                    $b['updated'] = date('c');
                }
            }
            return $all;
        }, true);
    }
    header('Location: ./?f=' . urlencode((string) ($_GET['f'] ?? '')));
    exit;
}

$labels = ['angefragt' => 'Angefragt', 'bestaetigt' => 'Bestätigt', 'storniert' => 'Storniert'];
$filter = (string) ($_GET['f'] ?? '');
$bookings = $authed ? array_reverse(with_bookings(fn(array $b) => $b)) : [];
if ($filter !== '') {
    $bookings = array_values(array_filter($bookings, fn($b) => $b['status'] === $filter));
}
$taken = $authed ? with_bookings(fn(array $b) => taken_dates($b)) : [];
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

  <h3>Newsletter-Belegung</h3>
  <div class="cal">
    <?php foreach (newsletter_dates() as $d): ?>
      <span class="<?= $h($taken[$d] ?? '') ?>" title="<?= $h($labels[$taken[$d] ?? ''] ?? 'Frei') ?>"><?= $h(de_date($d)) ?></span>
    <?php endforeach ?>
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
      <pre><?= $h(booking_text($b)) ?></pre>
      <div class="thumbs">
        <?php foreach ($b['items'] as $it): if (empty($it['file'])) continue; $f = $it['file']['stored']; ?>
          <a href="?file=<?= $h(urlencode($f)) ?>&dl=1" title="Herunterladen"><img src="?file=<?= $h(urlencode($f)) ?>" alt="<?= $h($it['name']) ?>"></a>
        <?php endforeach ?>
      </div>
      <div class="actions" style="margin-top:14px">
        <?php foreach ($labels as $k => $l): if ($k === $b['status']) continue; ?>
          <form method="post" action="?f=<?= $h($filter) ?>">
            <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
            <input type="hidden" name="id" value="<?= $h($b['id']) ?>">
            <input type="hidden" name="status" value="<?= $k ?>">
            <button class="btn btn-small <?= $k === 'bestaetigt' ? '' : 'btn-ghost' ?>"><?= $k === 'angefragt' ? 'Zurück auf angefragt' : $l ?></button>
          </form>
        <?php endforeach ?>
        <a class="btn btn-small btn-ghost" href="mailto:<?= $h($b['customer']['email']) ?>?subject=<?= $h(rawurlencode('Ihre Buchung ' . $b['id'])) ?>">Kunde anschreiben</a>
      </div>
    </article>
  <?php endforeach ?>
<?php endif ?>
</div>
</body>
</html>
