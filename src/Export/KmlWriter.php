<?php

declare(strict_types=1);

namespace Emontis\FitReader\Export;

use Emontis\FitReader\Activity\Activity;
use Emontis\FitReader\Activity\Record;
use Emontis\FitReader\Activity\Session;

/**
 * Converts an Activity into a Google KML 2.2 document for visualization
 * (Google Earth / GIS). One <Placemark> per session.
 *
 * KML has no fitness semantics, so this is a visualization-first exporter:
 * when records carry timestamps the track is a time-animatable `<gx:Track>`
 * (Google's `kml/ext/2.2` extension — parallel <when> and <gx:coord> lists),
 * otherwise it falls back to a plain `<LineString>`. A shared <LineStyle>
 * colors the line. Heart rate and cadence, the only channels Google Earth
 * surfaces, ride along as `<gx:SimpleArrayData>` arrays when present.
 *
 * Coordinate order is KML's `lon, lat, alt` — the reverse of GPX's lat/lon.
 *
 * The caller controls which records are drawn per session via the
 * `$resolveRecords` callback (`fn(Session): iterable<Record>`); the default is
 * the session's raw records. See {@see \Emontis\FitReader\FitReader::activityToKml()}.
 */
final class KmlWriter
{
    public const NS_KML = 'http://www.opengis.net/kml/2.2';
    public const NS_GX  = 'http://www.google.com/kml/ext/2.2';

    private const SCHEMA_ID = 'fitr_track';
    private const STYLE_ID   = 'fitr_line';

    /**
     * @param (callable(Session): iterable<Record>)|null $resolveRecords
     */
    public function toString(Activity $activity, ?string $name = null, ?callable $resolveRecords = null): string
    {
        $name           ??= $this->defaultName($activity);
        $resolveRecords ??= static fn (Session $s): array => $s->records;

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('kml');
        $xml->writeAttribute('xmlns', self::NS_KML);
        $xml->writeAttribute('xmlns:gx', self::NS_GX);

        $xml->startElement('Document');
        $xml->writeElement('name', $name);

        $xml->startElement('Style');
        $xml->writeAttribute('id', self::STYLE_ID);
        $xml->startElement('LineStyle');
        $xml->writeElement('color', 'ff0000ff'); // aabbggrr — opaque red
        $xml->writeElement('width', '4');
        $xml->endElement(); // LineStyle
        $xml->endElement(); // Style

        // Schema declaring the sensor arrays the gx:Track ExtendedData reference.
        $xml->startElement('Schema');
        $xml->writeAttribute('id', self::SCHEMA_ID);
        $this->writeArrayField($xml, 'heartrate', 'int');
        $this->writeArrayField($xml, 'cadence', 'int');
        $xml->endElement(); // Schema

        foreach ($activity->sessions as $i => $session) {
            $this->writePlacemark(
                $xml,
                $session,
                sprintf('%s — session %d (%s)', $name, $i + 1, $session->sport() ?? 'unknown'),
                $resolveRecords,
            );
        }

        $xml->endElement(); // Document
        $xml->endElement(); // kml
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
            throw new \RuntimeException("Cannot create KML target directory: {$dir}");
        }
        $bytes = file_put_contents($path, $this->toString($activity, $name, $resolveRecords));
        if ($bytes === false) {
            throw new \RuntimeException("Cannot write KML to: {$path}");
        }
    }

    /** @param callable(Session): iterable<Record> $resolveRecords */
    private function writePlacemark(\XMLWriter $xml, Session $session, string $name, callable $resolveRecords): void
    {
        // Records with a position, split out the timed ones for the gx:Track.
        $located = [];
        $timed   = [];
        foreach ($resolveRecords($session) as $r) {
            if ($r->position() === null) {
                continue;
            }
            $located[] = $r;
            if ($r->timestamp() !== null) {
                $timed[] = $r;
            }
        }
        if ($located === []) {
            return; // no GPS in this session — nothing to draw
        }

        $xml->startElement('Placemark');
        $xml->writeElement('name', $name);
        $xml->writeElement('styleUrl', '#' . self::STYLE_ID);

        if ($timed !== []) {
            $this->writeGxTrack($xml, $timed);
        } else {
            $this->writeLineString($xml, $located);
        }

        $xml->endElement(); // Placemark
    }

    /** @param Record[] $fixes each has a non-null position() and timestamp() */
    private function writeGxTrack(\XMLWriter $xml, array $fixes): void
    {
        $xml->startElementNs('gx', 'Track', null);
        // clampToGround so a missing/garbage altitude can't sink the track.
        $xml->writeElement('altitudeMode', 'clampToGround');

        foreach ($fixes as $r) {
            $xml->writeElement('when', self::isoZ($r->timestamp()));
        }
        foreach ($fixes as $r) {
            $pos = $r->position();
            $alt = $r->altitude() ?? 0.0;
            $xml->writeElementNs('gx', 'coord', null, sprintf(
                '%s %s %s',
                self::fmtCoord($pos->lng),
                self::fmtCoord($pos->lat),
                self::fmtFloat($alt, 2),
            ));
        }

        $hasHr  = false;
        $hasCad = false;
        foreach ($fixes as $r) {
            $hasHr  = $hasHr  || $r->heartRate() !== null;
            $hasCad = $hasCad || $r->cadence() !== null;
        }
        if ($hasHr || $hasCad) {
            $xml->startElement('ExtendedData');
            $xml->startElement('SchemaData');
            $xml->writeAttribute('schemaUrl', '#' . self::SCHEMA_ID);
            if ($hasHr) {
                $this->writeSimpleArray($xml, 'heartrate', $fixes, static fn (Record $r): ?int => $r->heartRate());
            }
            if ($hasCad) {
                $this->writeSimpleArray($xml, 'cadence', $fixes, static fn (Record $r): ?int => $r->cadence());
            }
            $xml->endElement(); // SchemaData
            $xml->endElement(); // ExtendedData
        }

        $xml->endElement(); // gx:Track
    }

    /** @param Record[] $records each has a non-null position() */
    private function writeLineString(\XMLWriter $xml, array $records): void
    {
        $coords = [];
        foreach ($records as $r) {
            $pos = $r->position();
            $alt = $r->altitude() ?? 0.0;
            // KML <coordinates>: comma-separated lon,lat,alt tuples, space-separated.
            $coords[] = sprintf('%s,%s,%s', self::fmtCoord($pos->lng), self::fmtCoord($pos->lat), self::fmtFloat($alt, 2));
        }
        $xml->startElement('LineString');
        $xml->writeElement('tessellate', '1');
        $xml->writeElement('coordinates', implode(' ', $coords));
        $xml->endElement(); // LineString
    }

    private function writeArrayField(\XMLWriter $xml, string $name, string $type): void
    {
        $xml->startElementNs('gx', 'SimpleArrayField', null);
        $xml->writeAttribute('name', $name);
        $xml->writeAttribute('type', $type);
        $xml->endElement();
    }

    /**
     * @param Record[]                 $fixes
     * @param callable(Record): ?int   $get
     */
    private function writeSimpleArray(\XMLWriter $xml, string $name, array $fixes, callable $get): void
    {
        $xml->startElementNs('gx', 'SimpleArrayData', null);
        $xml->writeAttribute('name', $name);
        foreach ($fixes as $r) {
            $v = $get($r);
            $xml->writeElementNs('gx', 'value', null, $v === null ? '' : (string) $v);
        }
        $xml->endElement(); // SimpleArrayData
    }

    private function defaultName(Activity $activity): string
    {
        $sport = $activity->sessions[0]?->sport() ?? 'activity';
        $when  = $activity->timeCreated()?->format('Y-m-d H:i') ?? '';
        return trim(sprintf('%s %s', ucfirst($sport), $when));
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
}
