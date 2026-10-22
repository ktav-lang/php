<?php

declare(strict_types=1);

namespace Ktav\Pure;

use Ktav\KtavException;

/**
 * Value → Ktav text writer for the pure-PHP backend.
 *
 * Two output modes: `pretty` (Ktav::dumps — multi-line strings prefer
 * the readable indented stripped form) and `canonical` (§ 5.9 —
 * verbatim preferred, stripped fallback), plus force-strings
 * coercion. Values arrive as native PHP scalars/arrays or the parser's
 * PureObject / PureList (which preserve empty-compound identity).
 * Output is built by amortised `$out .=` chunking — never per-byte
 * concatenation; complexity is O(output bytes + key work). Object
 * order is insertion order — the spec mandates NO sorting (§ 5.9.11).
 *
 * Floats are rendered shortest-round-trip (json_encode with
 * serialize_precision -1, a Ryu-equivalent) and then re-laid-out per
 * the § 5.9.8 thresholds and tie-break conventions.
 */
final class PureWriter
{
    /** @var bool */
    private $canonical;

    /** @var bool */
    private $force;

    /** @var string */
    private $out = '';

    /** @var string[] current key path, for Unrepresentable envelopes */
    private $path = [];

    private function __construct(bool $canonical, bool $force)
    {
        $this->canonical = $canonical;
        $this->force = $force;
    }

    /**
     * Internal rendering entry for the source formatter: the
     * constructor stays private; the formatter only renders line
     * fragments through the emit*Public API below.
     */
    public static function forFormatter(): self
    {
        return new self(false, false);
    }

    // -----------------------------------------------------------------
    // Value model conversion
    // -----------------------------------------------------------------

    /**
     * Normalize a public value into the internal model: assoc array →
     * PureObject, sequential list → PureList, `['$i' => digits]` →
     * Integer (int, or PureBigInt beyond PHP_INT_MAX — no float
     * conversion), `['$f' => text]` → float.
     *
     * Empty PHP array identity: PHP cannot distinguish `[]` (empty
     * list) from `{}` (empty object), so a NESTED empty array follows
     * the EMPTY LIST convention (PureList) — the same distinction
     * json_encode makes on the native wire layer (WireJson::wrap keeps
     * nested empty arrays as JSON `[]`), and the same convention the
     * corpus pins (tests/valid/arrays/empty_inline.canonical.ktav:
     * `tags: []` / `items: []`; arrays/nested third item `[]`). Only
     * the ROOT call ($isRoot, from renderDocument) keeps the documented
     * empty-Object convention so `dumps([]) === ''` and
     * `emitCanonical([]) === ''` (§ 5.9.0 empty Object root; pinned by
     * ValueModelSpec against rust render.rs emit_root).
     *
     * Cyclic PHP references in arrays are rejected by a recursive-count prepass.
     *
     * @param mixed $v
     * @param bool $isRoot true only for the renderDocument root call
     * @return mixed
     */
    public static function toInternal($v, bool $isRoot = false)
    {
        if (is_array($v)) {
            \Ktav\Internal\ArrayGraph::assertAcyclic($v);
        }
        $activeObjects = new \SplObjectStorage();
        return self::toInternalInner($v, $isRoot, $activeObjects);
    }

    /**
     * @param mixed $v
     * @return mixed
     */
    private static function toInternalInner($v, bool $isRoot, \SplObjectStorage $activeObjects)
    {
        if ($v instanceof PureObject || $v instanceof PureList) {
            self::assertAcyclicInternal($v, $activeObjects);
            return $v;
        }
        if (!is_array($v)) {
            return $v; // null / bool / int / float / string
        }
        if (count($v) === 1) {
            if (isset($v['$i']) && is_string($v['$i'])) {
                return self::reviveInteger($v['$i']);
            }
            if (isset($v['$f']) && is_string($v['$f'])) {
                return (float) $v['$f'];
            }
        }
        if ($v === []) {
            // Root call: documented empty PHP array = {} (§ 5.9.0).
            // Nested: empty list convention — see the docblock above.
            return $isRoot ? new PureObject() : new PureList();
        }
        if (self::isList($v)) {
            $out = new PureList();
            foreach ($v as $k => $_) {
                $out->items[] = self::toInternalInner($_, false, $activeObjects);
            }
            return $out;
        }
        $obj = new PureObject();
        foreach ($v as $k => $_) {
            $obj->pairs[(string) $k] = self::toInternalInner($_, false, $activeObjects);
        }
        return $obj;
    }

    /**
     * int when the digits round-trip, PureBigInt (decimal string)
     * otherwise — same convention as WireJson::reviveInteger.
     *
     * @return int|PureBigInt
     */
    public static function reviveInteger(string $digits)
    {
        $i = (int) $digits;
        if ((string) $i === $digits) {
            return $i;
        }
        return new PureBigInt($digits);
    }

    /** @param mixed $v */
    private static function isList($v): bool
    {
        $i = 0;
        foreach ($v as $k => $_) {
            if ($k !== $i++) {
                return false;
            }
        }
        return true;
    }

    // -----------------------------------------------------------------
    // Document rendering
    // -----------------------------------------------------------------

    /**
     * Render an internal (or public) root value as a full document.
     *
     * @param mixed $root
     */
    public static function renderDocument($root, bool $canonical, bool $force): string
    {
        $root = self::toInternal($root, true);
        if (!($root instanceof PureObject) && !($root instanceof PureList)) {
            PureKtav::failEnvelope([
                'error' => 'Unrepresentable',
                'reason' => 'ScalarRoot',
                'spec_section' => '§5.9.0',
                'message' => 'ScalarRoot: the document root is not an Object or an Array',
            ]);
        }
        $w = new self($canonical, $force);
        $w->emitRoot($root);
        return $w->out;
    }

    /** Validate mutable internal nodes by active path; shared DAGs remain valid. */
    private static function assertAcyclicInternal($value, \SplObjectStorage $active)
    {
        if (!($value instanceof PureObject) && !($value instanceof PureList)) {
            return;
        }
        if ($active->contains($value)) {
            PureKtav::failEnvelope([
                'error' => 'Message',
                'reason' => null,
                'message' => 'cyclic internal value cannot be rendered as a Ktav document',
            ]);
        }
        $active->attach($value);
        try {
            $children = $value instanceof PureObject ? $value->pairs : $value->items;
            foreach ($children as $child) {
                if ($child instanceof PureObject || $child instanceof PureList) {
                    self::assertAcyclicInternal($child, $active);
                }
            }
        } finally {
            $active->detach($value);
        }
    }

    /** @return void */
    private function emitRoot($root)
    {
        if ($root instanceof PureObject) {
            $first = true;
            foreach ($root->pairs as $k => $v) {
                $this->emitDottedPair([(string) $k], $v, 0, $first);
                $first = false;
            }
            return;
        }
        $items = $root->items;
        if ($items === []) {
            // § 5.9.3: empty Array root → `[]` line.
            $this->out .= "[]\n";
            return;
        }
        // § 5.9.3 wrap: first item that is itself a compound would be
        // root-detected on its own first line.
        if ($items[0] instanceof PureObject || $items[0] instanceof PureList) {
            $this->out .= "[\n";
            foreach ($items as $item) {
                $this->emitItem($item, 1, false);
            }
            $this->out .= "]\n";
            return;
        }
        $first = true;
        foreach ($items as $item) {
            $this->emitItem($item, 0, $first);
            $first = false;
        }
    }

    /**
     * Emit `k1: { k2: … : value }` — dotted key path rendered as the
     * explicit nested form (§ 5.9.10: dotted keys are never re-used).
     *
     * @param string[] $segs
     * @param mixed $value
     * @return void
     */
    private function emitDottedPair(array $segs, $value, int $indent, bool $isRootKey)
    {
        if (count($segs) === 1) {
            $this->emitPair($segs[0], $value, $indent, $isRootKey);
            return;
        }
        $this->indent($indent);
        $this->emitKeySegment($segs[0], $isRootKey);
        $this->out .= ": {\n";
        $path = $this->path;
        $this->path[] = $segs[0];
        // Offset-based recursion: NO array_slice per level — slicing
        // copied the remaining tail at every dotted-segment depth,
        // O(k²) in key-path length k (round-2 review P2).
        $this->emitDottedPairFrom($segs, 1, $value, $indent + 1, false);
        $this->path = $path;
        $this->indent($indent);
        $this->out .= "}\n";
    }

    /**
     * emitDottedPair core over $segs[$from ..] — byte-identical
     * output, no per-level array copies.
     *
     * @param string[] $segs
     * @param mixed $value
     * @return void
     */
    private function emitDottedPairFrom(array $segs, int $from, $value, int $indent, bool $isRootKey)
    {
        if (count($segs) - $from === 1) {
            $this->emitPair($segs[$from], $value, $indent, $isRootKey);
            return;
        }
        $this->indent($indent);
        $this->emitKeySegment($segs[$from], $isRootKey);
        $this->out .= ": {\n";
        $path = $this->path;
        $this->path[] = $segs[$from];
        $this->emitDottedPairFrom($segs, $from + 1, $value, $indent + 1, false);
        $this->path = $path;
        $this->indent($indent);
        $this->out .= "}\n";
    }

    /**
     * One `key: value` line (§ 5.9.5).
     *
     * @param mixed $value
     * @return void
     */
    private function emitPair(string $key, $value, int $indent, bool $isRootKey)
    {
        PureWorkMeter::tick(); // per emitted pair line
        // § 5.9.0 EmptyKeyName, before any key byte is emitted.
        if ($key === '') {
            $this->fail('EmptyKeyName');
        }
        $this->indent($indent);
        $this->emitKeySegment($key, $isRootKey);
        $path = $this->path;
        $this->path[] = $key;

        if ($this->force) {
            $text = $this->forcedStringText($value);
            if ($text !== null) {
                $this->emitStringInPair($text, $indent);
                $this->path = $path;
                return;
            }
        }

        if ($value === null) {
            $this->out .= ": null\n";
        } elseif (is_bool($value)) {
            $this->out .= $value ? ": true\n" : ": false\n";
        } elseif (is_int($value)) {
            $this->out .= ': ' . $value . "\n";
        } elseif ($value instanceof PureBigInt) {
            $this->out .= ': ' . $value->digits . "\n";
        } elseif (is_float($value)) {
            $this->out .= ': ' . $this->floatText($value) . "\n";
        } elseif (is_string($value)) {
            $this->emitStringInPair($value, $indent);
        } elseif ($value instanceof PureList) {
            if ($value->items === []) {
                $this->out .= ": []\n";
            } else {
                $this->out .= ": [\n";
                foreach ($value->items as $item) {
                    $this->emitItem($item, $indent + 1, false);
                }
                $this->indent($indent);
                $this->out .= "]\n";
            }
        } elseif ($value instanceof PureObject) {
            if ($value->pairs === []) {
                $this->out .= ": {}\n";
            } else {
                $this->out .= ": {\n";
                $first = true;
                foreach ($value->pairs as $k => $v) {
                    $this->emitPair((string) $k, $v, $indent + 1, false);
                    $first = false;
                }
                $this->indent($indent);
                $this->out .= "}\n";
            }
        } else {
            $this->unsupported($value);
        }
        $this->path = $path;
    }

    /**
     * One array-item line (§ 5.9.6). $atDocStart gates only the
     * § 5.9.12 U+FEFF guard (byte offset 0 of the document).
     *
     * @param mixed $value
     * @return void
     */
    public function emitItemPublic($value, int $indent, bool $isRootArrayFirst, bool $atDocStart = true)
    {
        $this->emitItem($value, $indent, $isRootArrayFirst, $atDocStart);
    }

    /** Public wrapper for the formatter's dotted-key pair rendering. */
    public function emitDottedPairPublic(array $segs, $value, int $indent, bool $isRootKey)
    {
        $this->emitDottedPair($segs, $value, $indent, $isRootKey);
    }

    /**
     * Formatter block support: emit the opener line(s) for a pair
     * whose compound value the formatter renders from its own trivia
     * stream — a dotted key opens one `{` level per segment, and the
     * innermost level opens `[` when $leafIsList (matching the pretty
     * writer, § 5.9.6). Returns
     * the number of openers emitted; the formatter owes that many
     * closers (`}`/`]`), innermost first.
     *
     * @param string[] $segs
     * @return int
     */
    public function emitCompoundOpen(array $segs, int $indent, bool $isRootKey, bool $leafIsList = false): int
    {
        $n = 0;
        $last = count($segs) - 1;
        foreach ($segs as $seg) {
            // The innermost level opens `[` when the leaf value is an
            // Array — matching emitPair/emitDottedPair output (§ 5.9.6);
            // otherwise the formatter would emit `key: {` for an array
            // body, which does not re-parse.
            $opener = $leafIsList && $n === $last ? '[' : '{';
            $this->indent($indent + $n);
            $this->emitKeySegment((string) $seg, $isRootKey && $n === 0);
            $this->out .= ': ' . $opener . "\n";
            ++$n;
        }
        return $n;
    }

    /** Amortised-buffer accessor for partial-document rendering. */
    public function flush(): string
    {
        return $this->out;
    }

    /**
     * One array-item line (§ 5.9.6).
     *
     * @param mixed $value
     * @return void
     */
    public function emitItem($value, int $indent, bool $isRootArrayFirst, bool $atDocStart = true)
    {
        PureWorkMeter::tick(); // per emitted item line
        $this->indent($indent);

        if ($this->force) {
            $text = $this->forcedStringText($value);
            if ($text !== null) {
                $this->emitStringAsItem($text, $indent, $isRootArrayFirst, $atDocStart);
                return;
            }
        }

        if ($value === null) {
            $this->out .= "null\n";
        } elseif (is_bool($value)) {
            $this->out .= $value ? "true\n" : "false\n";
        } elseif (is_int($value)) {
            $this->out .= $value . "\n";
        } elseif ($value instanceof PureBigInt) {
            $this->out .= $value->digits . "\n";
        } elseif (is_float($value)) {
            $this->out .= $this->floatText($value) . "\n";
        } elseif (is_string($value)) {
            $this->emitStringAsItem($value, $indent, $isRootArrayFirst, $atDocStart);
        } elseif ($value instanceof PureList) {
            if ($value->items === []) {
                $this->out .= "[]\n";
            } else {
                $this->out .= "[\n";
                foreach ($value->items as $item) {
                    $this->emitItem($item, $indent + 1, false);
                }
                $this->indent($indent);
                $this->out .= "]\n";
            }
        } elseif ($value instanceof PureObject) {
            if ($value->pairs === []) {
                $this->out .= "{}\n";
            } else {
                $this->out .= "{\n";
                foreach ($value->pairs as $k => $v) {
                    $this->emitPair((string) $k, $v, $indent + 1, false);
                }
                $this->indent($indent);
                $this->out .= "}\n";
            }
        } else {
            $this->unsupported($value);
        }
    }

    /** @return void */
    private function unsupported($value)
    {
        throw new KtavException('unsupported value type for Ktav::dumps: ' . gettype($value));
    }

    /**
     * Float text: the CANONICAL writer uses the § 5.9.8 relayout; the
     * PRETTY writers (dumps / dumpsForceStrings) emit the same payload
     * the native path puts on the wire — json_encode's shortest
     * round-trip form with a forced decimal point (WireJson::wrap).
     *
     * @param float $value
     * @return string
     */
    private function floatText($value)
    {
        if (!is_finite($value)) {
            if ($this->canonical) {
                // Canonical path: the Rust writer's attach_path pins
                // UnrepresentableAt WITH the path (ErrorEnvelopeSpec).
                // emitPair pushes the key before calling floatText;
                // emitItem items carry no key, so the path is as-is.
                $msg = PureFloat::reasonDisplay('NonFiniteFloat') . ' at ' . self::phpDebugList($this->path);
                PureKtav::failEnvelope([
                    'error' => 'UnrepresentableAt',
                    'reason' => 'NonFiniteFloat',
                    'spec_section' => '§5.9.0',
                    'path' => $this->path,
                    'message' => $msg,
                ]);
            }
            // Non-finite never reaches the native renderer at all: the
            // PHP wire wrapper rejects it first (Ktav::dumps path), so
            // the pretty envelope is the wire's, without a path.
            PureKtav::failEnvelope([
                'error' => 'Unrepresentable',
                'reason' => 'NonFiniteFloat',
                'spec_section' => '§5.9.0',
                'message' => 'NonFiniteFloat: a Float is NaN or Infinity',
            ]);
        }
        if ($this->canonical) {
            return PureFloat::formatFloatText($value);
        }
        $s = PureFloat::shortestDigits($value); // ini-independent (§ 5.9.8)
        if (strpos($s, '.') === false && strpos($s, 'e') === false && strpos($s, 'E') === false) {
            $s .= '.0';
        }
        return $s;
    }

    /**
     * to_string_force_strings textual form; null for compounds. The
     * native path coerces through the wire wrapper, so a Float's text
     * is the wire payload and a non-finite Float is the wire error.
     *
     * @param mixed $value
     * @return string|null
     */
    private function forcedStringText($value)
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if ($value instanceof PureBigInt) {
            return $value->digits;
        }
        if (is_float($value)) {
            return $this->floatText($value);
        }
        if (is_string($value)) {
            return $value;
        }
        return null;
    }

    /**
     * § 5.9.7 string form selection (helpers live in PureText).
     * -----------------------------------------------------------------
     */

    /** @return void */
    private function emitStringInPair(string $s, int $indent)
    {
        if (strpos($s, "\r") !== false) {
            $this->fail('CRByte');
        }
        if (PureText::stringNeedsMultiline($s)) {
            $this->emitMultiline($s, $indent, true);
            return;
        }
        if ($s === '') {
            // § 5.9.7: empty String pair → `key:` with no body.
            $this->out .= $this->canonical ? ":\n" : ": \n";
            return;
        }
        if (PureText::needsRawMarker($s)) {
            $this->out .= ':: ' . $s . "\n";
        } else {
            $this->out .= ': ' . $s . "\n";
        }
    }

    /** @return void */
    private function emitStringAsItem(string $s, int $indent, bool $isRootArrayFirst, bool $atDocStart = true)
    {
        if (strpos($s, "\r") !== false) {
            $this->fail('CRByte');
        }
        if (PureText::stringNeedsMultiline($s)) {
            $this->emitMultiline($s, $indent, false);
            return;
        }
        if ($s === '') {
            // § 5.9.6: empty String item → bare `::`.
            $this->out .= "::\n";
            return;
        }
        if (PureText::itemNeedsRawMarker($s)
            || ($isRootArrayFirst
                && (PureScanner::isPairShape($s)
                    || ($atDocStart && strpos($s, "\xEF\xBB\xBF") === 0)))) {
            $this->out .= ':: ' . $s . "\n";
            return;
        }
        $this->out .= $s . "\n";
    }

    /** @return void */
    private function emitMultiline(string $s, int $indent, bool $isPair)
    {
        // Canonical prefers verbatim; pretty (dumps) prefers the
        // readable indented stripped form (§ 5.6.1 chooser).
        $form = PureText::chooseMultilineForm($s, !$this->canonical);
        if ($form === 'verbatim') {
            $this->out .= $isPair ? ": ((\n" : "((\n";
            $this->out .= $s . "\n";
            $this->indent($indent);
            $this->out .= "))\n";
            return;
        }
        if ($this->canonical) {
            // Stripped fallback: body at indent 0 so the common-indent
            // computation yields zero (§ 5.9.7).
            $this->out .= $isPair ? ": (\n" : "(\n";
            $this->out .= $s . "\n";
            $this->indent($indent);
            $this->out .= ")\n";
            return;
        }
        // Pretty stripped: content lines at indent + 1 (dedented back
        // by the parser on re-read; blank lines stay blank).
        $contentIndent = $indent + 1;
        $this->out .= $isPair ? ": (\n" : "(\n";
        foreach (explode("\n", $s) as $line) {
            if ($line !== '') {
                $this->indent($contentIndent);
                $this->out .= $line;
            }
            $this->out .= "\n";
        }
        $this->indent($indent);
        $this->out .= ")\n";
    }

    /** @return void */
    private function fail(string $code)
    {
        $msg = PureFloat::reasonDisplay($code);
        if ($this->path !== []) {
            $msg .= ' at ' . self::phpDebugList($this->path);
        }
        PureKtav::failEnvelope([
            'error' => 'UnrepresentableAt',
            'reason' => $code,
            'spec_section' => $code === 'CRByte' ? '§5.9.7' : '§5.9.0',
            'path' => $this->path,
            'message' => $msg,
        ]);
    }

    /** Rust `Vec<String>` Debug rendering for the path suffix. */
    private static function phpDebugList(array $path): string
    {
        $parts = [];
        foreach ($path as $p) {
            $parts[] = '"' . addcslashes($p, "\"\\") . '"';
        }
        return '[' . implode(', ', $parts) . ']';
    }

    // -----------------------------------------------------------------
    // § 5.9.10 keys
    // -----------------------------------------------------------------

    /**
     * Emit one key segment — bare or quoted per § 5.9.10 (a)-(d),
     * one pass over the bytes.
     *
     * @return void
     */
    private function emitKeySegment(string $key, bool $rootFirstKey)
    {
        $n = strlen($key);
        // Rules (b)/(c)/(d): leading code point(s) only.
        if ($n > 0) {
            $b0 = ord($key[0]);
            if (PureScanner::isQuoteByte($b0)
                || ($rootFirstKey && substr($key, 0, 3) === "\xEF\xBB\xBF")
                || substr($key, 0, 2) === '##') {
                $this->emitQuotedKey($key);
                return;
            }
            // (a) edge § 3.3 whitespace other than LF/CR — quoting
            // buys nothing for LF/CR (a quoted segment never admits
            // them raw either).
            $firstCp = PureText::cpAt($key, 0);
            if (PureText::isInlineWsCp($firstCp[0])) {
                $this->emitQuotedKey($key);
                return;
            }
            $lastStart = $n - 1;
            while ($lastStart > 0 && (ord($key[$lastStart]) & 0xC0) === 0x80) {
                --$lastStart;
            }
            $lastCp = PureText::cpAt($key, $lastStart);
            if (PureText::isInlineWsCp($lastCp[0])) {
                $this->emitQuotedKey($key);
                return;
            }
        }
        $needsRewrite = false;
        for ($i = 0; $i < $n; ++$i) {
            $b = ord($key[$i]);
            switch ($b) {
                case 0x2E: case 0x3A: case 0x2C: // . : ,
                case 0x7B: case 0x7D: case 0x5B: case 0x5D: // { } [ ]
                case 0x28: case 0x29: // ( )
                    $this->emitQuotedKey($key);
                    return;
                case 0x5C: case 0x0A: case 0x0D: case 0x7F:
                    $needsRewrite = true;
                    break;
                default:
                    if ($b < 0x20 && $b !== 0x09 && $b !== 0x0B && $b !== 0x0C) {
                        $needsRewrite = true;
                    }
            }
        }
        if ($needsRewrite) {
            $this->emitBareKeyEscaped($key);
            return;
        }
        $this->out .= $key;
    }

    /** Bare form recipe (§ 5.9.10): named escapes, else \uXXXX. */
    private function emitBareKeyEscaped(string $key)
    {
        $n = strlen($key);
        $i = 0;
        $lastStart = $n - 1;
        while ($lastStart > 0 && (ord($key[$lastStart]) & 0xC0) === 0x80) {
            --$lastStart;
        }
        while ($i < $n) {
            $b = ord($key[$i]);
            if ($b < 0x80) {
                switch ($b) {
                    case 0x5C: $this->out .= '\\\\'; break;
                    case 0x0A: $this->out .= '\n'; break;
                    case 0x0D: $this->out .= '\r'; break;
                    case 0x2E: $this->out .= '\.'; break;
                    case 0x3A: $this->out .= '\:'; break;
                    case 0x2C: $this->out .= '\,'; break;
                    case 0x7B: $this->out .= '\{'; break;
                    case 0x7D: $this->out .= '\}'; break;
                    case 0x5B: $this->out .= '\['; break;
                    case 0x5D: $this->out .= '\]'; break;
                    default:
                        if ($b < 0x20 || $b === 0x7F) {
                            // Tab/VT/FF are § 3.3 members: raw interior,
                            // \uXXXX at an edge — unreachable at an edge
                            // in bare form (routed to quoted), kept raw.
                            if (($b === 0x09 || $b === 0x0B || $b === 0x0C) && $i !== 0 && $i !== $lastStart) {
                                $this->out .= chr($b);
                            } else {
                                $this->emitUnicodeEscape($b);
                            }
                        } else {
                            $this->out .= chr($b);
                        }
                }
                ++$i;
                continue;
            }
            // Multibyte code point.
            list($cp, $len) = PureText::cpAt($key, $i);
            if (PureText::isKtavWsCp($cp) && ($i === 0 || $i === $lastStart)) {
                // § 3.3 whitespace at an edge: \uXXXX (LF/CR already
                // handled above; unreachable in bare form anyway).
                $this->emitUnicodeEscape($cp);
            } else {
                $this->out .= substr($key, $i, $len);
            }
            $i += $len;
        }
    }

    /** Quoted form (§ 5.9.10): fixed `"` delimiter, minimal escapes. */
    private function emitQuotedKey(string $key)
    {
        $this->out .= '"';
        $n = strlen($key);
        $i = 0;
        while ($i < $n) {
            $b = ord($key[$i]);
            if ($b < 0x80) {
                switch ($b) {
                    case 0x22: $this->out .= '\\"'; break;
                    case 0x5C: $this->out .= '\\\\'; break;
                    case 0x0A: $this->out .= '\n'; break;
                    case 0x0D: $this->out .= '\r'; break;
                    default:
                        if (($b < 0x20 && $b !== 0x09 && $b !== 0x0B && $b !== 0x0C) || $b === 0x7F) {
                            $this->emitUnicodeEscape($b);
                        } else {
                            $this->out .= chr($b);
                        }
                }
                ++$i;
                continue;
            }
            list($cp, $len) = PureText::cpAt($key, $i);
            if (($cp < 0x20 && !($cp === 0x09 || $cp === 0x0B || $cp === 0x0C)) || $cp === 0x7F) {
                $this->emitUnicodeEscape($cp);
            } else {
                $this->out .= substr($key, $i, $len);
            }
            $i += $len;
        }
        $this->out .= '"';
    }

    /** \uXXXX with exactly four UPPERCASE hex digits (§ 3.7.1). */
    private function emitUnicodeEscape(int $cp)
    {
        if ($cp > 0xFFFF) {
            $cp -= 0x10000;
            $hi = 0xD800 | ($cp >> 10);
            $lo = 0xDC00 | ($cp & 0x3FF);
            $this->out .= '\u' . strtoupper(sprintf('%04X', $hi))
                . '\u' . strtoupper(sprintf('%04X', $lo));
            return;
        }
        $this->out .= '\u' . strtoupper(sprintf('%04X', $cp));
    }

    // -----------------------------------------------------------------
    // Small helpers
    // -----------------------------------------------------------------

    /** @return void */
    private function indent(int $level)
    {
        if ($level > 0) {
            $this->out .= str_repeat('    ', $level);
        }
    }

}
