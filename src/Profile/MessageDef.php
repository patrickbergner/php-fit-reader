<?php

declare(strict_types=1);

namespace Emontis\FitReader\Profile;

final readonly class MessageDef
{
    /**
     * @param array<int, FieldDef> $fields keyed by fieldDefNum
     */
    public function __construct(
        public int $globalNum,
        public string $name,
        public array $fields,
    ) {
    }
}
