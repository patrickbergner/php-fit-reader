# Demos — a progressive tour of the library

Increasingly complex, runnable scripts, each a single `demo.php`.
They all read a synthetic FIT file: [`tiergarten-run.fit`](tiergarten-run.fit),
a somewhat realistic ~12 km run through Berlin's Großer Tiergarten that starts and finishes
at the Brandenburg Gate.

## Running (pun intended)

Needs PHP 8.2 or newer. Run `composer install` in the root directory first.

Run the `demo.php` in any directory or all of them at once with the `run.sh` in this directory.

Demos write their artifacts into their own directory. Those files are committed so you can also
inspect the expected output without running anything yourself.

## The tour

| Dir | Shows |
|---|---|
| [`01-quick-start`](01-quick-start/demo.php) | `FitReader::activity()`; sport, distance, duration |
| [`02-eager-reading`](02-eager-reading/demo.php) | iterate `sessions → records`; `Record` accessors, `position()`/`GeoPoint`, `heartRate()` |
| [`03-streaming`](03-streaming/demo.php) | `FitReader::messages()` + `MessageKind`; tally kinds; flat-memory note |
| [`04-activity-overview`](04-activity-overview/demo.php) | `Activity` helpers, `deviceInfos`, `events`, `summary` |
| [`05-sessions-and-laps`](05-sessions-and-laps/demo.php) | `Session` accessors + per-km lap splits showing fast/slow |
| [`06-normalized-records`](06-normalized-records/demo.php) | `Session::recordsNormalized()`; raw vs normalized; fully-populated 1 Hz rows |
| [`07-custom-normalization`](07-custom-normalization/demo.php) | `FitReader::normalizer(stepSeconds/include/exclude/noFill/zeroIsInvalid/derive)` |
| [`08-gps-derived-fields`](08-gps-derived-fields/demo.php) | the five `…FromGps()` factories + smoothing |
| [`09-gpx-export`](09-gpx-export/demo.php) | `activityToGpx` raw + normalized(+derived) + `activityToGpxString` |
| [`10-csv-export`](10-csv-export/demo.php) | `activityToCsv` raw + normalized + `activityToCsvString` |
| [`11-mapbox-png`](11-mapbox-png/demo.php) | `fitToMapPng`; reads a Mapbox token, skips gracefully if absent |
| [`12-custom-report`](12-custom-report/demo.php) | lower-level `CsvWriter::buildMatrix()` + `Geo\EncodedPolyline` |
| [`13-tcx-export`](13-tcx-export/demo.php) | `activityToTcx` raw + normalized(+derived) + `activityToTcxString`; lap-aware TCX |
| [`14-kml-export`](14-kml-export/demo.php) | `activityToKml` raw + normalized + `activityToKmlString`; `gx:Track` for Google Earth |

## The Mapbox demo

`11-mapbox-png` needs a Mapbox access token to render — either in the `MAPBOX_TOKEN`
environment variable or in the `resources/mapbox-access-token.txt` file (gitignored).
Without a token it prints a note and skips.
