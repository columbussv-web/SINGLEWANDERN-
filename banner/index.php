<?php
declare(strict_types=1);
require __DIR__ . '/../api/lib.php';

header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$id = (string) ($_GET['b'] ?? '');
$token = (string) ($_GET['t'] ?? '');

/** Buchung nur mit gültigem Token. */
$find = function (array $all) use ($id, $token): ?array {
    foreach ($all as $b) {
        if ($b['id'] === $id && !empty($b['uploadToken']) && hash_equals($b['uploadToken'], $token)) {
            return $b;
        }
    }
    return null;
};

$booking = with_bookings($find);
if (!$booking || !$booking['items']) {
    http_response_code(404);
    $fatal = 'Dieser Link ist ungültig. Bitte prüfen Sie die Adresse aus unserer E-Mail.';
} elseif (!is_active($booking)) {
    $fatal = 'Diese Buchung ist ' . ($booking['status'] === 'abgelaufen' ? 'abgelaufen' : 'storniert') . '. Ein Upload ist nicht mehr möglich.';
}

// Vorschau des aktuellen Banners
if (empty($fatal) && isset($_GET['img'])) {
    foreach ($booking['items'] as $it) {
        if ($it['key'] === $_GET['img'] && !empty($it['file'])) {
            header('Content-Type: ' . (str_ends_with($it['file']['stored'], '.png') ? 'image/png' : 'image/jpeg'));
            readfile(storage_path('uploads/' . $it['file']['stored']));
            exit;
        }
    }
    http_response_code(404);
    exit;
}

$errors = [];
$saved = [];
if (empty($fatal) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploads = [];
    foreach ($booking['items'] as $it) {
        $u = check_upload($_FILES[$it['key'] . '_file'] ?? null, pricing()['products'][$it['key']]['short']);
        if (isset($u['error'])) {
            $errors[] = $u['error'];
        } elseif ($u) {
            $uploads[$it['key']] = $u;
        }
    }
    if (!$uploads && !$errors) {
        $errors[] = 'Bitte wählen Sie mindestens eine Datei aus.';
    }
    if (!$errors) {
        $old = [];
        // Token ist oben geprüft, hier zählt nur der aktuelle Stand unter Sperre
        with_bookings(function (array $all) use ($uploads, &$saved, &$old, &$booking) {
            foreach ($all as &$b) {
                if ($b['id'] !== $booking['id'] || !is_active($b)) {
                    continue;
                }
                foreach ($b['items'] as &$it) {
                    if (isset($uploads[$it['key']])) {
                        if (!empty($it['file'])) {
                            $old[] = $it['file']['stored'];
                        }
                        $it['file'] = store_upload($b['id'], $it['key'], $uploads[$it['key']]);
                        $saved[] = $it;
                    }
                }
                unset($it);
                $b['updated'] = date('c');
                $booking = $b;
                return $all;
            }
            return null;
        }, true);
        foreach ($old as $f) {
            @unlink(storage_path('uploads/' . $f));
        }
        if ($saved) {
            $c = config();
            $list = implode("\n", array_map(fn($it) => "- {$it['name']}: {$it['file']['original']} ({$it['file']['w']} × {$it['file']['h']} px)", $saved));
            send_mail(
                $c['bookingEmail'],
                "Banner eingegangen: {$booking['id']} {$booking['customer']['company']}",
                "{$booking['customer']['company']} hat Banner hochgeladen:\n$list\n\nVerwaltung: {$c['adminUrl']}",
                $booking['customer']['email']
            );
        }
    }
}

$formats = [];
foreach (pricing()['products'] as $k => $p) {
    $formats[$k] = $p['formats'];
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Banner hochladen</title>
<link rel="stylesheet" href="../assets/styles.css">
<style>
  .up { max-width: 760px; padding: 40px 16px 64px; }
  .item { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 20px; margin-bottom: 16px; }
  .item img { max-width: 100%; height: auto; border: 1px solid var(--line); border-radius: 8px; display: block; margin: 10px 0; }
  .state { font-size: .85rem; font-weight: 600; }
  .state.ok { color: var(--accent); }
  .state.missing { color: var(--warm); }
  .msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid; }
  .msg.ok { background: var(--accent-soft); border-color: var(--accent); }
  .msg.bad { background: var(--surface); border-color: var(--error); color: var(--error); }
</style>
</head>
<body>
<div class="wrap up">
  <p class="eyebrow">SINGLEWANDERN® Mediabuchung</p>
  <h1>Banner hochladen</h1>

<?php if (!empty($fatal)): ?>
  <p class="msg bad"><?= $h($fatal) ?></p>
<?php else: ?>
  <p class="muted">Buchung <?= $h($booking['id']) ?> · <?= $h($booking['customer']['company']) ?>. JPG oder PNG, max. <?= round(pricing()['uploadMaxBytes'] / 1024) ?> KB, keine Schrift kleiner als 18 px im Bild. Ein neuer Upload ersetzt das bisherige Banner.</p>

  <?php if ($saved): ?><p class="msg ok">Vielen Dank. <?= count($saved) === 1 ? 'Ihr Banner ist' : 'Ihre Banner sind' ?> eingegangen.</p><?php endif ?>
  <?php foreach ($errors as $e): ?><p class="msg bad"><?= $h($e) ?></p><?php endforeach ?>

  <form method="post" enctype="multipart/form-data">
    <?php foreach ($booking['items'] as $it):
        $fmt = array_values(array_filter($formats[$it['key']], fn($f) => $f['label'] === $it['format']))[0] ?? null; ?>
      <div class="item">
        <div class="bk-head" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
          <strong><?= $h($it['name']) ?></strong>
          <?php if (!empty($it['file'])): ?>
            <span class="state ok">Banner liegt vor</span>
          <?php else: ?>
            <span class="state missing">Banner fehlt noch</span>
          <?php endif ?>
        </div>
        <p class="muted small">Format: <?= $h($it['format']) ?><?php
            if (!empty($it['dates'])) echo ' · erste Ausgabe ' . $h(de_date($it['dates'][0]));
            if (!empty($it['start'])) echo ' · Start ' . $h(de_month($it['start']));
        ?></p>
        <?php if (!empty($it['file'])): ?>
          <img src="?b=<?= $h(rawurlencode($booking['id'])) ?>&t=<?= $h($token) ?>&img=<?= $h($it['key']) ?>&v=<?= $h(substr(md5($it['file']['stored']), 0, 6)) ?>" alt="Aktuelles Banner">
          <p class="muted small"><?= $h($it['file']['original']) ?>, <?= (int) $it['file']['w'] ?> × <?= (int) $it['file']['h'] ?> px<?php
            if ($fmt && ($it['file']['w'] !== $fmt['w'] || $it['file']['h'] !== $fmt['h'])) echo ' · gebuchtes Format ist ' . $fmt['w'] . ' × ' . $fmt['h'] . ' px';
          ?></p>
        <?php endif ?>
        <label><?= empty($it['file']) ? 'Banner auswählen' : 'Banner ersetzen' ?>
          <input type="file" name="<?= $h($it['key']) ?>_file" accept="image/jpeg,image/png">
        </label>
      </div>
    <?php endforeach ?>
    <button class="btn">Hochladen</button>
  </form>
<?php endif ?>
</div>
</body>
</html>
