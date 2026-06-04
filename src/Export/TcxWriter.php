<?php

declare(strict_types=1);

namespace Emontis\FitReader\Export;

use Emontis\FitReader\Activity\Activity;
use Emontis\FitReader\Activity\Lap;
use Emontis\FitReader\Activity\Record;
use Emontis\FitReader\Activity\Session;
use Emontis\FitReader\Profile\Profile;

/**
 * Converts an Activity into a Garmin Training Center XML (TCX) v2 document.
 * One <Activity> per session, one <Lap> per session lap (a single synthetic
 * lap when a session reports none), one <Trackpoint> per record.
 *
 * Unlike GPX, TCX is lap- and summary-aware: each <Lap> carries the session's
 * aggregates (total time/distance, max speed, calories, avg/max HR, cadence)
 * straight from the lap summary, and sensor channels live in their proper
 * homes — HR/Cadence/Distance/Altitude in the base schema, Speed/Watts in the
 * Garmin `ActivityExtension/v2` (ax:) namespace, which is XSD-clean (no bare
 * <power> sibling like GPX needs).
 *
 * TCX's `Sport` attribute is a closed enum (Running/Biking/Other), so the
 * richer FIT sport/sub_sport is mapped down to it AND preserved verbatim in
 * the schema-valid <Notes> element so nothing is lost when it collapses to
 * `Other`.
 *
 * The caller controls which records become trackpoints per lap via the
 * `$resolveRecords` callback (`fn(Lap): iterable<Record>`); the default is the
 * lap's own raw records. See {@see \Emontis\FitReader\FitReader::activityToTcx()}.
 */
final class TcxWriter
{
    public const NS_TCX = 'http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2';
    public const NS_AX  = 'http://www.garmin.com/xmlschemas/ActivityExtension/v2';

    private const AUTHOR_NAME    = 'emontis/fit-reader';
    private const AUTHOR_VERSION = [1, 0];
    private const AUTHOR_PART_NUMBER = '000-00000-00';

    /**
     * @param (callable(Lap): iterable<Record>)|null $resolveRecords
     */
    public function toString(Activity $activity, ?callable $resolveRecords = null): string
    {
        $resolveRecords ??= static fn (Lap $l): array => $l->records;

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('TrainingCenterDatabase');
        $xml->writeAttribute('xmlns', self::NS_TCX);
        $xml->writeAttribute('xmlns:ax', self::NS_AX);
        $xml->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->writeAttribute(
            'xsi:schemaLocation',
            self::NS_TCX . ' http://www.garmin.com/xmlschemas/TrainingCenterDatabasev2.xsd ' .
            self::NS_AX . ' http://www.garmin.com/xmlschemas/ActivityExtensionv2.xsd',
        );

        $device = self::deviceIdentity($activity);

        $xml->startElement('Activities');
        foreach ($activity->sessions as $session) {
            $this->writeActivity($xml, $session, $resolveRecords, $device);
        }
        $xml->endElement(); // Activities

        $this->writeAuthor($xml);

        $xml->endElement(); // TrainingCenterDatabase
        $xml->endDocument();
        return $xml->outputMemory();
    }

    /**
     * @param (callable(Lap): iterable<Record>)|null $resolveRecords
     */
    public function writeFile(Activity $activity, string $path, ?callable $resolveRecords = null): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create TCX target directory: {$dir}");
        }
        $bytes = file_put_contents($path, $this->toString($activity, $resolveRecords));
        if ($bytes === false) {
            throw new \RuntimeException("Cannot write TCX to: {$path}");
        }
    }

    /**
     * @param callable(Lap): iterable<Record> $resolveRecords
     * @param array{name: string, unitId: int, productId: int, version: array{int, int}}|null $device
     */
    private function writeActivity(\XMLWriter $xml, Session $session, callable $resolveRecords, ?array $device): void
    {
        $xml->startElement('Activity');
        $xml->writeAttribute('Sport', self::mapSport($session->sport()));

        // Id (required xsd:dateTime) — session start, else first record, else epoch.
        $xml->writeElement('Id', self::timeOrFallback($session->startTime(), $session->records));

        // One <Lap> per lap; synthesize a single lap from the session when none.
        $laps = $session->laps !== [] ? $session->laps : [new Lap($session->summary, $session->records)];
        foreach ($laps as $lap) {
            $this->writeLap($xml, $lap, $resolveRecords);
        }

        // <Notes> follows the <Lap> list per the Activity_t sequence. Carry the
        // original FIT sport/sub_sport so it survives the lossy Sport mapping.
        $notes = self::sportNotes($session);
        if ($notes !== null) {
            $xml->writeElement('Notes', $notes);
        }

        // <Creator> (the recording device) follows <Notes>, when known.
        if ($device !== null) {
            $this->writeCreator($xml, $device);
        }

        $xml->endElement(); // Activity
    }

    /**
     * @param array{name: string, unitId: int, productId: int, version: array{int, int}} $device
     */
    private function writeCreator(\XMLWriter $xml, array $device): void
    {
        $xml->startElement('Creator');
        $xml->writeAttribute('xsi:type', 'Device_t');
        $xml->writeElement('Name', $device['name']);
        $xml->writeElement('UnitId', (string) $device['unitId']);
        $xml->writeElement('ProductID', (string) $device['productId']);
        $xml->startElement('Version');
        $xml->writeElement('VersionMajor', (string) $device['version'][0]);
        $xml->writeElement('VersionMinor', (string) $device['version'][1]);
        $xml->writeElement('BuildMajor', '0');
        $xml->writeElement('BuildMinor', '0');
        $xml->endElement(); // Version
        $xml->endElement(); // Creator
    }

    private function writeAuthor(\XMLWriter $xml): void
    {
        $xml->startElement('Author');
        $xml->writeAttribute('xsi:type', 'Application_t');
        $xml->writeElement('Name', self::AUTHOR_NAME);
        $xml->startElement('Build');
        $xml->startElement('Version');
        $xml->writeElement('VersionMajor', (string) self::AUTHOR_VERSION[0]);
        $xml->writeElement('VersionMinor', (string) self::AUTHOR_VERSION[1]);
        $xml->writeElement('BuildMajor', '0');
        $xml->writeElement('BuildMinor', '0');
        $xml->endElement(); // Version
        $xml->endElement(); // Build
        $xml->writeElement('LangID', 'en');
        $xml->writeElement('PartNumber', self::AUTHOR_PART_NUMBER);
        $xml->endElement(); // Author
    }

    /** @param callable(Lap): iterable<Record> $resolveRecords */
    private function writeLap(\XMLWriter $xml, Lap $lap, callable $resolveRecords): void
    {
        $xml->startElement('Lap');
        $xml->writeAttribute('StartTime', self::timeOrFallback($lap->startTime(), $lap->records));

        // Required elements (schema sequence + minOccurs=1), defaulted to 0 when absent.
        $xml->writeElement('TotalTimeSeconds', self::fmtFloat($lap->totalTimerTime() ?? $lap->totalElapsedTime() ?? 0.0, 2));
        $xml->writeElement('DistanceMeters', self::fmtFloat($lap->totalDistance() ?? 0.0, 2));

        $maxSpeed = self::asFloat($lap->field('enhanced_max_speed') ?? $lap->field('max_speed'));
        if ($maxSpeed !== null) {
            $xml->writeElement('MaximumSpeed', self::fmtFloat($maxSpeed, 3));
        }

        $xml->writeElement('Calories', (string) (self::asInt($lap->field('total_calories')) ?? 0));

        $avgHr = self::asInt($lap->field('avg_heart_rate'));
        if ($avgHr !== null && $avgHr > 0) {
            $xml->startElement('AverageHeartRateBpm');
            $xml->writeElement('Value', (string) $avgHr);
            $xml->endElement();
        }
        $maxHr = self::asInt($lap->field('max_heart_rate'));
        if ($maxHr !== null && $maxHr > 0) {
            $xml->startElement('MaximumHeartRateBpm');
            $xml->writeElement('Value', (string) $maxHr);
            $xml->endElement();
        }

        $xml->writeElement('Intensity', 'Active'); // required

        $avgCad = self::asInt($lap->field('avg_cadence'));
        if ($avgCad !== null && $avgCad >= 0 && $avgCad <= 254) {
            $xml->writeElement('Cadence', (string) $avgCad);
        }

        $xml->writeElement('TriggerMethod', 'Manual'); // required

        // <Track> only when at least one trackpoint exists (minOccurs=1 inside).
        $trackOpen = false;
        foreach ($resolveRecords($lap) as $record) {
            if ($record->timestamp() === null) {
                continue; // Trackpoint/Time is required by the schema
            }
            if (!$trackOpen) {
                $xml->startElement('Track');
                $trackOpen = true;
            }
            $this->writeTrackpoint($xml, $record);
        }
        if ($trackOpen) {
            $xml->endElement(); // Track
        }

        $this->writeLapExtensions($xml, $lap);

        $xml->endElement(); // Lap
    }

    private function writeTrackpoint(\XMLWriter $xml, Record $r): void
    {
        $xml->startElement('Trackpoint');
        $xml->writeElement('Time', self::isoZ($r->timestamp())); // guaranteed non-null by caller

        $pos = $r->position();
        if ($pos !== null) {
            $xml->startElement('Position');
            $xml->writeElement('LatitudeDegrees', self::fmtCoord($pos->lat));
            $xml->writeElement('LongitudeDegrees', self::fmtCoord($pos->lng));
            $xml->endElement();
        }

        $alt = $r->altitude();
        if ($alt !== null) {
            $xml->writeElement('AltitudeMeters', self::fmtFloat($alt, 2));
        }
        $dist = $r->distance();
        if ($dist !== null) {
            $xml->writeElement('DistanceMeters', self::fmtFloat($dist, 2));
        }
        $hr = $r->heartRate();
        if ($hr !== null) {
            $xml->startElement('HeartRateBpm');
            $xml->writeElement('Value', (string) $hr);
            $xml->endElement();
        }
        $cad = $r->cadence();
        if ($cad !== null && $cad >= 0 && $cad <= 254) {
            $xml->writeElement('Cadence', (string) $cad);
        }

        // ax:TPX — Speed (m/s; native FIT speed, else derived speed_kmh→m/s) and Watts.
        $speedMps = $r->speed() ?? self::derivedSpeedMps($r);
        $watts    = $r->power();
        if ($speedMps !== null || $watts !== null) {
            $xml->startElement('Extensions');
            $xml->startElementNs('ax', 'TPX', null);
            if ($speedMps !== null) {
                $xml->writeElementNs('ax', 'Speed', null, self::fmtFloat($speedMps, 3));
            }
            if ($watts !== null) {
                $xml->writeElementNs('ax', 'Watts', null, (string) $watts);
            }
            $xml->endElement(); // TPX
            $xml->endElement(); // Extensions
        }

        $xml->endElement(); // Trackpoint
    }

    private function writeLapExtensions(\XMLWriter $xml, Lap $lap): void
    {
        $avgSpeed = self::asFloat($lap->field('enhanced_avg_speed') ?? $lap->field('avg_speed'));
        $avgWatts = self::asInt($lap->field('avg_power'));
        $maxWatts = self::asInt($lap->field('max_power'));
        if ($avgSpeed === null && $avgWatts === null && $maxWatts === null) {
            return;
        }
        $xml->startElement('Extensions');
        $xml->startElementNs('ax', 'LX', null);
        // LX sequence: AvgSpeed, …, AvgWatts, MaxWatts.
        if ($avgSpeed !== null) {
            $xml->writeElementNs('ax', 'AvgSpeed', null, self::fmtFloat($avgSpeed, 3));
        }
        if ($avgWatts !== null) {
            $xml->writeElementNs('ax', 'AvgWatts', null, (string) $avgWatts);
        }
        if ($maxWatts !== null) {
            $xml->writeElementNs('ax', 'MaxWatts', null, (string) $maxWatts);
        }
        $xml->endElement(); // LX
        $xml->endElement(); // Extensions
    }

    private static function mapSport(?string $sport): string
    {
        return match ($sport) {
            'running' => 'Running',
            'cycling' => 'Biking',
            default   => 'Other',
        };
    }

    /**
     * Assemble the recording-device identity for <Creator> from the decoded
     * Activity. Precedence: the primary `device_info` (the "creator" device),
     * then `file_id`, then `file_creator`. Returns null when there isn't even
     * a name to show, so the (optional) <Creator> can be omitted.
     *
     * @return array{name: string, unitId: int, productId: int, version: array{int, int}}|null
     */
    private static function deviceIdentity(Activity $activity): ?array
    {
        $primary = self::primaryDeviceInfo($activity->deviceInfos);
        $fileId  = $activity->fileId;
        $creator = $activity->fileCreator ?? [];

        $product      = $fileId['product'] ?? $primary['product'] ?? null;
        $manufacturer = self::firstString([$fileId['manufacturer'] ?? null, $primary['manufacturer'] ?? null]);

        // Name precedence: product_name → resolved garmin_product enum label
        // (the file often carries only the numeric id) → a string product
        // label already on the field → "manufacturer product" → manufacturer.
        $name = self::firstString([$primary['product_name'] ?? null, $fileId['product_name'] ?? null]);
        if ($name === null && $manufacturer === 'garmin' && is_int($product)) {
            $name = Profile::enumLabel('garmin_product', $product);
        }
        if ($name === null) {
            if (is_string($product)) {
                $name = $product;
            } elseif ($manufacturer !== null) {
                $name = is_int($product) ? $manufacturer . ' ' . $product : $manufacturer;
            }
        }
        if ($name === null) {
            return null;
        }

        $serial = self::asInt($fileId['serial_number'] ?? $primary['serial_number'] ?? null);

        return [
            'name'      => $name,
            'unitId'    => ($serial !== null && $serial >= 0) ? $serial : 0,
            'productId' => (is_int($product) && $product >= 0 && $product <= 65535) ? $product : 0,
            'version'   => self::versionParts($primary['software_version'] ?? $creator['software_version'] ?? null),
        ];
    }

    /**
     * The primary device_info — the one whose device_index resolves to
     * 'creator' (numeric 0), else the first entry, else an empty array.
     *
     * @param array<int, array<string|int, mixed>> $deviceInfos
     * @return array<string|int, mixed>
     */
    private static function primaryDeviceInfo(array $deviceInfos): array
    {
        foreach ($deviceInfos as $di) {
            $idx = $di['device_index'] ?? null;
            if ($idx === 'creator' || $idx === 0) {
                return $di;
            }
        }
        return $deviceInfos[0] ?? [];
    }

    /** @param array<int, mixed> $candidates */
    private static function firstString(array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '') {
                return $c;
            }
        }
        return null;
    }

    /**
     * Map a FIT software_version to TCX [VersionMajor, VersionMinor]. Garmin
     * encodes the version in hundredths, so the two digits after the point are
     * the minor (firmware 27.09 → major 27, minor 9).
     *
     * @return array{int, int}
     */
    private static function versionParts(mixed $sw): array
    {
        if (is_float($sw)) {
            $h = (int) round($sw * 100);
        } elseif (is_int($sw)) {
            $h = $sw;
        } else {
            return [0, 0];
        }
        return [intdiv($h, 100), $h % 100];
    }

    private static function sportNotes(Session $s): ?string
    {
        $parts = [];
        if ($s->sport() !== null) {
            $parts[] = 'sport=' . $s->sport();
        }
        if ($s->subSport() !== null) {
            $parts[] = 'sub_sport=' . $s->subSport();
        }
        return $parts === [] ? null : 'FIT ' . implode(', ', $parts);
    }

    private static function derivedSpeedMps(Record $r): ?float
    {
        $kmh = self::asFloat($r->field('speed_kmh'));
        return $kmh === null ? null : $kmh / 3.6;
    }

    /** @param Record[] $records */
    private static function timeOrFallback(?\DateTimeImmutable $t, array $records): string
    {
        $t ??= self::firstTimestamp($records);
        $t ??= new \DateTimeImmutable('@0', new \DateTimeZone('UTC'));
        return self::isoZ($t);
    }

    /** @param Record[] $records */
    private static function firstTimestamp(array $records): ?\DateTimeImmutable
    {
        foreach ($records as $r) {
            $ts = $r->timestamp();
            if ($ts !== null) {
                return $ts;
            }
        }
        return null;
    }

    private static function isoZ(\DateTimeInterface $t): string
    {
        return $t->format('Y-m-d\TH:i:s\Z');
    }

    private static function fmtCoord(float $v): string
    {
        // 8 decimals fully preserves the FIT semicircle resolution (~1 cm step).
        return rtrim(rtrim(sprintf('%.8f', $v), '0'), '.');
    }

    private static function fmtFloat(float $v, int $decimals): string
    {
        $s = sprintf('%.' . $decimals . 'f', $v);
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
        return $s === '' || $s === '-' ? '0' : $s;
    }

    private static function asFloat(mixed $v): ?float
    {
        if (is_float($v)) return $v;
        if (is_int($v))   return (float) $v;
        return null;
    }

    private static function asInt(mixed $v): ?int
    {
        if (is_int($v))   return $v;
        if (is_float($v)) return (int) round($v);
        return null;
    }
}
