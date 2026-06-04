<?php

declare(strict_types=1);

namespace Emontis\FitReader\Message;

/**
 * Names for the global message numbers we treat specially in the activity
 * facade. Use Message::globalNum directly if you need a global not listed.
 */
enum MessageKind: int
{
    case FileId               = 0;
    case Capabilities         = 1;
    case DeviceSettings       = 2;
    case UserProfile          = 3;
    case HrmProfile           = 4;
    case SdmProfile           = 5;
    case BikeProfile          = 6;
    case ZonesTarget          = 7;
    case HrZone               = 8;
    case PowerZone            = 9;
    case MetZone              = 10;
    case Sport                = 12;
    case Goal                 = 15;
    case Session              = 18;
    case Lap                  = 19;
    case Record               = 20;
    case Event                = 21;
    case DeviceInfo           = 23;
    case Workout              = 26;
    case WorkoutStep          = 27;
    case Schedule             = 28;
    case Activity             = 34;
    case Software             = 35;
    case FileCapabilities     = 37;
    case MesgCapabilities     = 38;
    case FieldCapabilities    = 39;
    case FileCreator          = 49;
    case BloodPressure        = 51;
    case Hr                   = 132;
    case TimestampCorrelation = 162;

    public static function tryFromGlobal(int $globalNum): ?self
    {
        return self::tryFrom($globalNum);
    }
}
