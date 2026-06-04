<?php

/**
 * Shared bootstrap for the runnable demos.
 *
 * Every demo `require_once`s this file. It loads Composer's autoloader (run
 * `composer install` first) and locates the one synthetic FIT file all the
 * demos read.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Absolute path to the shared synthetic FIT file the demos read — the committed
 * demos/tiergarten-run.fit. Regenerate it with `php bin/generate-default-samples`.
 */
define('DEMO_FIT_PATH', __DIR__ . '/tiergarten-run.fit');

/** Print a titled section header to stdout, e.g. "01 · Quick start". */
function demo_title(string $number, string $title): void
{
    echo str_repeat('─', 70), "\n", $number, ' · ', $title, "\n", str_repeat('─', 70), "\n", "\n";
}

/**
 * Resolve the Mapbox access token used by the PNG demo: the MAPBOX_TOKEN
 * environment variable if set, otherwise resources/mapbox-access-token.txt
 * (gitignored). Returns null when neither is present.
 */
function demo_mapbox_token(): ?string
{
    $env = getenv('MAPBOX_TOKEN');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    $path = __DIR__ . '/../resources/mapbox-access-token.txt';
    $raw  = is_readable($path) ? @file_get_contents($path) : false;
    return ($raw === false) ? null : (trim($raw) ?: null);
}
