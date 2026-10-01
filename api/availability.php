<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Liefert buchbare und belegte Newsletter-Ausgaben. Keine Kundendaten.
$taken = with_bookings(fn(array $b) => taken_dates($b));
$dates = array_map(
    fn(string $d) => ['date' => $d, 'status' => isset($taken[$d]) ? 'belegt' : 'frei'],
    newsletter_dates()
);

json_out(['newsletter' => $dates]);
