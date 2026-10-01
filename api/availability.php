<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Liefert freie und belegte Newsletter-Ausgaben sowie freie Sidebar-Plätze je Monat. Keine Kundendaten.
[$taken, $used] = with_bookings(fn(array $b) => [taken_dates($b), sidebar_usage($b)]);
$sb = pricing()['products']['sidebar'];

$dates = array_map(
    fn(string $d) => ['date' => $d, 'status' => isset($taken[$d]) ? 'belegt' : 'frei'],
    newsletter_dates()
);

// Startmonate plus maximale Laufzeit abdecken
$months = [];
$first = new DateTimeImmutable('first day of this month');
for ($i = 0; $i < $sb['horizonMonths'] + $sb['max']; $i++) {
    $m = $first->modify("+$i months")->format('Y-m');
    $months[$m] = max(0, $sb['slots'] - ($used[$m] ?? 0));
}

json_out(['newsletter' => $dates, 'sidebar' => ['slots' => $sb['slots'], 'free' => $months]]);
