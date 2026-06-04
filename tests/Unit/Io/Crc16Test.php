<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Unit\Io;

use Emontis\FitReader\Io\Crc16;
use PHPUnit\Framework\TestCase;

final class Crc16Test extends TestCase
{
    public function testEmptyStringIsZero(): void
    {
        self::assertSame(0, Crc16::of(''));
    }

    public function testIsIncremental(): void
    {
        $whole  = Crc16::of('Hello, FIT!');
        $crc    = new Crc16();
        $crc->update('Hello, ');
        $crc->update('FIT!');
        self::assertSame($whole, $crc->value());
    }

    public function testMatchesCanonicalCrc16ArcCheckValue(): void
    {
        // The FIT CRC (nibble table, polynomial 0xA001, init 0) is CRC-16/ARC.
        // Its cataloged check value — the CRC of the ASCII string "123456789"
        // — is 0xBB3D.
        self::assertSame(0xBB3D, Crc16::of('123456789'));
    }
}
