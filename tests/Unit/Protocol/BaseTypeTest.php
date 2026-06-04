<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Unit\Protocol;

use Emontis\FitReader\Protocol\BaseType;
use PHPUnit\Framework\TestCase;

final class BaseTypeTest extends TestCase
{
    public function testKnownSizes(): void
    {
        self::assertSame(1, BaseType::Enum->size());
        self::assertSame(2, BaseType::Uint16->size());
        self::assertSame(4, BaseType::Uint32->size());
        self::assertSame(4, BaseType::Float32->size());
        self::assertSame(8, BaseType::Float64->size());
        self::assertSame(8, BaseType::Sint64->size());
    }

    public function testSentinels(): void
    {
        self::assertSame(0xFF, BaseType::Uint8->invalidSentinel());
        self::assertSame(0xFFFF, BaseType::Uint16->invalidSentinel());
        self::assertSame(0x7FFF, BaseType::Sint16->invalidSentinel());
        self::assertSame(0x00, BaseType::Uint8z->invalidSentinel());
        self::assertNull(BaseType::String->invalidSentinel());
    }

    public function testTryFromByteHandlesUnknownTopBit(): void
    {
        // Hex codes either match a known type or have the high bit set.
        self::assertSame(BaseType::Uint16, BaseType::tryFromByte(0x84));
        self::assertSame(BaseType::Enum,   BaseType::tryFromByte(0x00));
        self::assertSame(BaseType::Sint64, BaseType::tryFromByte(0x8E));
    }
}
