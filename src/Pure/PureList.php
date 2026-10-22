<?php

declare(strict_types=1);

namespace Ktav\Pure;

/**
 * Internal Array value (ordered items) — keeps empty-compound
 * identity that plain PHP arrays cannot express.
 */
final class PureList
{
    /** @var array<int, mixed> */
    public $items = [];
}
