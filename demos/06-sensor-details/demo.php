<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;

$session = FitReader::activity(DEMO_FIT_PATH)->sessions[0];

demo_title('06', 'Sensor detail — every typed reading on a Record');

// A Record is much more than position + heart rate. Pick one rich, fully-loaded
// sample and read the whole typed sensor surface off it. Absent channels come
// back as null (shown as "—"); the escape hatches reach anything not typed.

// First record that carries the full set (GPS + chest strap + running footpod).
$r = $session->records[0];
foreach ($session->records as $rec) {
    if ($rec->position !== null && $rec->heartRate !== null && $rec->verticalOscillation !== null) {
        $r = $rec;
        break;
    }
}

$f   = static fn (mixed $v, string $unit = ''): string => $v === null ? '—' : trim($v . ' ' . $unit);
$pos = $r->position;

printf("Record @ %s\n\n", $r->timestamp?->format('H:i:s') ?? '—');

echo "Position & motion\n";
printf("  position           %s\n", $pos !== null ? sprintf('%.5f, %.5f', $pos->lat, $pos->lng) : '—');
printf("  altitude           %s\n", $f($r->altitude, 'm'));
printf("  speed              %s\n", $f($r->speed, 'm/s'));
printf("  distance           %s\n", $f($r->distance, 'm'));

echo "\nCore sensors\n";
printf("  heart rate         %s\n", $f($r->heartRate, 'bpm'));
printf("  cadence            %s\n", $f($r->cadence, 'spm'));
printf("  power              %s\n", $f($r->power, 'W'));
printf("  temperature        %s\n", $f($r->temperature, '°C'));
printf("  GPS accuracy       %s\n", $f($r->gpsAccuracy, 'm'));

echo "\nRunning dynamics\n";
printf("  vertical oscillation %s\n", $f($r->verticalOscillation, 'mm'));
printf("  stance time          %s\n", $f($r->stanceTime, 'ms'));
printf("  stance time pct      %s\n", $f($r->stanceTimePercent, '%'));
printf("  stance balance L/R   %s\n", $f($r->stanceTimeBalance, '%'));
printf("  vertical ratio       %s\n", $f($r->verticalRatio, '%'));
printf("  step length          %s\n", $f($r->stepLength, 'mm'));
printf("  fractional cadence   %s\n", $f($r->fractionalCadence, 'rpm'));
printf("  respiration rate     %s\n", $f($r->respirationRate, 'brpm'));

echo "\nDeveloper (Connect IQ) field\n";
printf("  Leg Spring Stiffness %s\n", $f($r->developerField('Leg Spring Stiffness'), 'kN/m'));

echo "\nEscape hatches\n";
printf("  field('vertical_oscillation') = %s\n", $r->field('vertical_oscillation') ?? '—');
printf("  all(): %d resolved fields on this record\n", count($r->all()));

echo "\nAlso typed, but cycling-/diving-only (— in this running file):\n";
echo "  leftRightBalance(), left/rightTorqueEffectiveness(), left/right/combinedPedalSmoothness(), depth(), grade()\n";
