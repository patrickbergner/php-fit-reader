<?php

declare(strict_types=1);

namespace Emontis\FitReader\Protocol;

final readonly class MessageDefinition
{
    /**
     * @param FieldDefinition[]    $fields
     * @param DevFieldDefinition[] $devFields
     */
    public function __construct(
        public int $localType,
        public bool $littleEndian,
        public int $globalNum,
        public array $fields,
        public array $devFields = [],
    ) {
    }
}
