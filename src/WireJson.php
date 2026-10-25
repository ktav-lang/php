<?php

declare(strict_types=1);

namespace Ktav;

use Ktav\Internal\ArrayGraph;

/**
 * JSON ⇄ PHP value bridge. The cabi side speaks JSON with
 * `{"$i":"<digits>"}` / `{"$f":"<text>"}` tagged wrappers around
 * typed integers / floats — same wire format used by the Java / Go /
 * .NET / JS bindings.
 *
 * Read path: cabi → JSON bytes → PHP value. `$i` becomes an `int`
 * when it fits in PHP_INT_*, otherwise a `string` (PHP has no native
 * arbitrary-precision integer; users wanting GMP can post-process).
 * `$f` becomes a `float`.
 *
 * Write path: PHP value → JSON bytes → cabi. `int` (any width) gets
 * the `$i` envelope; `float` (finite) gets `$f` with a forced
 * decimal point; bigint-as-string requires the user to wrap it
 * themselves as `['$i' => '999...']`.
 */
final class WireJson
{
    /** @return mixed */
    public static function decode(string $json)
    {
        if ($json === '') {
            throw new KtavException('empty JSON from native');
        }
        $parsed = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return self::revive($parsed);
    }

    /** @param mixed $value */
    public static function encode($value): string
    {
        if (is_array($value)) {
            ArrayGraph::assertAcyclic($value);
        }
        $wrapped = self::wrap($value);
        return json_encode(
            $wrapped,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * EDIT-ONLY wire encoding: identical to {@see encode} except that
     * an EMPTY `stdClass` is accepted as an explicit empty Object
     * (`{}` on the wire) — a PHP `[]` cannot express it and the edit
     * contract's emptying_block fixture needs the Object identity.
     * dumps/writer semantics are NOT extended: wrap() in dumps mode
     * keeps rejecting stdClass, and a NON-empty stdClass (including a
     * self-referencing one) is a typed Message transport failure here
     * too — never a recursion and never a coerced scalar.
     *
     * @param mixed $value
     * @throws KtavException Message envelope on a non-empty stdClass
     */
    public static function encodeEditReplacement($value): string
    {
        if (is_array($value)) {
            ArrayGraph::assertAcyclic($value);
        }
        $wrapped = self::wrap($value, true);
        return json_encode(
            $wrapped,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @param mixed $v
     * @return mixed
     */
    private static function revive($v)
    {
        if ($v === null || is_bool($v) || is_int($v) || is_float($v) || is_string($v)) {
            return $v;
        }
        if (!is_array($v)) {
            return $v;
        }
        // Tagged-wrapper detection: assoc array with a single key.
        if (count($v) === 1) {
            if (isset($v['$i']) && is_string($v['$i'])) {
                return self::reviveInteger($v['$i']);
            }
            if (isset($v['$f']) && is_string($v['$f'])) {
                return (float) $v['$f'];
            }
        }
        // Recurse into array (assoc or list).
        foreach ($v as $k => $child) {
            $v[$k] = self::revive($child);
        }
        return $v;
    }

    /** @return int|string */
    private static function reviveInteger(string $digits)
    {
        // Try plain int first.
        $i = (int) $digits;
        if ((string) $i === $digits) {
            return $i;
        }
        // Out of PHP int range — return as string (callers can use
        // GMP / BCMath if they need arithmetic on it).
        return $digits;
    }

    /**
     * @param mixed $v
     * @param bool $editObjects edit-only empty-stdClass Object support
     * @return mixed
     */
    private static function wrap($v, bool $editObjects = false)
    {
        if ($v === null || is_bool($v) || is_string($v)) {
            return $v;
        }
        if (is_int($v)) {
            return ['$i' => (string) $v];
        }
        if (is_float($v)) {
            if (!is_finite($v)) {
                throw KtavException::fromEnvelope([
                    'error' => 'Unrepresentable',
                    'reason' => 'NonFiniteFloat',
                    'spec_section' => '§5.9.0',
                    'message' => 'NonFiniteFloat: a Float is NaN or Infinity',
                ]);
            }
            // `(string) $v` is governed by the `precision` ini setting
            // (default 14 significant digits) and is lossy. `json_encode`
            // is governed by `serialize_precision` (default -1, shortest
            // round-trip form) — that's the one that's actually safe.
            // Force a decimal point if missing.
            $s = (string) json_encode($v);
            if (strpos($s, '.') === false && strpos($s, 'e') === false && strpos($s, 'E') === false) {
                $s .= '.0';
            }
            return ['$f' => $s];
        }
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $child) {
                $out[$k] = self::wrap($child, $editObjects);
            }
            return $out;
        }
        if ($editObjects && $v instanceof \stdClass) {
            // Empty only — non-empty is refused by encodeEditReplacement
            // on the top level and right here for nested values, so a
            // self-referencing stdClass can never recurse.
            if ((array) $v !== []) {
                throw KtavException::fromEnvelope([
                    'error' => 'Message',
                    'reason' => null,
                    'line' => null,
                    'line_text' => null,
                    'span' => null,
                    'path' => null,
                    'canonical' => null,
                    'spec_section' => null,
                    'body' => null,
                    'message' => 'edit replacement transport: only an EMPTY stdClass'
                        . ' (an explicit empty Object) is supported',
                ]);
            }
            return $v; // json_encode(new stdClass) === '{}'
        }
        throw new KtavException('unsupported value type for Ktav::dumps: ' . gettype($v));
    }
}
