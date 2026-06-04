<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Unit\Protocol;

use Emontis\FitReader\Protocol\RecordHeader;
use PHPUnit\Framework\TestCase;

final class RecordHeaderTest extends TestCase
{
    public function testNormalDataHeader(): void
    {
        $h = RecordHeader::parse(0x05); // 0000 0101 — data, local 5
        self::assertFalse($h->compressedTimestamp);
        self::assertFalse($h->isDefinition);
        self::assertFalse($h->hasDeveloperData);
        self::assertSame(5, $h->localType);
    }

    public function testNormalDefinitionHeader(): void
    {
        $h = RecordHeader::parse(0x40); // 0100 0000 — definition, local 0
        self::assertTrue($h->isDefinition);
        self::assertFalse($h->hasDeveloperData);
        self::assertSame(0, $h->localType);
    }

    public function testDefinitionWithDeveloperData(): void
    {
        $h = RecordHeader::parse(0x60); // 0110 0000
        self::assertTrue($h->isDefinition);
        self::assertTrue($h->hasDeveloperData);
        self::assertSame(0, $h->localType);
    }

    public function testCompressedTimestampHeader(): void
    {
        $h = RecordHeader::parse(0b10110011); // local 1, offset 0b10011 = 19
        self::assertTrue($h->compressedTimestamp);
        self::assertFalse($h->isDefinition);
        self::assertSame(1, $h->localType);
        self::assertSame(19, $h->timeOffset);
    }
}
