<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;

$session = FitReader::activity(DEMO_FIT_PATH)->sessions[0];

demo_title('14', 'Aerobic decoupling & training load');

// Two staple "how was this session?" numbers, computed from speed()/heartRate()
// over the records — the sort of training insight the typed objects are for.
//
//   • Aerobic decoupling: does pace-per-heartbeat fade in the second half?
//     EF = speed / HR (efficiency factor); decoupling = (EF1 - EF2) / EF1.
//     Friel's rule of thumb: < 5 % means well aerobically coupled.
//   • Training load (Banister TRIMP): duration weighted by HR intensity.

$restHr = 60;
$maxHr  = $session->maxHeartRate() ?? 190;

// Time-weighted sums of speed and HR over an arbitrary record span.
$accumulate = static function (array $records): array {
    $sumSpeed = 0.0;
    $sumHr    = 0.0;
    $secs     = 0.0;
    $prev     = null;
    foreach ($records as $r) {
        $t = $r->timestamp();
        if ($t === null) {
            continue;
        }
        if ($prev !== null && $prev['spd'] !== null && $prev['hr'] !== null && $prev['hr'] > 0) {
            $dt = (float) ($t->getTimestamp() - $prev['t']->getTimestamp());
            $sumSpeed += $prev['spd'] * $dt;
            $sumHr    += $prev['hr'] * $dt;
            $secs     += $dt;
        }
        $prev = ['t' => $t, 'spd' => $r->speed(), 'hr' => $r->heartRate()];
    }
    return ['speed' => $secs > 0 ? $sumSpeed / $secs : 0.0, 'hr' => $secs > 0 ? $sumHr / $secs : 0.0, 'secs' => $secs];
};

$records = $session->records;
$half    = intdiv(count($records), 2);
$first   = $accumulate(array_slice($records, 0, $half));
$second  = $accumulate(array_slice($records, $half));

$ef1 = $first['hr']  > 0 ? $first['speed']  / $first['hr']  : 0.0;
$ef2 = $second['hr'] > 0 ? $second['speed'] / $second['hr'] : 0.0;
$decoupling = $ef1 > 0 ? ($ef1 - $ef2) / $ef1 * 100.0 : 0.0;

$pace = static fn (float $mps): string => $mps > 0
    ? sprintf('%d:%02d/km', intdiv((int) round(1000 / $mps), 60), ((int) round(1000 / $mps)) % 60)
    : '—';

printf("                 %-12s %-12s\n", '1st half', '2nd half');
printf("  avg pace       %-12s %-12s\n", $pace($first['speed']), $pace($second['speed']));
printf("  avg HR         %-12s %-12s\n", sprintf('%.0f bpm', $first['hr']), sprintf('%.0f bpm', $second['hr']));
printf("  efficiency     %-12s %-12s  (speed ÷ HR)\n", sprintf('%.4f', $ef1), sprintf('%.4f', $ef2));
printf("\n  Aerobic decoupling: %+.1f%%  (%s)\n",
    $decoupling,
    abs($decoupling) < 5.0 ? 'well coupled' : 'notable drift',
);

// Banister TRIMP over the whole run: Σ dt_min · HRr · 0.64·e^(1.92·HRr).
$trimp = 0.0;
$prev  = null;
foreach ($records as $r) {
    $t  = $r->timestamp();
    if ($t === null) {
        continue;
    }
    if ($prev !== null && $prev['hr'] !== null && $prev['hr'] > 0) {
        $dtMin = (float) ($t->getTimestamp() - $prev['t']->getTimestamp()) / 60.0;
        $hrr   = max(0.0, min(1.0, ($prev['hr'] - $restHr) / ($maxHr - $restHr)));
        $trimp += $dtMin * $hrr * 0.64 * exp(1.92 * $hrr);
    }
    $prev = ['t' => $t, 'hr' => $r->heartRate()];
}

printf("  Training load (TRIMP): %.0f   (rest %d, max %d bpm)\n", $trimp, $restHr, $maxHr);
