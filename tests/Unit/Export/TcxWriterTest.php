<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Unit\Export;

use Emontis\FitReader\Activity\Activity;
use Emontis\FitReader\Activity\Lap;
use Emontis\FitReader\Activity\Record;
use Emontis\FitReader\Activity\Session;
use Emontis\FitReader\Export\TcxWriter;
use Emontis\FitReader\Value\GeoPoint;
use PHPUnit\Framework\TestCase;

final class TcxWriterTest extends TestCase
{
    public function testProducesWellFormedTcxWithLapsAndTrackpoints(): void
    {
        $xml   = new TcxWriter()->toString(self::sampleActivity('running'));
        $xpath = self::xpath($xml);

        self::assertSame('Running', $xpath->evaluate('string(//t:Activity/@Sport)'));
        self::assertSame(1, (int) $xpath->evaluate('count(//t:Activity)'));
        self::assertSame(2, (int) $xpath->evaluate('count(//t:Lap)'), 'one <Lap> per session lap');
        self::assertNotSame('', $xpath->evaluate('string(//t:Activity/t:Id)'));
        self::assertNotSame('', $xpath->evaluate('string((//t:Lap)[1]/@StartTime)'));

        // Trackpoints carry native position + HR.
        self::assertGreaterThan(0, (int) $xpath->evaluate('count(//t:Trackpoint/t:Position/t:LatitudeDegrees)'));
        self::assertGreaterThan(0, (int) $xpath->evaluate('count(//t:Trackpoint/t:HeartRateBpm/t:Value)'));

        // Required lap elements are always present.
        self::assertGreaterThan(0, (int) $xpath->evaluate('count(//t:Lap/t:TotalTimeSeconds)'));
        self::assertGreaterThan(0, (int) $xpath->evaluate('count(//t:Lap/t:Calories)'));
        self::assertGreaterThan(0, (int) $xpath->evaluate('count(//t:Lap/t:Intensity)'));
        self::assertGreaterThan(0, (int) $xpath->evaluate('count(//t:Lap/t:TriggerMethod)'));
    }

    public function testPowerAndSpeedGoIntoActivityExtensionNamespace(): void
    {
        $xml   = new TcxWriter()->toString(self::sampleActivity('cycling'));
        $xpath = self::xpath($xml);

        self::assertSame('Biking', $xpath->evaluate('string(//t:Activity/@Sport)'));
        self::assertGreaterThan(0, (int) $xpath->evaluate('count(//t:Trackpoint/t:Extensions/ax:TPX/ax:Watts)'));
        self::assertGreaterThan(0, (int) $xpath->evaluate('count(//t:Trackpoint/t:Extensions/ax:TPX/ax:Speed)'));
    }

    public function testDerivedSpeedKmhFlowsIntoAxSpeedWhenNativeSpeedIsAbsent(): void
    {
        // Mirrors the normalized export path: native `speed` excluded, a
        // GPS-derived `speed_kmh` present instead. It should surface as
        // ax:Speed in m/s (km/h ÷ 3.6).
        $activity = self::singleRecordActivity([
            'position'  => new GeoPoint(52.51, 13.35),
            'speed_kmh' => 18.0, // → 5 m/s
        ]);

        $xpath = self::xpath(new TcxWriter()->toString($activity));

        self::assertSame(1, (int) $xpath->evaluate('count(//ax:TPX/ax:Speed)'));
        self::assertEqualsWithDelta(5.0, (float) $xpath->evaluate('string(//ax:TPX/ax:Speed)'), 0.001);
    }

    public function testUnmappedSportCollapsesToOtherButIsPreservedInNotes(): void
    {
        $xml   = new TcxWriter()->toString(self::sampleActivity('swimming'));
        $xpath = self::xpath($xml);

        self::assertSame('Other', $xpath->evaluate('string(//t:Activity/@Sport)'));
        self::assertStringContainsString('sport=swimming', $xpath->evaluate('string(//t:Activity/t:Notes)'));
    }

    public function testEmitsDeviceCreatorFromFitData(): void
    {
        $xpath = self::xpath(new TcxWriter()->toString(self::sampleActivity('running')));

        self::assertSame('Device_t', $xpath->evaluate('string(//t:Activity/t:Creator/@xsi:type)'));
        self::assertSame('Forerunner 255 Music', $xpath->evaluate('string(//t:Creator/t:Name)'));
        self::assertSame('1729', $xpath->evaluate('string(//t:Creator/t:UnitId)'));
        self::assertSame('1234', $xpath->evaluate('string(//t:Creator/t:ProductID)'));
        // device_info software_version 27.09 (hundredths) → VersionMajor 27 / Minor 9.
        self::assertSame('27', $xpath->evaluate('string(//t:Creator/t:Version/t:VersionMajor)'));
        self::assertSame('9', $xpath->evaluate('string(//t:Creator/t:Version/t:VersionMinor)'));
    }

    public function testCoordinatesAreEmittedWithEightDecimals(): void
    {
        // FIT semicircles resolve finer than 7 decimals; 8 decimals preserves
        // the source value rather than rounding 51.36898275 → 51.3689828.
        $activity = self::singleRecordActivity(['position' => new GeoPoint(51.36898275, 12.37027396)]);

        $xpath = self::xpath(new TcxWriter()->toString($activity));
        self::assertSame('51.36898275', $xpath->evaluate('string(//t:Trackpoint/t:Position/t:LatitudeDegrees)'));
        self::assertSame('12.37027396', $xpath->evaluate('string(//t:Trackpoint/t:Position/t:LongitudeDegrees)'));
    }

    public function testCreatorNameResolvesGarminProductEnumWhenNoProductName(): void
    {
        // The file carries only the numeric product id (no product_name); for a
        // garmin device it should resolve via the profile's garmin_product enum.
        $activity = self::singleRecordActivity(
            ['position' => new GeoPoint(52.51, 13.35)],
            ['manufacturer' => 'garmin', 'product' => 3990, 'serial_number' => 3416986430],
        );

        $xpath = self::xpath(new TcxWriter()->toString($activity));
        self::assertSame('fr255_music', $xpath->evaluate('string(//t:Creator/t:Name)'));
    }

    public function testEmitsFitReaderAuthor(): void
    {
        $xpath = self::xpath(new TcxWriter()->toString(self::sampleActivity('running')));

        self::assertSame('Application_t', $xpath->evaluate('string(//t:Author/@xsi:type)'));
        self::assertSame('emontis/fit-reader', $xpath->evaluate('string(//t:Author/t:Name)'));
        self::assertSame('000-00000-00', $xpath->evaluate('string(//t:Author/t:PartNumber)'));
    }

    public function testCreatorOmittedWithoutDeviceDataButAuthorRemains(): void
    {
        // Empty file_id, no file_creator, no device_info → no identity to show.
        $activity = self::singleRecordActivity(['position' => new GeoPoint(52.51, 13.35)]);

        $xpath = self::xpath(new TcxWriter()->toString($activity));
        self::assertSame(0, (int) $xpath->evaluate('count(//t:Creator)'));
        self::assertSame(1, (int) $xpath->evaluate('count(//t:Author)'));
    }

    private static function xpath(string $xml): \DOMXPath
    {
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml), 'TCX output must be well-formed XML');
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('t', TcxWriter::NS_TCX);
        $xpath->registerNamespace('ax', TcxWriter::NS_AX);
        $xpath->registerNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        return $xpath;
    }

    /**
     * A one-session, one-lap activity with a single record. The record always
     * carries a `timestamp`; pass the rest of its fields and (optionally) the
     * `file_id` map.
     *
     * @param array<string, mixed> $recordFields
     * @param array<string, mixed> $fileId
     */
    private static function singleRecordActivity(array $recordFields, array $fileId = []): Activity
    {
        $t0 = new \DateTimeImmutable('2026-06-04T08:00:00Z');
        $record  = new Record(['timestamp' => $t0] + $recordFields);
        $lap     = new Lap(['start_time' => $t0], [$record]);
        $session = new Session(['sport' => 'running', 'start_time' => $t0], [$lap], [$record]);
        return new Activity($fileId, null, [$session], [], [], null);
    }

    private static function sampleActivity(string $sport): Activity
    {
        $t0 = new \DateTimeImmutable('2026-06-04T08:00:00Z');
        $lap1 = self::lap($t0, 0, 3);
        $lap2 = self::lap($t0->modify('+3 seconds'), 3, 3);
        $records = array_merge($lap1->records, $lap2->records);

        $session = new Session(
            ['sport' => $sport, 'sub_sport' => 'road', 'start_time' => $t0],
            [$lap1, $lap2],
            $records,
        );

        return new Activity(
            ['manufacturer' => 'garmin', 'product' => 1234, 'serial_number' => 1729,
             'product_name' => 'Forerunner 255 Music', 'time_created' => $t0],
            ['software_version' => 100, 'hardware_version' => 1],
            [$session],
            [[
                'device_index'     => 'creator',
                'manufacturer'     => 'garmin',
                'product'          => 1234,
                'product_name'     => 'Forerunner 255 Music',
                'software_version' => 27.09,
                'serial_number'    => 1729,
            ]],
            [],
            ['total_timer_time' => 6.0],
        );
    }

    private static function lap(\DateTimeImmutable $start, int $offset, int $count): Lap
    {
        $records = [];
        for ($i = 0; $i < $count; $i++) {
            $records[] = new Record([
                'timestamp'   => $start->modify("+{$i} seconds"),
                'position'    => new GeoPoint(52.51 + ($offset + $i) * 0.001, 13.35 + ($offset + $i) * 0.001),
                'altitude'    => 35.0 + $offset + $i,
                'distance'    => (float) (($offset + $i) * 5),
                'heart_rate'  => 140 + $i,
                'cadence'     => 85 + $i,
                'power'       => 200 + $i,
                'speed'       => 3.2,
            ]);
        }

        return new Lap([
            'start_time'       => $start,
            'total_timer_time' => (float) $count,
            'total_distance'   => (float) ($count * 5),
            'max_speed'        => 3.5,
            'total_calories'   => 12,
            'avg_heart_rate'   => 142,
            'max_heart_rate'   => 150,
            'avg_cadence'      => 86,
            'avg_power'        => 205,
            'max_power'        => 220,
        ], $records);
    }
}
