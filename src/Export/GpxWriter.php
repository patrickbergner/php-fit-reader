<?php

declare(strict_types=1);

namespace Emontis\FitReader\Export;

use Emontis\FitReader\Activity\Activity;
use Emontis\FitReader\Activity\Record;
use Emontis\FitReader\Activity\Session;

/**
 * Converts an Activity into a GPX 1.1 document. One <trk> per session;
 * each session is a single <trkseg>. Sensor data is surfaced via the
 * standard Garmin `TrackPointExtension/v1` namespace (atemp, wtemp,
 * depth, hr, cad — emitted in schema order) plus a bare <power> element
 * which Strava / Garmin Connect / RideWithGPS all recognize. Two derived
 * sibling elements `<speed_kmh>` and `<pace_min_per_km>` carry GPS-based
 * speed and pace whenever the input records carry those fields.
 *
 * The caller controls which records are written per session via the
 * `$resolveRecords` callback (`fn(Session): iterable<Record>`). Default
 * is the session's raw records — pass a {@see \Emontis\FitReader\Activity\ContinuousTimelineNormalizer}-backed
 * closure to get the historical behavior (1-second timeline with forward-
 * filled fields and GPS-derived `speed_kmh` / `pace_min_per_km`); see
 * {@see \Emontis\FitReader\FitReader::activityToGpx()} for the default recipe.
 */
final class GpxWriter
{
    public const NS_GPX     = 'http://www.topografix.com/GPX/1/1';
    public const NS_GPX_TPX = 'http://www.garmin.com/xmlschemas/TrackPointExtension/v1';
    /** Our own namespace for derived/custom fields that the GPX 1.1 XSD doesn't cover. */
    public const NS_FITR    = 'https://emontis.com/xmlschemas/fit-reader/v1';

    /**
     * @param (callable(Session): iterable<Record>)|null $resolveRecords
     */
    public function toString(Activity $activity, ?string $name = null, ?callable $resolveRecords = null): string
    {
        $name ??= $this->defaultName($activity);
        $resolveRecords ??= static fn (Session $s): array => $s->records;

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('gpx');
        $xml->writeAttribute('version', '1.1');
        $xml->writeAttribute('creator', 'emontis/fit-reader');
        $xml->writeAttribute('xmlns', self::NS_GPX);
        $xml->writeAttribute('xmlns:gpxtpx', self::NS_GPX_TPX);
        $xml->writeAttribute('xmlns:fitr', self::NS_FITR);
        $xml->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->writeAttribute(
            'xsi:schemaLocation',
            self::NS_GPX . ' http://www.topografix.com/GPX/1/1/gpx.xsd ' .
            self::NS_GPX_TPX . ' http://www.garmin.com/xmlschemas/TrackPointExtensionv1.xsd',
        );

        $xml->startElement('metadata');
        $xml->writeElement('name', $name);
        $created = $activity->timeCreated;
        if ($created !== null) {
            $xml->writeElement('time', $created->format('Y-m-d\TH:i:s\Z'));
        }
        $xml->endElement();

        foreach ($activity->sessions as $i => $session) {
            $xml->startElement('trk');
            $xml->writeElement(
                'name',
                sprintf('%s — session %d (%s)', $name, $i + 1, $session->sport ?? 'unknown'),
            );
            if ($session->sport !== null) {
                $xml->writeElement('type', $session->sport);
            }

            $xml->startElement('trkseg');
            foreach ($resolveRecords($session) as $record) {
                $pos = $record->position;
                if ($pos === null) {
                    continue;
                }
                $this->writeTrackPoint($xml, $record, $pos->lat, $pos->lng);
            }
            $xml->endElement(); // trkseg
            $xml->endElement(); // trk
        }

        $xml->endElement(); // gpx
        $xml->endDocument();
        return $xml->outputMemory();
    }

    /**
     * @param (callable(Session): iterable<Record>)|null $resolveRecords
     */
    public function writeFile(Activity $activity, string $path, ?string $name = null, ?callable $resolveRecords = null): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create GPX target directory: {$dir}");
        }
        $bytes = file_put_contents($path, $this->toString($activity, $name, $resolveRecords));
        if ($bytes === false) {
            throw new \RuntimeException("Cannot write GPX to: {$path}");
        }
    }

    private function writeTrackPoint(\XMLWriter $xml, Record $r, float $lat, float $lng): void
    {
        $xml->startElement('trkpt');
        $xml->writeAttribute('lat', self::fmtCoord($lat));
        $xml->writeAttribute('lon', self::fmtCoord($lng));

        $alt = $r->altitude;
        if ($alt !== null) {
            $xml->writeElement('ele', self::fmtFloat($alt, 2));
        }
        $ts = $r->timestamp;
        if ($ts !== null) {
            $xml->writeElement('time', $ts->format('Y-m-d\TH:i:s\Z'));
        }

        // TrackPointExtension v1 fields (atemp, wtemp, depth, hr, cad), then
        // <power> and the derived <speed_kmh> / <pace_min_per_km> as separate
        // sibling extensions. wtemp isn't present in the FIT record message
        // so it's never emitted.
        $atemp    = $r->temperature;
        $depth    = $r->depth;
        $hr       = $r->heartRate;
        $cad      = $r->cadence;
        $power    = $r->power;
        $speedKmh = self::asFloat($r->field('speed_kmh'));
        $paceMpk  = self::asFloat($r->field('pace_min_per_km'));

        $hasTpx     = $atemp !== null || $depth !== null || $hr !== null || $cad !== null;
        $hasPower   = $power !== null;
        $hasDerived = $speedKmh !== null || $paceMpk !== null;
        if (!$hasTpx && !$hasPower && !$hasDerived) {
            $xml->endElement(); // trkpt
            return;
        }

        $xml->startElement('extensions');
        if ($hasTpx) {
            $xml->startElement('gpxtpx:TrackPointExtension');
            if ($atemp !== null) $xml->writeElement('gpxtpx:atemp', (string) $atemp);
            if ($depth !== null) $xml->writeElement('gpxtpx:depth', self::fmtFloat($depth, 2));
            if ($hr !== null)    $xml->writeElement('gpxtpx:hr',    (string) $hr);
            if ($cad !== null)   $xml->writeElement('gpxtpx:cad',   (string) $cad);
            $xml->endElement();
        }
        // Bare <power> intentionally lives in the default (GPX) namespace,
        // which technically violates the gpx.xsd extensions wildcard
        // (`namespace="##other"`). Strava, Garmin Connect, and RideWithGPS
        // all auto-import power from this exact element name + position,
        // so we accept the strict-validation warning in exchange for
        // interop. Our own derived fields go in NS_FITR — XSD-clean.
        if ($hasPower) {
            $xml->writeElement('power', (string) $power);
        }
        if ($speedKmh !== null) {
            $xml->writeElementNs('fitr', 'speed_kmh', null, self::fmtFloat($speedKmh, 2));
        }
        if ($paceMpk !== null) {
            $xml->writeElementNs('fitr', 'pace_min_per_km', null, self::fmtFloat($paceMpk, 2));
        }
        $xml->endElement(); // extensions

        $xml->endElement(); // trkpt
    }

    private function defaultName(Activity $activity): string
    {
        $sport = isset($activity->sessions[0]) ? ($activity->sessions[0]->sport ?? 'activity') : 'activity';
        $when  = $activity->timeCreated?->format('Y-m-d H:i') ?? '';
        return trim(sprintf('%s %s', ucfirst($sport), $when));
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
}
