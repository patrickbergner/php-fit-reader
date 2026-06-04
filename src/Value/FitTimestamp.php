<?php

declare(strict_types=1);

namespace Emontis\FitReader\Value;

/**
 * Seconds since the FIT epoch, 1989-12-31 00:00:00 UTC. Add the offset below
 * to convert to Unix seconds.
 */
final readonly class FitTimestamp
{
    public const FIT_EPOCH_OFFSET = 631065600;

    public function __construct(public int $secondsSinceFitEpoch)
    {
    }

    public function unix(): int
    {
        return $this->secondsSinceFitEpoch + self::FIT_EPOCH_OFFSET;
    }

    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $this->unix()))
            ->setTimezone(new \DateTimeZone('UTC'));
    }
}
