<?php

declare(strict_types=1);

namespace Emontis\FitReader\Activity;

/**
 * Marks a {@see ContinuousTimelineNormalizer} `derive` entry as a *factory*
 * that produces a deriver, rather than a deriver itself.
 *
 * A deriver is a closure `fn(?Record $prev, Record $curr): mixed`. Some are
 * stateful — the GPS speed/pace derivers keep a rolling `$lastGps`/`$history`
 * in their closure — so reusing one across several `normalize()` runs (e.g.
 * one run per session) leaks state between runs. Wrapping the factory here
 * tells the normalizer to call it once at the start of every `normalize()`
 * run, yielding a fresh deriver each time.
 *
 * Build instances via {@see ContinuousTimelineNormalizer::perRun()} or the
 * {@see \Emontis\FitReader\FitReader::perRun()} passthrough.
 */
final readonly class DeriverFactory
{
    /** @var \Closure(): callable */
    private \Closure $factory;

    /** @param callable(): callable $factory Produces a fresh deriver `fn(?Record, Record): mixed`. */
    public function __construct(callable $factory)
    {
        $this->factory = $factory(...);
    }

    /** Build a fresh deriver instance. */
    public function build(): callable
    {
        return ($this->factory)();
    }
}
