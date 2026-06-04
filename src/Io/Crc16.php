<?php

declare(strict_types=1);

namespace Emontis\FitReader\Io;

/**
 * Nibble-table CRC-16 used by the FIT protocol (polynomial 0xA001 / Modbus
 * family). Two-step nibble lookup, matches the canonical Garmin SDK
 * implementation (FitCRC_Update16).
 */
final class Crc16
{
    private const TABLE = [
        0x0000, 0xCC01, 0xD801, 0x1400, 0xF001, 0x3C00, 0x2800, 0xE401,
        0xA001, 0x6C00, 0x7800, 0xB401, 0x5000, 0x9C01, 0x8801, 0x4400,
    ];

    private int $crc = 0;

    public function update(string $data): void
    {
        $crc = $this->crc;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $byte = ord($data[$i]);
            $tmp  = self::TABLE[$crc & 0xF];
            $crc  = ($crc >> 4) & 0x0FFF;
            $crc  = $crc ^ $tmp ^ self::TABLE[$byte & 0xF];

            $tmp  = self::TABLE[$crc & 0xF];
            $crc  = ($crc >> 4) & 0x0FFF;
            $crc  = $crc ^ $tmp ^ self::TABLE[($byte >> 4) & 0xF];
        }
        $this->crc = $crc;
    }

    public function value(): int
    {
        return $this->crc;
    }

    public function reset(): void
    {
        $this->crc = 0;
    }

    public static function of(string $data): int
    {
        $c = new self();
        $c->update($data);
        return $c->value();
    }
}
