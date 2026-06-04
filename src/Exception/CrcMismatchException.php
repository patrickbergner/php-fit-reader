<?php

declare(strict_types=1);

namespace Emontis\FitReader\Exception;

class CrcMismatchException extends FitException
{
    public function __construct(string $location, int $expected, int $actual)
    {
        parent::__construct(sprintf(
            'FIT CRC mismatch at %s: expected 0x%04X, got 0x%04X',
            $location,
            $expected,
            $actual,
        ));
    }
}
