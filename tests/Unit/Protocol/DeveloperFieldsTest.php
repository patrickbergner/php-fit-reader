<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Unit\Protocol;

use Emontis\FitReader\FitReader;
use Emontis\FitReader\Protocol\BaseType;
use Emontis\FitReader\Tests\Support\SyntheticFit\Writer;
use PHPUnit\Framework\TestCase;

final class DeveloperFieldsTest extends TestCase
{
    private string $path = '';

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function testDeveloperFieldsResolveByNameWithScale(): void
    {
        $t0 = new \DateTimeImmutable('2026-06-01T08:00:00+00:00');

        $bytes = (new Writer())
            ->add('file_id', [
                'type'          => 'activity',
                'manufacturer'  => 'garmin',
                'product'       => 1,
                'serial_number' => 1,
                'time_created'  => $t0,
            ])
            // field_description messages must precede the data that uses them.
            ->add('field_description', [
                'developer_data_index'    => 0,
                'field_definition_number' => 0,
                'fit_base_type_id'        => 'uint16',
                'field_name'              => 'Stryd Power',
                'units'                   => 'Watts',
            ])
            ->add('field_description', [
                'developer_data_index'    => 0,
                'field_definition_number' => 1,
                'fit_base_type_id'        => 'uint16',
                'field_name'              => 'Leg Spring Stiffness',
                'scale'                   => 100,
                'units'                   => 'kN/m',
            ])
            ->addWithDeveloperFields(
                'record',
                ['timestamp' => $t0, 'heart_rate' => 150],
                [
                    ['fieldNum' => 0, 'devDataIndex' => 0, 'baseType' => BaseType::Uint16, 'value' => 285],
                    ['fieldNum' => 1, 'devDataIndex' => 0, 'baseType' => BaseType::Uint16, 'value' => 1050],
                ],
            )
            ->add('session', [
                'start_time'         => $t0,
                'total_elapsed_time' => 1.0,
                'sport'              => 'running',
            ])
            ->toBytes();

        $record = FitReader::activity($this->writeTemp($bytes))->sessions[0]->records[0];

        // Native field still decodes alongside.
        self::assertSame(150, $record->heartRate());
        // Developer fields resolve to their declared names…
        self::assertSame(285, $record->developerField('Stryd Power'));
        // …with the field_description scale applied (1050 / 100).
        self::assertSame(10.5, $record->developerField('Leg Spring Stiffness'));
        // Unknown developer field → default.
        self::assertNull($record->developerField('Nope'));
    }

    public function testUndescribedDeveloperFieldFallsBackToRawBytes(): void
    {
        $t0 = new \DateTimeImmutable('2026-06-01T08:00:00+00:00');

        $bytes = (new Writer())
            ->add('file_id', [
                'type' => 'activity', 'manufacturer' => 'garmin',
                'product' => 1, 'serial_number' => 1, 'time_created' => $t0,
            ])
            ->addWithDeveloperFields(
                'record',
                ['timestamp' => $t0, 'heart_rate' => 140],
                [['fieldNum' => 7, 'devDataIndex' => 0, 'baseType' => BaseType::Uint16, 'value' => 42]],
            )
            ->add('session', ['start_time' => $t0, 'total_elapsed_time' => 1.0, 'sport' => 'running'])
            ->toBytes();

        $record = FitReader::activity($this->writeTemp($bytes))->sessions[0]->records[0];

        self::assertSame(140, $record->heartRate());
        // No field_description ⇒ the value stays as raw bytes under dev_field_N.
        self::assertNull($record->developerField('whatever'));
        self::assertIsString($record->field('dev_field_7'));
    }

    private function writeTemp(string $bytes): string
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'fit');
        file_put_contents($this->path, $bytes);
        return $this->path;
    }
}
