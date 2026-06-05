<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;

$session = FitReader::activity(DEMO_FIT_PATH)->sessions[0];

demo_title('12', 'Heart-rate zones — time in zone');

// A classic training view, built straight from the records. We analyze the
// normalized 1 second timeline (recordsNormalized()) so every second is accounted
// for exactly once — each record is one second, charged to the zone its heart
// rate falls in. Zones here are the textbook %-of-max bands; swap in your own.

$maxHr = $session->maxHeartRate() ?? 190;

// Upper bound (% of max) and label for each zone, low → high.
$bands = [
    ['pct' => 0.60, 'name' => 'Z1 Recovery'],
    ['pct' => 0.70, 'name' => 'Z2 Endurance'],
    ['pct' => 0.80, 'name' => 'Z3 Tempo'],
    ['pct' => 0.90, 'name' => 'Z4 Threshold'],
    ['pct' => 1.01, 'name' => 'Z5 VO2max'], // 1.01 so HR == max lands in Z5
];
$lowPct = 0.50; // below this we count it as "easy/<Z1"

$timeInZone = array_fill(0, count($bands), 0.0);
$belowZ1    = 0.0;

foreach ($session->recordsNormalized() as $r) {
    $hr = $r->heartRate();
    if ($hr === null || $hr <= 0) {
        continue;
    }
    $frac = $hr / $maxHr;
    if ($frac < $lowPct) {
        $belowZ1 += 1.0; // one second on the 1 second timeline
        continue;
    }
    foreach ($bands as $z => $band) {
        if ($frac < $band['pct']) {
            $timeInZone[$z] += 1.0;
            break;
        }
    }
}

$mmss  = static fn (float $s): string => sprintf('%d:%02d', intdiv((int) round($s), 60), ((int) round($s)) % 60);
$total = array_sum($timeInZone) + $belowZ1;

printf("Max HR used: %d bpm   (zones are %% of max)\n\n", $maxHr);
printf("  %-13s  %-9s  %-7s  %-6s %s\n", 'Zone', 'bpm', 'Time', 'Share', '');
echo '  ', str_repeat('─', 52), "\n";

$lo = (int) round($maxHr * $lowPct);
foreach ($bands as $z => $band) {
    $hi    = (int) round($maxHr * $band['pct']);
    $secs  = $timeInZone[$z];
    $share = $total > 0 ? $secs / $total : 0.0;
    printf("  %-13s  %3d–%-5d  %-7s  %4.1f%%  %s\n",
        $band['name'],
        $lo,
        $z === count($bands) - 1 ? $maxHr : $hi,
        $mmss($secs),
        $share * 100,
        str_repeat('█', (int) round($share * 30)),
    );
    $lo = $hi;
}
if ($belowZ1 > 0) {
    printf("  %-13s  %-9s  %-7s  %4.1f%%\n", '<Z1 easy', '', $mmss($belowZ1), $belowZ1 / $total * 100);
}

printf("\n  %-13s  %-9s  %s\n", 'Total', '', $mmss($total));
