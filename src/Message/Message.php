<?php

declare(strict_types=1);

namespace Emontis\FitReader\Message;

/**
 * A profile-resolved FIT message. `name` is null if the global message
 * number isn't in the loaded profile. `fields` is keyed by field name when
 * known, otherwise by numeric fieldDefNum.
 */
final readonly class Message
{
    /** @param array<string|int, mixed> $fields */
    public function __construct(
        public int $globalNum,
        public ?string $name,
        public array $fields,
        public ?MessageKind $kind = null,
    ) {
    }

    public function get(string $field, mixed $default = null): mixed
    {
        return $this->fields[$field] ?? $default;
    }
}
