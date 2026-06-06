# Flexible and Interoperable Data Transfer (FIT) File Reader

[![CI](https://github.com/patrickbergner/php-fit-reader/actions/workflows/ci.yml/badge.svg)](https://github.com/patrickbergner/php-fit-reader/actions/workflows/ci.yml)

Read Garmin FIT activity files into typed PHP objects in streaming or eager mode, then analyze, transform, visualize and work with the data. The library allows collapsing the data stream onto a continuous step-second timeline that unites position and sensor data for easier processing.

Convenience exporters to other formats are included as well (CSV, GPX 1.1, KML 2.2, TCX v2, GeoJSON, Mapbox PNG).

## What is a FIT file?

**FIT** is the binary container Garmin bike computers, GPS watches, indoor trainers and other equipment write out. A single `.fit` file can hold a GPS track, sensor readings (heart rate, cadence, power, temperature, …), laps, events, device info, and a summary — all in one binary stream.

Strava, Garmin Connect, RideWithGPS, TrainingPeaks and many other fitness platforms and tools talk FIT as well. This library decodes it in PHP with PHPStan **level 10** compliance.

## Install

```bash
composer require emontis/fit-reader
```

Requires PHP 8.4+ and nothing else to read FIT into PHP objects, analyze and work with them, and/or export to CSV or GeoJSON.

The GPX/KML/TCX exporters additionally need the `xmlwriter` extension. The Mapbox PNG generator uses the `curl` extension.

## Quick start

```php
use Emontis\FitReader\FitReader;

$activity = FitReader::activity('ride.fit');
echo $activity->sessions[0]->sport, "\n";
// >> "cycling"
```

## Demos

The [`demos`](demos/) directory is a runnable tour against a sample FIT file — reading, normalizing, analyzing, visualizing, and exporting. See its [`README`](demos/README.md) for the full list.

## Two ways to read

### Eager

Use `FitReader::activity($path)`.

Returns the full typed [`Activity`](src/Activity/Activity.php) in memory. Best for working with single, rather short activities or if memory usage is not of concern, i.e., no massive parallel processing is done. Typical file sizes are 70–400 KB.

```php
foreach (FitReader::activity('ride.fit')->sessions as $session) {
    printf("%s: %.1f km in %.0f min\n",
        $session->sport,
        $session->totalDistance / 1000.0,
        $session->totalTimerTime / 60.0,
    );

    foreach ($session->records as $r) {
        printf("  %s  %.5f,%.5f  hr=%s\n",
            $r->timestamp->format('H:i:s'),
            $r->position->lat ?? '—',
            $r->position->lng ?? '—',
            $r->heartRate ?? '—',
        );
    }
}
```

### Streaming

Use `FitReader::messages($path)`.

Yields one [`Message`](src/Message/Message.php) at a time. The file handle is closed when the generator is destroyed, so peak memory stays flat regardless of file size. Use this when you only need a slice of the data, when files are too big to hold in memory whole or massive parallel processing is done.

```php
use Emontis\FitReader\Message\MessageKind;

foreach (FitReader::messages('big-ride.fit') as $msg) {
    if ($msg->kind === MessageKind::Record) {
        echo $msg->get('timestamp')->format('c'), ' ', $msg->get('heart_rate') ?? '—', "\n";
    }
}
```

## Domain types

The eager facade is a small tree of `readonly` objects — this is what you analyze and visualize.

- **[`Activity`](src/Activity/Activity.php)** — `fileId`, `fileCreator`, `sessions[]`, `deviceInfos[]`, `events[]`, `summary`. Helpers: `manufacturer()`, `timeCreated()`, `numSessions()`, `totalTimerTime()`. Local time (FIT stores UTC): `utcOffsetSeconds()`, `localTimeCreated()`, `toLocalTime($utc)`.
- **[`Session`](src/Activity/Session.php)** — `summary`, `laps[]`, `records[]` (flattened across laps for convenience). Typed accessors: `sport()`, `subSport()`, `startTime()`, `totalDistance()`, `totalTimerTime()`, `totalElapsedTime()`, `movingTime()`, `stoppedTime()`, `avgHeartRate()`, `maxHeartRate()`, `avgCadence()`, `maxCadence()`, `avgPower()`, `maxPower()`, `totalCalories()`, `totalAscent()`, `totalDescent()`, plus average running dynamics (`avgVerticalOscillation()`, `avgStanceTime()`, `avgStepLength()`, …). `recordsNormalized()` for the 1-second forward/back-filled view.
- **[`Lap`](src/Activity/Lap.php)** — `summary`, `records[]`, sport/start/totals accessors.
- **[`Record`](src/Activity/Record.php)** — `timestamp()`, `position()`, `distance()`, `speed()`, `altitude()`, `heartRate()`, `cadence()`, `power()`, `temperature()`, `depth()`, `grade()`, `gpsAccuracy()`. Running/cycling dynamics: `verticalOscillation()`, `stanceTime()`, `stepLength()`, `verticalRatio()`, `respirationRate()`, `leftRightBalance()`, pedal torque/smoothness, … Escape hatches: `field(name, default)`, `developerField(name, default)` (Connect IQ app fields), and `all()` return the raw resolved fields.
- **[`GeoPoint`](src/Value/GeoPoint.php)** — `lat`, `lng` floats, in decimal degrees.
- **[`BoundingBox`](src/Geo/BoundingBox.php)** — `minLat`, `minLng`, `maxLat`, `maxLng` of a track, plus `center()`; see [Visualize the track](#visualize-the-track).

Missing values are surfaced as PHP `null` — the FIT base-type invalid sentinels (`0xFFFFFFFF` and friends) are translated for you.

## Normalization and derived fields

FIT writers vary. Some devices emit one dense `record` message per second with every sensor field populated. Others emit one `record` message for every position or sensor readout, potentially multiple per second and each carrying a disjoint subset of fields.

[`ContinuousTimelineNormalizer`](src/Activity/ContinuousTimelineNormalizer.php) collapses either input onto a continuous 1-second timeline with forward/back-filled fields, so you can iterate "one fully populated record per second" without worrying about which writer produced the file.

Sessions expose the normalized view via `$session->recordsNormalized()` (computed and cached on first call).

```php
foreach (FitReader::activity('ride.fit')->sessions as $session) {
    // …
    foreach ($session->recordsNormalized() as $r) {
        // …
    }
}
```

### Customization

If you want to customize the normalization with one or many of
* changing the timeline step (e.g. collapsing data every 10 seconds instead of every 1 second),
* adding derived fields (like speed or pace derived from GPS data),
* including certain fields only (whitelist),
* excluding certain fields (blacklist, processed after the inclusion whitelist),
* treating zero values of certain fields as "missing/invalid data" (not meaning empty but really `0`, like heart rate, default)
* defining fields that must not be forward/back-filled (for instantaneous metrics like speed/cadence/power where carrying forward a stale value would misrepresent reality, empty by default),

build a custom normalizer:

```php
$normalizer = FitReader::normalizer(
    stepSeconds:   10,
    zeroIsInvalid: ['heart_rate'],
    noFill:        ['power'],
    include:       ['…'],
    exclude:       ['…'],
    derive: [
        // smoothing in a 10 second moving time window
        'speed_kmh' => FitReader::kilometersPerHourFromGps(10),
        // smoothing defaults to 0 seconds (= no smoothing)
        'pace_min_per_km' => FitReader::minutesPerKilometerFromGps(),
    ],
);

foreach ($normalizer->normalize($activity->sessions[0]->records) as $r) {
    printf("%s  %s km/h  hr=%s\n",
        $r->timestamp->format('H:i:s'),
        $r->field('speed_kmh') ?? '—',
        $r->heartRate ?? '—',
    );
}
```

### Supplied sample derivers for GPS-derived fields

Five derivers are available that derive additional speed and pace data fields from GPS coordinates:

| Factory | Unit |
|---|---|
| `FitReader::metersPerSecondFromGps($s)` | m/s |
| `FitReader::kilometersPerHourFromGps($s)` | km/h |
| `FitReader::milesPerHourFromGps($s)` | mph |
| `FitReader::minutesPerKilometerFromGps($s)` | min/km |
| `FitReader::minutesPerMileFromGps($s)` | min/mi |

`$s` is the trailing-window length in seconds for smoothing (`0` = no smoothing, default).

#### Note about multi-session activities

The supplied GPS derivers are **stateful**: they track GPS history sample to sample to facilitate the desired smoothing. When one `ContinuousTimelineNormalizer` with non-zero smoothing is applied to several record sets, e.g., an `Activity` having multiple `Session`s (in a FIT sense, not HTTP), the last states of the previous session leak into the first states of the next session.

This may be desired if the `Session`s are performed back-to-back or deemed negligible because the smoothing time window is short. If this is not desired though, you can then wrap the deriver in `FitReader::perRun(fn () => …)` so the normalizer rebuilds it fresh for each `normalize()` run and no state leaks between sessions:

```php
$normalizer = FitReader::normalizer(
    derive: [
        'speed_kmh' => FitReader::perRun(fn () => FitReader::kilometersPerHourFromGps(10)),
    ],
);
```

### Implementing custom derivers

A deriver is a callable `fn(?Record $prev, Record $curr): mixed`. It runs once per output record and its return value is written to the field the deriver is registered to. `$curr` is the record being built; `$prev` is the previous output record (`null` on the first), so a deriver can work from the delta between consecutive steps.

Notes:

- Derivers run in declaration order; a later one sees fields written by earlier ones on `$curr`.
- Derived fields bypass `include`/`exclude` and override a same-named raw field.
- Like raw fields, derived values are forward/back-filled unless you list the field in `noFill` — use that for instantaneous metrics where carrying a stale value would mislead.

## Data analysis

After reading the data, compute best efforts, lap pace, training load, climbing, drift, whatever… The following examples showcase some ideas and are implemented in the demos.

**Best splits / fastest segments** ([demo](demos/11-best-splits/demo.php)). Every record carries a cumulative `distance()` and a `timestamp()`, so the fastest stretch of any length is a sliding window over those two series — your fastest 1 km, 5 km, 10 km:

```php
$session = FitReader::activity('run.fit')->sessions[0];
foreach ($session->records as $r) {
    $dist[] = $r->distance;                       // cumulative metres
    $time[] = $r->timestamp->getTimestamp();      // seconds
}
// slide a window over $dist/$time to find the min time covering ≥ N metres
```

**Heart-rate time-in-zone** ([demo](demos/12-hr-zones/demo.php)). Walk the normalized timeline and charge each second to the zone its heart rate falls in:

```php
foreach ($session->recordsNormalized() as $r) {
    $zone = zoneFor($r->heartRate, $session->maxHeartRate);
    $timeInZone[$zone]++; // one second per record on the normalized timeline
}
```

**Elevation & grade** ([demo](demos/13-elevation-grade/demo.php)). `altitude()` against cumulative `distance()` gives an elevation profile and per-segment grades; `Session::totalAscent()`/`totalDescent()` are the device's climb totals.

**Aerobic decoupling & load** ([demo](demos/14-pace-hr-decoupling/demo.php)). Compare pace-per-heartbeat (`speed()` ÷ `heartRate()`) between the first and second half for cardiac drift, and weight HR over time for a Banister TRIMP training load.

## Track visualization

Putting the track on a map rarely needs a file — it needs the right shape for your provider. The included `Geo` toolkit turns records into exactly that; see the [visualization demo](demos/15-visualization/demo.php).

**Bounds & center** — frame a static image, or `fitBounds` a web map:

```php
$bounds = FitReader::trackBounds($activity);   // ?BoundingBox over all sessions
$center = $bounds?->center();                  // GeoPoint
```

**Encoded polyline** — the path overlay both the Mapbox Static Images API and the Google Maps Static API accept (simplify first to keep the URL short):

```php
$poly = FitReader::encodedPolyline($activity->sessions[0], simplifyToleranceDegrees: 1e-4);
// Mapbox: …/static/path-4+f44({$poly})/auto/600x400?access_token=…
// Google: …/staticmap?path=enc:{$poly}&size=600x400&key=…
```

**GeoJSON** — for web map libraries (Leaflet, MapLibre, Mapbox GL, OpenLayers). One `LineString` Feature per session, in `[lng, lat]` order:

```php
$json = FitReader::activityToGeoJsonString($activity);
// or write a file: FitReader::activityToGeoJson($activity, 'ride.geojson');
```

The buildings bricks of these features are [`EncodedPolyline`](src/Geo/EncodedPolyline.php) (Google polyline), [`DouglasPeucker`](src/Geo/DouglasPeucker.php) (simplification), [`BoundingBox`](src/Geo/BoundingBox.php), and [`TrackPoints`](src/Geo/TrackPoints.php) (records → `lat,lng` pairs).

## Exporting

The library allows converting a FIT file or an already parsed/modified `Activity` to other file formats. They all support the `normalize` option described in [Normalization](#normalization-and-derived-fields).

### Comma-separated values (CSV)

By default the raw records are written. Pass `normalize: true` for the default forward/back-filled 1-second timeline, or `normalize: [...]` to configure the [`ContinuousTimelineNormalizer`](src/Activity/ContinuousTimelineNormalizer.php) — all as described in the Normalization section.

```php
// from an already processed Activity object
FitReader::activityToCsv($activity, 'ride.csv'); // raw records
FitReader::activityToCsv($activity, 'ride.csv', normalize: true); // default normalizer
FitReader::activityToCsv($activity, 'ride.csv', normalize: [ 
    'derive' => ['speed_kmh' => FitReader::kilometersPerHourFromGps(10)],
]); // records normalized with a customized normalizer

// or, directly from a FIT file
FitReader::fitToCsv('ride.fit', 'ride.csv');
```

`*ToCsvString()` variants return the CSV strings without writing a file:

```php
$csvString = FitReader::activityToCsvString($activity);
$csvString = FitReader::fitToCsvString('ride.fit');
```

#### CSV dialect

The CSV dialect is configurable: pass `csv: [...]` to override any of the `separator`, `enclosure`, `escape`, or `eol` characters (defaults: `,` `"` `''` `"\n"`). It works on every CSV method:

```php
// semicolon-delimited, CRLF line endings
FitReader::activityToCsv($activity, 'ride.csv', csv: ['separator' => ';', 'eol' => "\r\n"]);

// tab-separated string
$tsv = FitReader::activityToCsvString($activity, csv: ['separator' => "\t"]);
```

### GPS Exchange Format (GPX) 1.1

By default the raw records are written. Pass `normalize: true` for the default forward/back-filled 1-second timeline, or `normalize: [...]` to configure the [`ContinuousTimelineNormalizer`](src/Activity/ContinuousTimelineNormalizer.php) — all as described in the Normalization section.

```php
// from an already processed Activity object
FitReader::activityToGpx($activity, 'ride.gpx'); // raw records
FitReader::activityToGpx($activity, 'ride.gpx', normalize: true); // default normalizer
FitReader::activityToGpx($activity, 'ride.gpx', normalize: [ 
    'derive' => ['speed_kmh' => FitReader::kilometersPerHourFromGps(10)],
]); // records normalized with a customized normalizer

// or, directly from a FIT file
FitReader::fitToGpx('ride.fit', 'ride.gpx');
```

`*ToGpxString()` variants return the GPX XML strings without writing a file:

```php
$gpxString = FitReader::activityToGpxString($activity);
$gpxString = FitReader::fitToGpxString('ride.fit');
```

The output is GPX 1.1 with Garmin's `TrackPointExtension v1` (`atemp`, `depth`, `hr`, `cad`) in the GPX trackpoint `<extensions>` element and a sibling `<power>` element (if the activity has all these data). Strava, Garmin Connect, and RideWithGPS all auto-import these on upload.

If derived fields are added in the normalization, they are also stored in the trackpoint `<extensions>` element, in an own namespace to comply with the GPX XML schema.

#### FIT messages with sensor data but without position information

GPX is a geo position focused format. Sensor data rides inside a `<trkpt>` (trackpoint) as an extension and a trackpoint always requires `lat`/`lon` positions.

Therefore, a raw (non-normalized) export can't carry readings that have no position, and silently drops them in two cases:
1. records with no GPS fix at all (indoor, tunnels, tree cover), and
1. writers that split position and sensors into separate records at the same timestamp, where only the position point is written (without the sensors)

This is a format limitation/design decision that only normalization or exporting to another format (like CSV) can mitigate.

### Keyhole Markup Language (KML) 2.2

By default the raw records are written. Pass `normalize: true` for the default forward/back-filled 1-second timeline, or `normalize: [...]` to configure the [`ContinuousTimelineNormalizer`](src/Activity/ContinuousTimelineNormalizer.php) — all as described in the Normalization section.

```php
// from an already processed Activity object
FitReader::activityToKml($activity, 'ride.kml'); // raw records
FitReader::activityToKml($activity, 'ride.kml', normalize: true); // default normalizer
FitReader::activityToKml($activity, 'ride.kml', normalize: [ 
    'derive' => ['speed_kmh' => FitReader::kilometersPerHourFromGps(10)],
]); // records normalized with a customized normalizer

// or, directly from a FIT file
FitReader::fitToKml('ride.fit', 'ride.kml');
```

`*ToKmlString()` variants return the KML XML strings without writing a file:

```php
$kmlString = FitReader::activityToKmlString($activity);
$kmlString = FitReader::fitToKmlString('ride.fit');
```

KML is a visualization format for Google Earth/GIS, not a fitness format. Each session becomes one `<Placemark>`: a time-animatable `<gx:Track>` when records carry timestamps, or a plain `<LineString>` otherwise. Heart rate and cadence — the only channels Google Earth surfaces — ride along the track as `<gx:SimpleArrayData>` arrays when present. Coordinates use KML's `lon,lat,alt` order (the reverse of GPX).

Like GPX, KML is geo position focused: records without a GPS fix are dropped.

### Garmin Training Center (TCX) v2

By default the raw records are written. Pass `normalize: true` for the default forward/back-filled 1-second timeline, or `normalize: [...]` to configure the [`ContinuousTimelineNormalizer`](src/Activity/ContinuousTimelineNormalizer.php) — all as described in the Normalization section.

```php
// from an already processed Activity object
FitReader::activityToTcx($activity, 'ride.tcx'); // raw records
FitReader::activityToTcx($activity, 'ride.tcx', normalize: true); // default normalizer
FitReader::activityToTcx($activity, 'ride.tcx', normalize: [ 
    'derive' => ['speed_kmh' => FitReader::kilometersPerHourFromGps(10)],
]); // records normalized with a customized normalizer

// or, directly from a FIT file
FitReader::fitToTcx('ride.fit', 'ride.tcx');
```

`*ToTcxString()` variants return the TCX XML strings without writing a file:

```php
$tcxString = FitReader::activityToTcxString($activity);
$tcxString = FitReader::fitToTcxString('ride.fit');
```

Unlike GPX and KML, and close to FIT, TCX is an activity/fitness focused format and is lap- and summary-aware: one `<Activity>` per session, one `<Lap>` per lap carrying the lap summary aggregates (total time/distance, max speed, calories, avg/max HR, cadence), and one `<Trackpoint>` per record. Heart rate, cadence, distance and altitude sit in the base schema; speed and power live in Garmin's `ActivityExtension v2` namespace.

TCX's `Sport` attribute is very restricted (Running/Biking/Other), so the richer FIT sport/sub-sport is mapped down and also preserved verbatim in a `<Notes>` element.

A trackpoint only requires a timestamp, not a position — so unlike GPX and KML, TCX carries position-less sensor readings in raw mode; only records without a timestamp are dropped.

### GeoJSON (RFC 7946)

A `FeatureCollection` with one `<LineString>` per session, in `[lng, lat]` order — the interchange format web map libraries read. Like the other exporters it takes the `normalize` option, and it needs no `xmlwriter` (plain JSON).

```php
// from an already processed Activity object
FitReader::activityToGeoJson($activity, 'ride.geojson');
// or, directly from a FIT file
FitReader::fitToGeoJson('ride.fit', 'ride.geojson');
// string variants, without writing a file:
$json = FitReader::activityToGeoJsonString($activity);
$json = FitReader::fitToGeoJsonString('ride.fit');
```

See [Visualize the track](#visualize-the-track) for using it with Leaflet/MapLibre, plus the bounding-box and encoded-polyline helpers for static maps.

### Mapbox PNG Image

Render the GPS track using the Mapbox API as a 2560 pixel (2x retina) square PNG image that is zoomed/bounding-boxed to the track:

```php
$token = getenv('MAPBOX_TOKEN'); // your Mapbox access token

// from an already processed Activity object
FitReader::activityToMapPng($activity, 'ride.png', $token);
// or, directly from a FIT file
FitReader::fitToMapPng('ride.fit', 'ride.png', $token);
```

Returns `true` on success, `false` if the activity has no GPS data or the API call fails for any reason. An empty token is the one programmer-error case and throws `\InvalidArgumentException`.

This is a nice-to-have feature to have a quick visualization of the FIT file processing as part of the integration tests (see below). No further customization options will be added. The Mapbox free tier currently offers thousands of map generations per month.

## Status

What's in scope today:

- **Read-only** decoding of **FIT activity files** (`file_id.type == 4`) into typed PHP objects.
- Support for **all FIT message types**, now and **in the future** (see the "Regenerating the Profile" subsection), including **developer (Connect IQ) fields** resolved by name, base type and scale.
- Typed **running/cycling dynamics** (vertical oscillation, stance time, step length, pedal balance, …), **local time** (UTC ↔ local), and **moving vs. stopped time**.
- **Analyze** on the objects directly — best splits, HR zones, elevation/grade, aerobic decoupling (see the [demos](demos/)).
- Track **visualization** prep — bounding box/center, Douglas–Peucker simplification, Google/Mapbox **encoded polylines**.
- Collapsing the data stream onto a **continuous step-second timeline** for easier processing and with pluggable derivers.
- Convenience **exporters**: Comma-separated values (**CSV**), GPS Exchange Format 1.1 (**GPX**), Keyhole Markup Language 2.2 (**KML**), Garmin Training Center v2 (**TCX**), **GeoJSON** (RFC 7946), and a **Mapbox** PNG renderer.

What isn't:

- **No FIT writer.** A small writer exists under `util/SyntheticFit/` to generate test fixtures, but it's intentionally part of the public API.
- **Course, workout, monitoring, and other FIT file kinds** parse fine via the streaming `messages()` API but aren't projected into a typed object.
- **64-bit PHP only** (`PHP_INT_SIZE = 8`). Big-endian definition messages are supported per the per-definition architecture byte. `uint64` values above `PHP_INT_MAX` are surfaced as strings.

## Repo layout

| Path | Contents |
|---|---|
| [`demos`](demos/) | tour of the public API (run all with `demos/run.sh`) |
| [`src`](src/) | library source with the [`FitReader`](src/FitReader.php) entry point |
| [`tests`](tests/) | unit and integration tests |
| [`bin`](bin/) | helper scripts |
| [`resources`](resources/) | additional resources like the FIT Profile XLSX |
| [`resources/samples`](resources/samples/) | put your own, real `.fit` files here for integration test consumption (gitignored) |
| [`resources/samples/default`](resources/samples/default/) | synthetic integration test files |

## License

MIT

## For library developers

### Architecture

| Layer | Responsibility |
|---|---|
| 1. File reading/binary stream | CRC, endian-aware reads |
| 2. Record decoder | definition + data → raw `Message` blocks |
| 3. Message decoder | resolves Profile, scales values (provides streamed `Generator<Message>` API) |
| 4. Activity facade | `Activity`, `Session`, `Lap`, `Record`, … (provides eager, typed domain API) |
| 5. `Geo` toolkit | bounds, simplification, encoded polyline |
| (6. Exporters) | CSV, GPX, KML, TCX, GeoJSON exporters and the Mapbox PNG renderer (all optional) |

### Regenerating the FIT Profile

This requires the PHP `dom` and `zip` extensions.

The FIT Profile (message metadata, field scales/offsets, enums) is generated from Garmin's [`Profile.xlsx`](https://github.com/garmin/fit-sdk-tools/raw/refs/heads/main/Profile.xlsx) shipped in the [FIT SDK](https://github.com/garmin/fit-sdk-tools).


```bash
bin/generate-profile [path/to/Profile.xlsx, defaults to resources/Profile.xlsx]
```

Output lands in `src/Profile/Generated/` and is committed. Downstream consumers never run this — only re-run it when the SDK ships a new profile version.

### Synthetic test samples

```bash
bin/generate-default-samples
```

Writes deterministic `.fit` test cases into `samples/default/` from a scenario builder. Re-run after scenario changes.

### Running static code analysis

```bash
composer analyze
```

### Running unit tests

```bash
composer test
```

### Running integration tests

```bash
bin/run-integration-tests
```

Produces under `build/`:

- `test-report.txt` — testdox plain-text summary
- `test-report-junit.xml` — JUnit XML for CI
- `test-output.txt` — combined stdout + stderr + PHP error log of the phpunit run
- `integration-tests/<sample>.md` — per-sample human-readable report
- `integration-tests/<sample>-{raw,normalized}.{gpx,csv,tcx,kml,geojson}` — every exporter, raw and normalized
- `integration-tests/<sample>-geo.txt` — the Geo toolkit output: bounding box, center, and per-session encoded polylines
- `integration-tests/<sample>.png` — Mapbox map, **only generated for samples under `samples/` (not `samples/default/`)** and only when a Mapbox token is available — in the `MAPBOX_TOKEN` env var, or `resources/mapbox-access-token.txt` file (gitignored).

### Contributing

- Public API lives on [`FitReader`](src/FitReader.php) — add new entry points there.
- New exporters belong under [`src/Export`](src/Export/).
- Run static code analysis and the test suite before pushing.
