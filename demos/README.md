# Demos

A tour of the public API, each a single, runnable `demo.php`.

They all use the same synthetic [`tiergarten-run.fit`](tiergarten-run.fit) file, a somewhat realistic ~12 km run through Berlin's Großer Tiergarten that starts and finishes at the Brandenburg Gate.

## Running (pun intended)

Needs PHP 8.4 or newer. Run `composer install` in the root directory first.

Run the `demo.php` in any directory or all of them at once with the `run.sh` in this directory.

Demos write their artifacts into their own directory. Those files are committed so you can also inspect the expected output without running anything yourself.

## The tour

The tour builds up the way you'd actually use the library: read the file into typed objects, normalize the stream, analyze it, transform it, prepare it for visualization, and, if you need to, export it to an interchange format.

### Reading the typed objects

| Dir | Shows |
|---|---|
| [`01-quick-start`](01-quick-start/demo.php) | `FitReader::activity()`; sport, distance, duration |
| [`02-eager-reading`](02-eager-reading/demo.php) | iterate `sessions → records`; `Record` accessors, `position()`/`GeoPoint`, `heartRate()` |
| [`03-streaming`](03-streaming/demo.php) | `FitReader::messages()` + `MessageKind`; tally kinds; flat-memory note |
| [`04-activity-overview`](04-activity-overview/demo.php) | `Activity` helpers, `deviceInfos`, `events`, `summary` |
| [`05-sessions-and-laps`](05-sessions-and-laps/demo.php) | `Session` accessors + per-km lap splits showing fast/slow |
| [`06-sensor-details`](06-sensor-details/demo.php) | the full typed `Record` surface: power/temperature/GPS, running dynamics, `developerField()` (Connect IQ), `field()`/`all()` |
| [`07-local-time-and-summaries`](07-local-time-and-summaries/demo.php) | `Activity` local time (`utcOffsetSeconds()`/`localTimeCreated()`/`toLocalTime()`), `Session` moving/stopped time + average dynamics |

### Normalizing & deriving fields

| Dir | Shows |
|---|---|
| [`08-normalized-records`](08-normalized-records/demo.php) | `Session::recordsNormalized()`; raw vs normalized; fully-populated 1 second rows |
| [`09-custom-normalization`](09-custom-normalization/demo.php) | `FitReader::normalizer(stepSeconds/include/exclude/noFill/zeroIsInvalid/derive)` |
| [`10-gps-derived-fields`](10-gps-derived-fields/demo.php) | the five `…FromGps()` factories + smoothing |

### Analyzing in PHP

| Dir | Shows |
|---|---|
| [`11-best-splits`](11-best-splits/demo.php) | fastest 1 km / 5 km / 10 km via a sliding window over `distance()` + `timestamp()` |
| [`12-hr-zones`](12-hr-zones/demo.php) | heart-rate time-in-zone over the normalized 1 second timeline |
| [`13-elevation-grade`](13-elevation-grade/demo.php) | elevation profile, ascent/descent, steepest grade, ASCII sparkline |
| [`14-pace-hr-decoupling`](14-pace-hr-decoupling/demo.php) | aerobic decoupling (efficiency factor) + Banister TRIMP load |

### Visualizing the track

| Dir | Shows |
|---|---|
| [`15-visualization`](15-visualization/demo.php) | `trackBounds()`/center, Douglas–Peucker simplify, `encodedPolyline()` for Mapbox & Google static maps, `activityToGeoJson()` + a keyless geojson.io link; renders track+bbox and simplified-track PNGs when a Mapbox token is set |

### Exporting to interchange formats

| Dir | Shows |
|---|---|
| [`16-csv-export`](16-csv-export/demo.php) | `activityToCsv` raw + normalized + `activityToCsvString` + CSV dialect |
| [`17-gpx-export`](17-gpx-export/demo.php) | `activityToGpx` raw + normalized(+derived) + `activityToGpxString` |
| [`18-kml-export`](18-kml-export/demo.php) | `activityToKml` raw + normalized + `activityToKmlString`; `gx:Track` for Google Earth |
| [`19-tcx-export`](19-tcx-export/demo.php) | `activityToTcx` raw + normalized(+derived) + `activityToTcxString`; lap-aware TCX |
| [`20-mapbox-png`](20-mapbox-png/demo.php) | `fitToMapPng`; reads a Mapbox token, skips gracefully if absent |

## About Mapbox

Demos `20-mapbox-png` and the optional renders in `15-visualization` need a Mapbox access token — either in the `MAPBOX_TOKEN` environment variable or in the `resources/mapbox-access-token.txt` file (gitignored). Without a token they print a note and skip.
