# Flexible and Interoperable Data Transfer (FIT) File Reader

Read Garmin FIT activity files into PHP objects — in streaming or eager mode. Export to CSV, GPX, KML, TCX or a Mapbox PNG.

Supports collapsing the data stream onto a continuous step-second timeline for easier processing.

## What is a FIT file?

**FIT** is the binary container Garmin/ANT bike computers, GPS watches, and indoor trainers write out after each workout. A single `.fit` file holds the GPS track, per-second sensor readings (heart rate, cadence, power, temperature), laps, events, device info, and a summary — all in one self-describing stream.

Strava, Garmin Connect, RideWithGPS, TrainingPeaks and most other fitness platforms read FIT. This library decodes those files in PHP.

## Install

```bash
composer require emontis/fit-reader
```

Requires PHP 8.2+ and nothing else for simply reading FIT into PHP objects or exporting to CSV.

Additionally requires the `xmlwriter` extension for the GPX/KML/TCX exporters. The Mapbox PNG generator uses the `curl` extension.

## Quick start

```php
use Emontis\FitReader\FitReader;

$activity = FitReader::activity('ride.fit');
echo $activity->sessions[0]->sport(), "\n";
// >> "cycling"
```

## Demos

The [`demos`](demos/) directory is a progressive tour of the public API against a sample FIT file. See the [`README.md`](demos/README.md) there for more information.

## Two ways to read

### Eager

Use `FitReader::activity($path)`.

Returns the full typed [`Activity`](src/Activity/Activity.php) in memory. Best for working with single, rather short activities or if memory usage is not of concern, i.e., no massive parallel processing is done. Typical file sizes are 70–400 KB.

```php
foreach (FitReader::activity('ride.fit')->sessions as $session) {
    printf("%s: %.1f km in %.0f min\n",
        $session->sport(),
        $session->totalDistance() / 1000.0,
        $session->totalTimerTime() / 60.0,
    );

    foreach ($session->records as $r) {
        printf("  %s  %.5f,%.5f  hr=%s\n",
            $r->timestamp()->format('H:i:s'),
            $r->position()->lat ?? '—',
            $r->position()->lng ?? '—',
            $r->heartRate() ?? '—',
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
        $r->timestamp()->format('H:i:s'),
        $r->field('speed_kmh') ?? '—',
        $r->heartRate() ?? '—',
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

## Exporting

### GPS Exchange Format (GPX) 1.1

By default the raw records are written. Pass `normalize: true` for the default forward/back-filled 1-second timeline, or `normalize: [...]` to configure the [`ContinuousTimelineNormalizer`](src/Activity/ContinuousTimelineNormalizer.php) — all as described above.

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

### Comma-separated values (CSV)

By default the raw records are written. Pass `normalize: true` for the default forward/back-filled 1-second timeline, or `normalize: [...]` to configure the [`ContinuousTimelineNormalizer`](src/Activity/ContinuousTimelineNormalizer.php) — all as described above.

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

## Domain types

The eager facade is a small tree of `readonly` objects.

- **[`Activity`](src/Activity/Activity.php)** — `fileId`, `fileCreator`, `sessions[]`, `deviceInfos[]`, `events[]`, `summary`. Helpers: `manufacturer()`, `timeCreated()`, `numSessions()`, `totalTimerTime()`.
- **[`Session`](src/Activity/Session.php)** — `summary`, `laps[]`, `records[]` (flattened across laps for convenience). Typed accessors: `sport()`, `subSport()`, `startTime()`, `totalDistance()`, `totalTimerTime()`, `totalElapsedTime()`, `avgHeartRate()`, `maxHeartRate()`, `avgCadence()`, `maxCadence()`, `avgPower()`, `maxPower()`, `totalCalories()`, `totalAscent()`, `totalDescent()`. `recordsNormalized()` for the 1-second forward/back-filled view.
- **[`Lap`](src/Activity/Lap.php)** — `summary`, `records[]`, sport/start/totals accessors.
- **[`Record`](src/Activity/Record.php)** — `timestamp()`, `position()`, `distance()`, `speed()`, `altitude()`, `heartRate()`, `cadence()`, `power()`, `temperature()`, `depth()`, `grade()`, `gpsAccuracy()`. Escape hatches: `field(name, default)` and `all()` return the raw resolved fields.
- **[`GeoPoint`](src/Value/GeoPoint.php)** — `lat`, `lng` floats, in decimal degrees.

Missing values are surfaced as PHP `null` — the FIT base-type invalid sentinels (`0xFFFFFFFF` and friends) are translated for you.

## Status

What's in scope today:

- **Read-only** decoding of **FIT activity files** (`file_id.type == 4`).
- Support for **all FIT message types**, now and **in the future** (see the "Regenerating the Profile" subsection).
- Comma-separated values (**CSV**) export
- GPS Exchange Format 1.1 (**GPX**) export
- Keyhole Markup Language 2.2 (**KML**) export
- Garmin Training Center v2 (**TCX**) export
- **Mapbox** PNG renderer.
- Collapsing the data stream onto a **continuous step-second timeline** for easier processing and with pluggable derivers.

What isn't:

- **No FIT writer.** A small writer exists under `tests/Support/SyntheticFit/` to generate test fixtures, but it's intentionally test-scoped and not part of the public API.
- **Course, workout, monitoring, and other FIT file kinds** parse fine via the streaming `messages()` API but aren't projected into a typed object.
- **64-bit PHP only** (`PHP_INT_SIZE = 8`). Big-endian definition messages are supported per the per-definition architecture byte. `uint64` values above `PHP_INT_MAX` are surfaced as strings.

## License

MIT

## For library developers

### Architecture

```
┌─────────────────────────────────────────────────────────┐
│ (6.) Exporters (CSV, GPX, KML, TCX, Mapbox)             │  (only if needed)
├─────────────────────────────────────────────────────────┤
│  5.  Activity facade (Activity, Session, Lap, Record…)  │  eager API, typed domain
├─────────────────────────────────────────────────────────┤
│  4.  Message decoder (resolves Profile, scales values)  │  streamed API, Generator<Message>
├─────────────────────────────────────────────────────────┤
│  3.  Record decoder (definition + data → raw Message)   │
├─────────────────────────────────────────────────────────┤
│  2.  Binary stream (CRC, endian-aware reads)            │
├─────────────────────────────────────────────────────────┤
│  1.  PHP file handle / string                           │
└─────────────────────────────────────────────────────────┘
```

Each layer is independently testable. Layer 4 is the streaming entry point; layer 5 is the eager facade.

### Repo layout

- [`src`](src/) — library source with the [`FitReader`](src/FitReader.php) entry point.
- [`tests`](tests/) — unit and integration tests.
- [`bin`](bin/) — helper scripts (see below).
- [`resources`](resources/) — additional resources like the FIT Profile XLSX.
- [`samples`](samples/) — put your own, real `.fit` files here for integration test consumption (gitignored)
- [`samples/default`](samples/default/) — synthetic test files (see below).
- [`demos`](demos/) — a progressive, runnable tour of the public API (run all with `bash demos/run.sh`).

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

### Running tests

```bash
run-tests.sh                          # full suite
run-tests.sh --testsuite unit         # unit tests only
run-tests.sh --testsuite integration  # integration tests only
```

Produces under `build/`:

- `test-report.txt` — testdox plain-text summary
- `test-report-junit.xml` — JUnit XML for CI
- `test-output.txt` — combined stdout + stderr + PHP error log of the phpunit run
- `integration-tests/<sample>.md` — per-sample human-readable report
- `integration-tests/<sample>.gpx`, `.csv` — GPX and CSV exports
- `integration-tests/<sample>.png` — Mapbox map, **only generated for samples under `samples/` (not `samples/default/`)** and only when a Mapbox token is available — in the `MAPBOX_TOKEN` env var, or `resources/mapbox-access-token.txt` file (gitignored).

### Contributing

- Public API lives on [`FitReader`](src/FitReader.php) — add new entry points there.
- New exporters belong under [`src/Export`](`src/Export/`).
