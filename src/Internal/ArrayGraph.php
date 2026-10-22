<?php

declare(strict_types=1);

namespace Ktav\Internal;

use Ktav\KtavException;

/** @internal */
final class ArrayGraph
{
    /** @param array $value */
    public static function assertAcyclic(array $value): void
    {
        $recursive = false;
        $previous = null;
        $previous = set_error_handler(function ($severity, $message, $file, $line, $context = null) use (&$recursive, &$previous) {
            if ($severity === E_WARNING && is_string($message)
                && preg_match('/^count\(\): recursion detected$/i', $message)) {
                $recursive = true;
                return true;
            }
            if ($previous !== null) {
                if (func_num_args() >= 5) {
                    return call_user_func($previous, $severity, $message, $file, $line, $context);
                }
                return call_user_func($previous, $severity, $message, $file, $line);
            }
            return false;
        }, E_WARNING);
        try {
            count($value, COUNT_RECURSIVE);
        } finally {
            restore_error_handler();
        }
        if ($recursive) {
            throw KtavException::fromEnvelope([
                'error' => 'Message',
                'reason' => null,
                'spec_section' => null,
                'message' => 'cyclic PHP reference cannot be rendered as a Ktav document',
            ]);
        }
    }
}
