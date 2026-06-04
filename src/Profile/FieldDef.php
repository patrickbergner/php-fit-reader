<?php

declare(strict_types=1);

namespace Emontis\FitReader\Profile;

use Emontis\FitReader\Protocol\BaseType;

/**
 * Profile metadata for one field within a message. Loaded from the generated
 * profile (which comes from Garmin's Profile.xlsx).
 */
final readonly class FieldDef
{
    public function __construct(
        public int $fieldDefNum,
        public string $name,
        public BaseType $baseType,
        public float $scale = 1.0,
        public float $offset = 0.0,
        public ?string $units = null,
        public ?string $typeName = null,
    ) {
    }
}
