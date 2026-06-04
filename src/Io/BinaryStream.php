<?php

declare(strict_types=1);

namespace Emontis\FitReader\Io;

use Emontis\FitReader\Exception\InvalidFileException;

/**
 * Thin wrapper around an fread-able stream with typed read helpers. Maintains
 * a running CRC over everything read (except the trailing file CRC bytes
 * themselves), so the decoder can verify the file CRC without a second pass.
 *
 * Endianness is explicit on each call: `*Le()` / `*Be()`.
 */
final class BinaryStream
{
    /** @var resource */
    private $handle;

    private int $position = 0;
    private Crc16 $runningCrc;

    /** @var resource $handle */
    public function __construct($handle, ?Crc16 $crc = null)
    {
        if (!is_resource($handle)) {
            throw new \InvalidArgumentException('BinaryStream requires a stream resource');
        }
        $this->handle     = $handle;
        $this->runningCrc = $crc ?? new Crc16();
    }

    public static function fromPath(string $path): self
    {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            throw new InvalidFileException("Cannot open FIT file: {$path}");
        }
        return new self($h);
    }

    public function position(): int
    {
        return $this->position;
    }

    public function runningCrc(): Crc16
    {
        return $this->runningCrc;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /**
     * Read exactly $n bytes, updating the running CRC. Throws on short read.
     */
    public function read(int $n): string
    {
        if ($n <= 0) {
            return '';
        }
        $buf = '';
        $remaining = $n;
        while ($remaining > 0) {
            $chunk = fread($this->handle, $remaining);
            if ($chunk === false || $chunk === '') {
                throw new InvalidFileException(
                    "Unexpected end of FIT stream at byte {$this->position} (wanted {$n}, got " . strlen($buf) . ')'
                );
            }
            $buf .= $chunk;
            $remaining -= strlen($chunk);
        }
        $this->runningCrc->update($buf);
        $this->position += $n;
        return $buf;
    }

    /**
     * Read $n bytes WITHOUT updating the running CRC. Used for the trailing
     * file CRC itself.
     */
    public function readRaw(int $n): string
    {
        $buf = '';
        $remaining = $n;
        while ($remaining > 0) {
            $chunk = fread($this->handle, $remaining);
            if ($chunk === false || $chunk === '') {
                throw new InvalidFileException(
                    "Unexpected end of FIT stream at byte {$this->position} (wanted {$n}, got " . strlen($buf) . ')'
                );
            }
            $buf .= $chunk;
            $remaining -= strlen($chunk);
        }
        $this->position += $n;
        return $buf;
    }

    public function u8(): int
    {
        return ord($this->read(1));
    }

    public function u16Le(): int
    {
        $b = $this->read(2);
        return ord($b[0]) | (ord($b[1]) << 8);
    }

    public function u16Be(): int
    {
        $b = $this->read(2);
        return (ord($b[0]) << 8) | ord($b[1]);
    }
}
