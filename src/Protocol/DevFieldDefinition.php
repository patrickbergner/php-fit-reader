<?php

declare(strict_types=1);

namespace Emontis\FitReader\Protocol;

final readonly class DevFieldDefinition
{
    public function __construct(
        public int $fieldNum,
        public int $size,
        public int $devDataIndex,
    ) {
    }
}
