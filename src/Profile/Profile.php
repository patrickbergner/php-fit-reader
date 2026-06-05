<?php

declare(strict_types=1);

namespace Emontis\FitReader\Profile;

/**
 * Lazy-loading registry of profile data. Generated files live under
 * src/Profile/Generated/. If they're absent the library still functions —
 * messages are surfaced with numeric keys.
 */
final class Profile
{
    /** @var array<int, MessageDef>|null */
    private static ?array $messages = null;

    /** @var array<string, array<int, string>>|null  typeName => value => label */
    private static ?array $types = null;

    public static function message(int $globalNum): ?MessageDef
    {
        return self::messages()[$globalNum] ?? null;
    }

    public static function field(int $globalNum, int $fieldDefNum): ?FieldDef
    {
        return self::messages()[$globalNum]->fields[$fieldDefNum] ?? null;
    }

    public static function enumLabel(string $typeName, int $value): ?string
    {
        return self::types()[$typeName][$value] ?? null;
    }

    /** Reverse of {@see enumLabel()} — resolve a label back to its raw value. */
    public static function enumValue(string $typeName, string $label): ?int
    {
        $map = self::types()[$typeName] ?? null;
        if ($map === null) {
            return null;
        }
        $found = array_search($label, $map, strict: true);
        return $found === false ? null : $found;
    }

    /** @return array<int, MessageDef> */
    private static function messages(): array
    {
        if (self::$messages === null) {
            $path = __DIR__ . '/Generated/MessageDefinitions.php';
            /** @var array<int, MessageDef> $loaded */
            $loaded = is_file($path) ? require $path : [];
            self::$messages = $loaded;
        }
        return self::$messages;
    }

    /** @return array<string, array<int, string>> */
    private static function types(): array
    {
        if (self::$types === null) {
            $path = __DIR__ . '/Generated/Types.php';
            /** @var array<string, array<int, string>> $loaded */
            $loaded = is_file($path) ? require $path : [];
            self::$types = $loaded;
        }
        return self::$types;
    }

    /** Test/internal: force-reload generated data. */
    public static function reset(): void
    {
        self::$messages = null;
        self::$types    = null;
    }
}
