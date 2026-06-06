<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Unit\Export;

use Emontis\FitReader\Export\CsvWriter;
use PHPUnit\Framework\TestCase;

final class CsvWriterTest extends TestCase
{
    public function testDefaultDialectIsCommaSeparatedWithLfEndings(): void
    {
        $csv = new CsvWriter()->toString([
            ['name' => 'a', 'value' => 1],
            ['name' => 'b', 'value' => 2],
        ]);

        self::assertSame("name,value\na,1\nb,2\n", $csv);
    }

    public function testSeparatorAndEolAreOverridable(): void
    {
        $csv = new CsvWriter(separator: ';', eol: "\r\n")->toString([
            ['name' => 'a', 'value' => 1],
        ]);

        self::assertSame("name;value\r\na;1\r\n", $csv);
    }

    public function testEnclosureIsOverridable(): void
    {
        // The value contains the separator, so it must be quoted — with the
        // caller's enclosure character, not the default double quote.
        $csv = new CsvWriter(enclosure: "'")->toString([
            ['col' => 'x,y'],
        ]);

        self::assertSame("col\n'x,y'\n", $csv);
    }

    public function testDefaultEscapeDoublesEmbeddedEnclosurePerRfc4180(): void
    {
        $csv = new CsvWriter()->toString([
            ['col' => 'p"q'],
        ]);

        self::assertSame("col\n\"p\"\"q\"\n", $csv);
    }

    public function testEscapeIsOverridable(): void
    {
        // A value containing a backslash needs no quoting under the default
        // empty escape, but with '\' as the escape character fputcsv treats it
        // as special — proving the parameter reaches fputcsv().
        $rows = [['col' => 'a\\b']];

        self::assertNotSame(
            new CsvWriter()->toString($rows),
            new CsvWriter(escape: '\\')->toString($rows),
        );
    }

    public function testWriteRowsHonorsTheDialect(): void
    {
        $path = sys_get_temp_dir() . '/fit-reader-csvwriter-' . uniqid() . '.csv';
        try {
            new CsvWriter(separator: "\t", eol: "\r\n")->writeRows($path, [
                ['name' => 'a', 'value' => 1],
            ]);
            self::assertSame("name\tvalue\r\na\t1\r\n", file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }
}
