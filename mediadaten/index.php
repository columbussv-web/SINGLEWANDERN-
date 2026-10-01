<?php
declare(strict_types=1);
require __DIR__ . '/../api/mediadaten.php';

// Mediadaten als PDF, immer aus dem aktuellen Stand von pricing.json
$m = pricing()['media'];
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="SINGLEWANDERN-Mediadaten-' . str_replace('/', '-', $m['edition']) . '.pdf"');
header('Cache-Control: public, max-age=3600');
echo mediadaten_pdf();
