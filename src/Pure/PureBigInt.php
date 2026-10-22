<?php

declare(strict_types=1);

namespace Ktav\Pure;

/**
 * An Integer beyond the PHP int range, carried as its exact decimal
 * digit string (same convention as WireJson::reviveInteger).
 */
final class PureBigInt
{
    /** @var string */
    public $digits;

    public function __construct(string $digits)
    {
        $this->digits = $digits;
    }
}
