<?php

declare(strict_types=1);

namespace Emontis\FitReader\Protocol;

final readonly class FieldDefinition
{
    public function __construct(
        public int $fieldDefNum,
        public int $size,
        public BaseType $baseType,
    ) {
    }
}
