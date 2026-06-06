<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;

$activity = FitReader::activity(DEMO_FIT_PATH);

demo_title('04', 'Activity overview — metadata, devices, events');

printf("Manufacturer: %s\n", $activity->manufacturer ?? '—');
printf("Created:      %s\n", $activity->timeCreated?->format('c') ?? '—');
printf("Sessions:     %d\n", $activity->numSessions);
printf("Total timer:  %.0f min\n", ($activity->totalTimerTime ?? 0.0) / 60.0);

printf("\nDevice-info messages: %d\n", count($activity->deviceInfos));
foreach ($activity->deviceInfos as $dev) {
    printf(
        "  manufacturer=%s product=%s battery=%s\n",
        $dev['manufacturer'] ?? '—',
        $dev['product'] ?? '—',
        $dev['battery_level'] ?? '—',
    );
}

printf("\nEvents: %d\n", count($activity->events));
foreach ($activity->events as $ev) {
    $ts = $ev['timestamp'] ?? null;
    printf(
        "  %s  %s / %s\n",
        $ts instanceof \DateTimeImmutable ? $ts->format('H:i:s') : '—',
        $ev['event'] ?? '—',
        $ev['event_type'] ?? '—',
    );
}

// `summary`, `fileId`, `fileCreator`, `deviceInfos`, `events` are raw field maps
// — the escape hatch for anything without a typed accessor.
printf("\nRaw activity summary keys: %s\n", implode(', ', array_keys($activity->summary ?? [])));
