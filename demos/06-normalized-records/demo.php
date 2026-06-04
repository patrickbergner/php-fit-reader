<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;

$session = FitReader::activity(DEMO_FIT_PATH)->sessions[0];

demo_title('06', 'Normalized records — one fully-populated row per second');

$raw        = $session->records;
$normalized = $session->recordsNormalized(); // default 1 Hz timeline, cached

printf("Raw records:        %d\n", count($raw));
printf("Normalized records: %d (continuous 1 Hz timeline, forward-filled)\n", count($normalized));

// A normalized row mid-run: every field is carried forward, so nothing is null.
$mid = $normalized[intdiv(count($normalized), 2)];
printf("\nMid-run normalized record @ %s:\n", $mid->timestamp()?->format('H:i:s') ?? '—');
foreach ($mid->all() as $name => $value) {
    if ($value instanceof \DateTimeImmutable) {
        $value = $value->format('H:i:s');
    } elseif (is_object($value)) {
        $value = json_encode($value); // e.g. the position GeoPoint
    }
    printf("  %-16s %s\n", $name, $value ?? '—');
}
