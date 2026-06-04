<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Unit\Value;

use Emontis\FitReader\Protocol\BaseType;
use Emontis\FitReader\Value\ValueDecoder;
use PHPUnit\Framework\TestCase;

final class ValueDecoderTest extends TestCase
{
    public function testUint16Endianness(): void
    {
        self::assertSame(0x1234, ValueDecoder::decode("\x34\x12", BaseType::Uint16, true));
        self::assertSame(0x1234, ValueDecoder::decode("\x12\x34", BaseType::Uint16, false));
    }

    public function testSint16SignExtension(): void
    {
        // 0xFFFE LE = -2
        self::assertSame(-2, ValueDecoder::decode("\xFE\xFF", BaseType::Sint16, true));
        // 0x8000 LE = -32768
        self::assertSame(-32768, ValueDecoder::decode("\x00\x80", BaseType::Sint16, true));
    }

    public function testUint32InvalidBecomesNull(): void
    {
        self::assertNull(ValueDecoder::decode("\xFF\xFF\xFF\xFF", BaseType::Uint32, true));
    }

    public function testSint32(): void
    {
        // -1 LE → 0xFFFFFFFF, but that's the invalid sentinel for uint32, not for sint32.
        // For sint32 the sentinel is 0x7FFFFFFF.
        self::assertSame(-1, ValueDecoder::decode("\xFF\xFF\xFF\xFF", BaseType::Sint32, true));
        self::assertNull(ValueDecoder::decode("\xFF\xFF\xFF\x7F", BaseType::Sint32, true));
    }

    public function testStringTrimsAtNull(): void
    {
        self::assertSame('garmin', ValueDecoder::decode("garmin\x00\x00", BaseType::String, true));
    }

    public function testEmptyStringIsNull(): void
    {
        self::assertNull(ValueDecoder::decode("\x00\x00\x00", BaseType::String, true));
    }

    public function testArrayOfUint16(): void
    {
        $bytes = "\x01\x00\x02\x00\x03\x00"; // [1, 2, 3]
        self::assertSame([1, 2, 3], ValueDecoder::decode($bytes, BaseType::Uint16, true));
    }

    public function testArrayAllInvalidIsNull(): void
    {
        $bytes = "\xFF\xFF\xFF\xFF"; // [invalid, invalid]
        self::assertNull(ValueDecoder::decode($bytes, BaseType::Uint16, true));
    }

    public function testArrayPartiallyInvalidKeepsArray(): void
    {
        $bytes = "\x01\x00\xFF\xFF\x03\x00"; // [1, null, 3]
        self::assertSame([1, null, 3], ValueDecoder::decode($bytes, BaseType::Uint16, true));
    }

    public function testFloat32(): void
    {
        // 1.0f LE
        $v = ValueDecoder::decode("\x00\x00\x80\x3F", BaseType::Float32, true);
        self::assertEqualsWithDelta(1.0, $v, 1e-6);
    }
}
