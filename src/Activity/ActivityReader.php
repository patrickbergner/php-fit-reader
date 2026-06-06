<?php

declare(strict_types=1);

namespace Emontis\FitReader\Activity;

use Emontis\FitReader\Exception\InvalidFileException;
use Emontis\FitReader\Io\BinaryStream;
use Emontis\FitReader\Message\Message;
use Emontis\FitReader\Protocol\Decoder;

/**
 * Builds an Activity aggregate from a decoded message stream.
 *
 * Different writers order messages differently — Garmin devices interleave
 * record/lap/session as data arrives, while Strava (and some other tools)
 * write the session/lap summaries up front before the records. To handle
 * both, we gather messages by type in a single pass, then split records
 * and laps across sessions by start_time / total_elapsed_time range.
 */
final class ActivityReader
{
    public static function read(string $path, bool $verifyCrc = true): Activity
    {
        $stream  = BinaryStream::fromPath($path);
        $decoder = new Decoder($stream, verifyCrc: $verifyCrc);
        try {
            return self::buildFrom($decoder->messages());
        } finally {
            $stream->close();
        }
    }

    /** @param iterable<Message> $messages */
    public static function buildFrom(iterable $messages): Activity
    {
        $fileId       = null;
        $fileCreator  = null;
        $devices      = [];
        $events       = [];
        $activitySum  = null;
        /** @var Record[] $records */
        $records      = [];
        /** @var Message[] $lapMessages */
        $lapMessages  = [];
        /** @var Message[] $sessionMessages */
        $sessionMessages = [];

        foreach ($messages as $m) {
            switch ($m->name) {
                case 'file_id':
                    if ($fileId === null) {
                        $fileId = $m->fields;
                        $type   = $fileId['type'] ?? null;
                        if ($type !== 'activity') {
                            throw new InvalidFileException(
                                'Not an activity file (file_id.type = ' . var_export($type, true) . ')'
                            );
                        }
                    }
                    break;
                case 'file_creator':
                    $fileCreator = $m->fields;
                    break;
                case 'device_info':
                    $devices[] = $m->fields;
                    break;
                case 'event':
                    $events[] = $m->fields;
                    break;
                case 'record':
                    $records[] = Record::fromMessage($m);
                    break;
                case 'lap':
                    $lapMessages[] = $m;
                    break;
                case 'session':
                    $sessionMessages[] = $m;
                    break;
                case 'activity':
                    $activitySum = $m->fields;
                    break;
            }
        }

        if ($fileId === null) {
            throw new InvalidFileException('FIT file has no file_id message');
        }

        $sessions = self::assembleSessions($sessionMessages, $lapMessages, $records);

        return new Activity(
            fileId:      $fileId,
            fileCreator: $fileCreator,
            sessions:    $sessions,
            deviceInfos: $devices,
            events:      $events,
            summary:     $activitySum,
        );
    }

    /**
     * @param Message[] $sessionMsgs
     * @param Message[] $lapMsgs
     * @param Record[]  $records
     * @return Session[]
     */
    private static function assembleSessions(array $sessionMsgs, array $lapMsgs, array $records): array
    {
        if ($sessionMsgs === []) {
            return [];
        }

        // Pre-sort by start_time so multi-session activities partition cleanly.
        $cmpStart = static fn (Message $a, Message $b) => self::ts($a->fields['start_time'] ?? null) <=> self::ts($b->fields['start_time'] ?? null);
        usort($sessionMsgs, $cmpStart);
        usort($lapMsgs, $cmpStart);
        usort($records, static fn (Record $a, Record $b) => ($a->timestamp?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->timestamp?->getTimestamp() ?? PHP_INT_MAX));

        // Fast path: single session — everything goes to it.
        if (count($sessionMsgs) === 1) {
            $sm   = $sessionMsgs[0];
            $laps = array_map(
                static fn (Message $lm) => Lap::fromMessage($lm, self::recordsInRange($records, $lm->fields)),
                $lapMsgs,
            );
            return [Session::build($sm, $laps, $records)];
        }

        // Multi-session: bucket records/laps by session time range.
        $sessions = [];
        foreach ($sessionMsgs as $i => $sm) {
            $start = self::ts($sm->fields['start_time'] ?? null);
            $end   = $start + (int) self::secondsField($sm->fields['total_elapsed_time'] ?? null);
            $myRecords = array_values(array_filter(
                $records,
                static function (Record $r) use ($start, $end): bool {
                    $ts = $r->timestamp?->getTimestamp();
                    return $ts !== null && $ts >= $start && $ts <= $end;
                },
            ));
            $myLapMsgs = array_values(array_filter(
                $lapMsgs,
                static function (Message $lm) use ($start, $end): bool {
                    $ls = self::ts($lm->fields['start_time'] ?? null);
                    return $ls >= $start && $ls <= $end;
                },
            ));
            $myLaps = array_map(
                static fn (Message $lm) => Lap::fromMessage($lm, self::recordsInRange($myRecords, $lm->fields)),
                $myLapMsgs,
            );
            $sessions[] = Session::build($sm, $myLaps, $myRecords);
        }
        return $sessions;
    }

    /**
     * @param Record[]                $records
     * @param array<string|int,mixed> $lapFields
     * @return list<Record>
     */
    private static function recordsInRange(array $records, array $lapFields): array
    {
        $start = self::ts($lapFields['start_time'] ?? null);
        if ($start === 0) {
            return [];
        }
        $duration = (int) self::secondsField($lapFields['total_elapsed_time'] ?? null);
        $end = $start + max($duration, 1);
        return array_values(array_filter(
            $records,
            static function (Record $r) use ($start, $end): bool {
                $ts = $r->timestamp?->getTimestamp();
                return $ts !== null && $ts >= $start && $ts <= $end;
            },
        ));
    }

    private static function ts(mixed $v): int
    {
        return $v instanceof \DateTimeImmutable ? $v->getTimestamp() : 0;
    }

    private static function secondsField(mixed $v): float
    {
        if (is_float($v) || is_int($v)) return (float) $v;
        return 0.0;
    }
}
