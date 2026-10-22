<?php

declare(strict_types=1);

namespace Ktav\Pure;

/**
 * Internal Object value (insertion-ordered pairs). Exists so
 * canonicalFromSource / the formatter can keep the Object-vs-Array
 * identity of EMPTY compounds, which plain PHP arrays cannot.
 */
final class PureObject
{
    /** @var array<string, mixed> insertion-ordered */
    public $pairs = [];
}
