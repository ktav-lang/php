<?php

declare(strict_types=1);

namespace Ktav\Pure;

/**
 * Comment-preserving source formatter (documented `format`
 * semantics): comments verbatim, blank runs of two or more collapse
 * to exactly one, no blank padding inside brackets (content lines are
 * re-rendered at their canonical indent), key order never changed,
 * fixed point. A document with no comments and no blank lines comes
 * out byte-identical to `canonicalFromSource`.
 *
 * Implementation: one parse pass that records a NESTED trivia stream
 * — each compound body's events buffer under the compound's own event
 * — so every source line reaches the renderer exactly once. Leaf
 * lines render via PureWriter::forFormatter(); a compound whose body
 * carried trivia renders opener/kid-stream/closers recursively, one
 * without trivia renders whole from its value. O(bytes) parse +
 * O(output) render.
 */
final class PureFormatter
{
    /** @return string */
    public static function format(string $src): string
    {
        $events = [];
        $meta = [];
        PureParser::parse($src, false, $events, $meta);

        // No trivia at all: the output IS the canonical form.
        if (!self::hasTrivia($events)) {
            return PureKtav::canonicalFromSource($src);
        }
        return self::emit($events, $meta);
    }

    /** Trivia anywhere in a (nested) event stream? */
    private static function hasTrivia(array $events): bool
    {
        foreach ($events as $e) {
            if ($e[0] === 'blank' || $e[0] === 'comment') {
                return true;
            }
            if (($e[0] === 'pair' || $e[0] === 'item')
                && is_array($e[4]) && self::hasTrivia($e[4])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Line-based trivia-aware emission.
     *
     * @param array[] $events
     * @param array $meta
     * @return string
     */
    private static function emit(array $events, array $meta): string
    {
        // § 5.9.12: an implicitly-rooted Object's first key gets the
        // root-first guards (e.g. BOM quoting).
        $rootKeyGuard = $meta !== null && $meta['rootKind'] === 'o'
            && !$meta['explicit'] && !$meta['inline'];

        // § 5.9.3: an (implicitly-rooted) Array whose first value is a
        // compound would re-parse with a different root kind — wrap.
        // An EXPLICIT `[` root also re-emits its `[`/`]` even when its
        // only events are comments/blanks (identity, § 5.9.3).
        $wrap = false;
        if ($meta !== null && $meta['rootKind'] === 'a' && !$meta['inline']) {
            if (!empty($meta['explicit'])) {
                $wrap = true;
            } else {
                foreach ($events as $e) {
                    if ($e[0] === 'blank' || $e[0] === 'comment') {
                        continue;
                    }
                    if ($e[0] === 'item'
                        && ($e[1] instanceof PureObject || $e[1] instanceof PureList)) {
                        $wrap = true;
                    }
                    break;
                }
            }
        }
        if ($wrap) {
            $events = array_merge([['wrapOpen']], $events, [['wrapClose']]);
        }
        $out = '';
        $emitted = false;
        self::renderEvents($events, 0, $out, $emitted, $rootKeyGuard, true, $wrap);
        return $out;
    }

    /**
     * Render one event stream (one nesting level) at $indent.
     *
     * @param array[] $events
     * @param bool $top trailing blank lines collapse at document level
     *        only — never padded inside brackets
     * @param bool $wrap the § 5.9.3 wrapper is active (top level only)
     * @return void
     */
    private static function renderEvents(array $events, int $indent, string &$out, bool &$emitted, bool $rootKeyGuard, bool $top, bool $wrap)
    {
        $blankRun = 0;
        // Per-stream emitted flag: a pending blank run BEFORE the first
        // emitted event of a NON-top stream is dropped (no blank
        // padding inside brackets); $emitted stays document-wide for
        // the § 5.9.12 root guards.
        $streamEmitted = false;
        foreach ($events as $e) {
            switch ($e[0]) {
                case 'blank':
                    ++$blankRun;
                    break;
                case 'wrapOpen':
                    $out .= "[\n";
                    break;
                case 'wrapClose':
                    $out .= "]\n";
                    break;
                case 'comment':
                    if ($blankRun > 0 && ($top || $streamEmitted)) {
                        $out .= "\n";
                    }
                    $blankRun = 0;
                    $out .= $e[1] . "\n";
                    $emitted = true;
                    $streamEmitted = true;
                    break;
                case 'root':
                    if ($blankRun > 0 && ($top || $streamEmitted)) {
                        $out .= "\n";
                    }
                    $blankRun = 0;
                    $out .= PureWriter::renderDocument($e[1], false, false);
                    $emitted = true;
                    $streamEmitted = true;
                    break;
                case 'pair':
                    if ($blankRun > 0 && ($top || $streamEmitted)) {
                        $out .= "\n";
                    }
                    $blankRun = 0;
                    self::renderPair($e, $indent, $out, $emitted, $rootKeyGuard);
                    $emitted = true;
                    $streamEmitted = true;
                    break;
                case 'item':
                    if ($blankRun > 0 && ($top || $streamEmitted)) {
                        $out .= "\n";
                    }
                    $blankRun = 0;
                    self::renderItem($e, $indent, $out, $emitted, $wrap);
                    $emitted = true;
                    $streamEmitted = true;
                    break;
            }
        }
        if ($blankRun > 0 && $top) {
            $out .= "\n";
        }
    }

    /**
     * One pair line: a trivia-bearing compound body renders opener /
     * recursive kid stream / closers; anything else renders whole via
     * the pretty writer.
     *
     * @param array[] $e
     * @return void
     */
    private static function renderPair(array $e, int $indent, string &$out, bool &$emitted, bool $rootKeyGuard)
    {
        $w = PureWriter::forFormatter();
        if (is_array($e[4])) {
            // A leaf Array value opens/closes with [ ] — one per
            // § 5.9.10 dotted segment, matching emitDottedPair output
            // (PureWriter::emitCompoundOpen $leafIsList).
            $leafIsList = $e[2] instanceof PureList;
            $n = $w->emitCompoundOpen($e[1], $indent, $rootKeyGuard && !$emitted, $leafIsList);
            $out .= $w->flush();
            self::renderEvents($e[4], $indent + $n, $out, $emitted, false, false, false);
            for ($k = $n - 1; $k >= 0; --$k) {
                // Innermost first: the leaf closer is $k === $n - 1.
                $closer = $leafIsList && $k === $n - 1 ? ']' : '}';
                $out .= str_repeat('    ', $indent + $k) . $closer . "\n";
            }
            return;
        }
        $w->emitDottedPairPublic($e[1], $e[2], $indent, $rootKeyGuard && !$emitted);
        $out .= $w->flush();
    }

    /**
     * One array-item line (§ 5.9.6); same block/leaf split as pairs.
     * The first root item's § 5.9.12 guards switch off inside the
     * § 5.9.3 wrap wrapper.
     *
     * @param array[] $e
     * @return void
     */
    private static function renderItem(array $e, int $indent, string &$out, bool &$emitted, bool $wrap)
    {
        if (is_array($e[4])) {
            $opener = $e[1] instanceof PureObject ? '{' : '[';
            $closer = $e[1] instanceof PureObject ? '}' : ']';
            $out .= str_repeat('    ', $indent + ($wrap ? 1 : 0)) . $opener . "\n";
            self::renderEvents($e[4], $indent + 1 + ($wrap ? 1 : 0), $out, $emitted, false, false, false);
            $out .= str_repeat('    ', $indent + ($wrap ? 1 : 0)) . $closer . "\n";
            return;
        }
        $w = PureWriter::forFormatter();
        // Inside the wrap no item line is root-detected, so the
        // § 5.9.6 first-item exclusions switch off.
        $w->emitItemPublic($e[1], $indent + ($wrap ? 1 : 0), $e[3] && !$wrap, !$emitted);
        $out .= $w->flush();
    }
}
