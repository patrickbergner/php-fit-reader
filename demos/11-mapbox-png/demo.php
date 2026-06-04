<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;

demo_title('11', 'Mapbox PNG — render the GPS track to an image');

// The PNG renderer calls the Mapbox Static Images API, which needs an access
// token: the MAPBOX_TOKEN environment variable, or resources/mapbox-access-token.txt.
// Skip cleanly (no failure) when neither is present so the demo suite runs offline.
$token = demo_mapbox_token();
if ($token === null) {
    echo "No Mapbox token — skipping the live render.\n";
    echo "Provide one to enable this demo, either via the environment:\n\n";
    echo "  MAPBOX_TOKEN=pk.xxxx php demos/11-mapbox-png/demo.php\n\n";
    echo "or by writing it to resources/mapbox-access-token.txt (gitignored).\n";
    return;
}

// fitToMapPng() returns true on success, false if there's no GPS data or the
// API call fails. A 2560px (2x retina) square zoomed to the track's bounds.
$pngPath = __DIR__ . '/tiergarten-map.png';
$ok      = FitReader::fitToMapPng(DEMO_FIT_PATH, $pngPath, $token);

if ($ok) {
    printf("Rendered → %s (%d bytes)\n", basename($pngPath), filesize($pngPath));
} else {
    echo "Render failed (no GPS data, or the Mapbox API call did not succeed).\n";
}
