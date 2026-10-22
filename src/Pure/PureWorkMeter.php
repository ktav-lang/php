<?php

declare(strict_types=1);

namespace Ktav\Pure;

/**
 * Per-byte work accounting for deterministic complexity checks;
 * never affects output.
 */
final class PureWorkMeter
{
    /** @var int */
    public static $ticks = 0;

    public static function tick(int $n = 1): void
    {
        self::$ticks += $n;
    }

    public static function reset(): void
    {
        self::$ticks = 0;
    }
}
