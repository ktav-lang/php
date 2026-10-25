<?php

declare(strict_types=1);

namespace Ktav\Pure;

use Ktav\KtavException;

/**
 * Single-pass byte-cursor parser for Ktav 0.8.1 (pure-PHP backend).
 *
 * One linear walk over the raw input bytes; line dispatch per § 5.1,
 * root detection per § 5.0.1, dotted-key merge per § 5.3.2 (hash-map
 * bookkeeping, O(1) per segment), inline compounds per § 5.8 with the
 * Rust core's declared 128-deep inline limit. UTF-8 is validated up
 * front (§ 6.15); all error spans are byte offsets over the raw
 * input. No preg, no split-on-comma of nested input, no tail
 * re-slicing: complexity is O(bytes + total key-segment work).
 */
final class PureParser
{
    use PureErrors;
    /** @var string raw input (BOM included; parsing starts past it) */
    private $src;

    /** @var int */
    private $len;

    /** @var int */
    private $start;

    /** @var bool */
    private $strict;

    /** @var array[] explicit frame stack: o / a / m frames */
    private $frames = [];

    /** @var PureObject|PureList|null */
    private $root;

    /** @var string 'o'|'a' */
    private $rootKind = 'o';

    /** @var bool root opened by a lone `{` / `[` (needs its closer) */
    private $rootExplicit = false;

    /** @var bool root fully constructed — further content is orphaned */
    private $rootDone = false;

    /** @var array|null formatter event collector */
    private $events;

    /** @var array|null meta for the formatter */
    private $meta;

    private function __construct(string $src, bool $strict, ?array &$events, ?array &$meta)
    {
        $this->src = $src;
        $this->len = strlen($src);
        $this->strict = $strict;
        $this->events = &$events;
        $this->meta = &$meta;
        // § 3.1: skip exactly one leading BOM, before anything else —
        // but only after UTF-8 validation ran over the raw bytes.
        $this->start = (substr($src, 0, 3) === "\xEF\xBB\xBF") ? 3 : 0;
    }

    /**
     * Parse a document. Returns the root PureObject / PureList.
     * When $events / $meta are supplied they receive the formatter's
     * trivia/structure stream.
     *
     * @return PureObject|PureList
     */
    public static function parse(string $src, bool $strict, ?array &$events = null, ?array &$meta = null)
    {
        // § 6.15: byte-level UTF-8 validation precedes every grammar
        // error. Span covers the first malformed sequence (extended
        // over continuation bytes / to EOF, like the Rust core).
        $bad = PureScanner::validateUtf8($src);
        if ($bad !== null) {
            list($line, ) = PureScanner::locate($src, $bad[0]);
            // § 6.15 (0.9): the line NUMBER travels, but line_text stays
            // NULL — the source is not valid UTF-8, so a "line text"
            // slice would decode replacement chars over bytes that are
            // not characters.
            PureKtav::failEnvelope([
                'error' => 'InvalidUtf8',
                'spec_section' => '§6.15',
                'line' => $line,
                'line_text' => null,
                'span' => ['start' => $bad[0], 'end' => $bad[1]],
                'body' => 'input is not valid UTF-8: invalid utf-8 sequence of '
                    . max(1, $bad[1] - $bad[0]) . ' byte(s) from index ' . $bad[0],
                'message' => 'InvalidUtf8: input is not valid UTF-8; first invalid byte sequence at byte offset ' . $bad[0],
            ]);
        }
        $p = new self($src, $strict, $events, $meta);
        return $p->run();
    }

    /** @return PureObject|PureList */
    private function run()
    {
        $i = $this->start;
        $len = $this->len;
        while ($i < $len) {
            $lineStart = $i;
            // Find the § 3.2 terminator: LF, lone CR, or CRLF.
            $j = $i;
            $termLen = 0;
            while ($j < $len) {
                PureWorkMeter::tick(); // per byte scanned
                $b = ord($this->src[$j]);
                if ($b === 0x0A) {
                    $termLen = 1;
                    break;
                }
                if ($b === 0x0D) {
                    $termLen = ($j + 1 < $len && ord($this->src[$j + 1]) === 0x0A) ? 2 : 1;
                    break;
                }
                ++$j;
            }
            $this->processLine($lineStart, $j);
            PureWorkMeter::tick(); // per line processed
            $i = $j + $termLen;
        }
        // § 6.1: an explicit root or any nested compound left open at
        // EOF is UnclosedCompound; an implicit root needs no closer.
        // An implicit root frame's trivia buffer merges into the
        // document stream (an explicit root merges on close).
        $nf = count($this->frames);
        if ($nf > 0) {
            $top = $this->frames[$nf - 1];
            if (!isset($top['root']) || !$top['root'] || $this->rootExplicit) {
                $this->unclosed($top);
            }
            foreach ($this->frames[0]['events'] as $ev) {
                $this->events[] = $ev;
            }
        }
        if ($this->root === null) {
            // § 5.0.1 rule 1: no content lines → empty Object.
            $this->root = new PureObject();
            if ($this->meta !== null) {
                $this->meta = ['rootKind' => 'o', 'explicit' => false, 'inline' => false];
            }
        }
        return $this->root;
    }

    // -----------------------------------------------------------------
    // Line dispatch (§ 5.1)
    // -----------------------------------------------------------------

    /** @return void */
    private function processLine(int $start, int $end)
    {
        $src = $this->src;
        list($ts, $te) = PureScanner::trimBounds($src, $start, $end);
        $n = count($this->frames);

        // Inside an open multi-line string: only the block's own
        // terminator closes it; everything else is content (§ 5.1
        // rule 3 — takes precedence over blank/comment dispatch).
        if ($n > 0 && $this->frames[$n - 1]['t'] === 'm') {
            $this->mlsLine($n - 1, $start, $end, $ts, $te);
            return;
        }

        if ($ts >= $te) {
            if ($this->events !== null) {
                $buf = &$this->curBuf();
                $buf[] = ['blank'];
            }
            return;
        }
        if (substr($src, $ts, 2) === '##') {
            // § 3.4: comment, verbatim (outside multi-line strings).
            if ($this->events !== null) {
                $buf = &$this->curBuf();
                $buf[] = ['comment', substr($src, $start, $end - $start)];
            }
            return;
        }

        if ($this->rootDone) {
            // § 6.14.
            $this->fail('OrphanLineAfterTopLevelInline', $ts, $te);
        }

        if ($this->root === null) {
            $this->detectRoot($ts, $te);
            return;
        }

        $top = $this->frames[$n - 1];
        if ($this->trimmedIs($ts, $te, '}')) {
            $this->closeCompound('o', $ts, $te);
            return;
        }
        if ($this->trimmedIs($ts, $te, ']')) {
            $this->closeCompound('a', $ts, $te);
            return;
        }
        if ($top['t'] === 'o') {
            $this->pairLine($top, $ts, $te);
        } else {
            $this->itemLine($top, $ts, $te);
        }
    }

    /** @return bool */
    private function trimmedIs(int $ts, int $te, string $tok): bool
    {
        return $te - $ts === strlen($tok) && substr($this->src, $ts, $te - $ts) === $tok;
    }

    // -----------------------------------------------------------------
    // Root detection (§ 5.0.1) — two-phase, quote-aware
    // -----------------------------------------------------------------

    /** @return void */
    private function detectRoot(int $ts, int $te)
    {
        $b0 = ord($this->src[$ts]);
        if ($b0 === 0x7B || $b0 === 0x5B) { // '{' or '['
            $kind = $b0 === 0x7B ? 'o' : 'a';
            $scan = PureInlineScan::scan($this->src, $kind, $ts + 1, $te);
            if ($scan['badEscape'] !== null) {
                $this->fail('BadEscapeSequence', $scan['badEscape'], $scan['badEscape'] + 2, ['seq' => $this->rawAt($scan['badEscape'])]);
            }
            if ($scan['closer'] !== null && !$scan['mismatch']) {
                // Rule 2/3: closed inline compound IS the root — but
                // only when the closer ends the line (§ 5.0.1).
                list($js, $je) = PureScanner::trimBounds($this->src, $scan['closer'] + 1, $te);
                if ($js < $je) {
                    $this->fail('MalformedInlineCompound', $js, $je, ['detail' => 'content after the closing delimiter']);
                }
                $value = $this->parseInlineBody($kind, $ts + 1, $scan['closer'], 0, $scan['segs'], $scan['kids']);
                $this->root = $value;
                $this->rootKind = $kind;
                $this->rootDone = true;
                if ($this->meta !== null) {
                    $this->meta = ['rootKind' => $kind, 'explicit' => false, 'inline' => true];
                }
                if ($this->events !== null) {
                    $this->events[] = ['root', $value];
                }
                return;
            }
            if ($scan['mismatch']) {
                $this->fail('MalformedInlineCompound', $ts, $te, ['detail' => 'mismatched closing delimiter']);
            }
            if ($this->trimmedIs($ts, $te, $kind === 'o' ? '{' : '[')) {
                // Rule 4/5: lone opener — the root itself.
                $this->root = $kind === 'o' ? new PureObject() : new PureList();
                $this->rootKind = $kind;
                $this->rootExplicit = true;
                $this->frames[] = ['t' => $kind, 'v' => $this->root, 'root' => true, 'opener' => $ts, 'events' => []];
                if ($this->meta !== null) {
                    $this->meta = ['rootKind' => $kind, 'explicit' => true, 'inline' => false];
                }
                return;
            }
            // Malformed / unterminated inline attempt — never a pair
            // candidate, even with a ':' later in the line (§ 5.0.1
            // note after rules 2–5).
            $this->fail('UnterminatedInlineCompound', $ts, $te);
        }
        // Rule 6: pair candidate — phase 1 is shape-only.
        list($sep, $marker, $unterm) = PureScanner::findSeparator($this->src, $ts, $te);
        $candidate = false;
        if ($sep >= 0 && !$unterm) {
            list($ps, $pe) = PureScanner::trimBounds($this->src, $ts, $sep);
            if ($ps < $pe && ($marker === 2
                || $sep + 1 >= $te || PureScanner::wsLenAt($this->src, $sep + 1, $te, true) > 0)) {
                $candidate = true;
            }
        }
        if ($candidate) {
            $this->root = new PureObject();
            $this->rootKind = 'o';
            $this->frames[] = ['t' => 'o', 'v' => $this->root, 'root' => true, 'opener' => $ts, 'events' => []];
            if ($this->meta !== null) {
                $this->meta = ['rootKind' => 'o', 'explicit' => false, 'inline' => false];
            }
            $this->pairLine($this->frames[0], $ts, $te);
            return;
        }
        if ($this->trimmedIs($ts, $te, '}') || $this->trimmedIs($ts, $te, ']')) {
            // Rule 8: bare closer on the first content line.
            $found = $this->src[$ts];
            $this->fail('UnbalancedBracket', $ts, $te, ['found' => $found]);
        }
        // Rule 7: array root, this line is the first item.
        $this->root = new PureList();
        $this->rootKind = 'a';
        $this->frames[] = ['t' => 'a', 'v' => $this->root, 'root' => true, 'opener' => $ts, 'events' => []];
        if ($this->meta !== null) {
            $this->meta = ['rootKind' => 'a', 'explicit' => false, 'inline' => false];
        }
        $this->itemLine($this->frames[0], $ts, $te);
    }

    // -----------------------------------------------------------------
    // Compound close (§ 5.1 rules 5–6)
    // -----------------------------------------------------------------

    /** @return void */
    private function closeCompound(string $kind, int $ts, int $te)
    {
        $n = count($this->frames);
        $top = $this->frames[$n - 1];
        if ($top['t'] !== $kind) {
            $this->fail('UnbalancedBracket', $ts, $te, ['found' => $kind === 'o' ? '}' : ']']);
        }
        // 'root' is absent on valuePart/mls-pushed frames (§ 5.2 nested
        // compounds) — those are never unclosed-root candidates.
        if ((isset($top['root']) ? $top['root'] : false) && !$this->rootExplicit) {
            // An implicit root has no opening line to match.
            $this->fail('UnbalancedBracket', $ts, $te, ['found' => $kind === 'o' ? '}' : ']']);
        }
        array_pop($this->frames);
        if (isset($top['root']) && $top['root']) {
            // Explicit root closed — any further content is orphaned.
            // Its trivia buffer IS the document stream tail.
            foreach ($top['events'] as $ev) {
                $this->events[] = $ev;
            }
            $this->rootDone = true;
            return;
        }
        // Dedupe at the source: a compound whose body carried trivia
        // ANYWHERE (including inside grandchild compounds) carries its
        // kid stream; without trivia the pretty writer renders the
        // whole block from the value alone. Either way the children's
        // lines are emitted exactly once.
        $kids = self::hasTriviaDeep($top['events']) ? $top['events'] : null;
        $this->insertInto($this->frames[count($this->frames) - 1], $top['dest'], $top['v'], $kids);
    }

    /** Trivia (blank/comment) anywhere in a nested event stream —
     * pair/item events recurse into their kid buffer at $e[4].
     *
     * @param array[] $events
     * @return bool
     */
    private static function hasTriviaDeep(array $events): bool
    {
        foreach ($events as $e) {
            if ($e[0] === 'blank' || $e[0] === 'comment') {
                return true;
            }
            if (isset($e[4]) && is_array($e[4]) && self::hasTriviaDeep($e[4])) {
                return true;
            }
        }
        return false;
    }

    // -----------------------------------------------------------------
    // Pair lines (§ 5.3)
    // -----------------------------------------------------------------

    /** @return void */
    private function pairLine(array $frame, int $ts, int $te)
    {
        $src = $this->src;
        list($sep, $marker, $unterm) = PureScanner::findSeparator($src, $ts, $te);
        if ($sep < 0) {
            if ($unterm) {
                // § 6.16 — more specific than MissingSeparator.
                $this->fail('UnterminatedQuotedKey', $ts, $te);
            }
            $this->fail('MissingSeparator', $ts, $te);
        }
        // § 5.3 check order: UnterminatedQuotedKey → MissingSeparator
        // → EmptyKey → MissingSeparatorSpace → key validation.
        list($ps, $pe) = PureScanner::trimBounds($src, $ts, $sep);
        if ($ps >= $pe) {
            $this->fail('EmptyKey', $ts, $sep);
        }
        $after = $sep + $marker;
        if ($after < $te && PureScanner::wsLenAt($src, $after, $te, true) === 0) {
            $this->fail('MissingSeparatorSpace', $sep, min($te, $sep + $marker + 1));
        }
        $segs = $this->walkKey($frame['v'], $ts, $sep);
        $dest = ['walk' => $segs];
        // Value part: <sep-end> consumes the maximal ws run, advancing
        // by the RETURNED wsLenAt width (multibyte members are 2–3
        // bytes; ++ would split a NBSP into content).
        $vs = $after;
        while ($vs < $te && ($l = PureScanner::wsLenAt($src, $vs, $te, true)) > 0) {
            $vs += $l;
        }
        if ($marker === 2) {
            // Raw marker: literal String, no type inference, NO escape
            // processing in the multi-line pair body (§ 5.3); trailing
            // § 3.3 whitespace before EOL is trimmed from the body.
            list($bs, $be) = PureScanner::trimBounds($src, $vs, $te);
            $value = $bs >= $be ? '' : substr($src, $bs, $be - $bs);
            $this->insertInto($frame, $dest, $value);
            return;
        }
        $this->valuePart($frame, $vs, $te, $dest);
    }

    /**
     * § 5.3.1 key validation + § 5.3.2 descent, interleaved per
     * segment like the Rust core's insert walk: trim (EmptyKey),
     * validate (InvalidKey), decode (BadEscapeSequence), then descend
     * (a non-Object in the way is KeyPathConflict::BlockedByValue).
     * Returns decoded segs (formatter event stream), the target table,
     * leaf segment, raw key text, single-segment flag and key span;
     * the leaf itself is placed by insertLeaf when the value completes
     * (the Rust pending-key-span model).
     *
     * @return array
     */
    private function walkKey(PureObject $table, int $keyStart, int $keyEnd): array
    {
        list($ps, $pe) = PureScanner::trimBounds($this->src, $keyStart, $keyEnd);
        $raw = $this->rawKeyText($ps, $pe);
        $rawSegs = PureScanner::splitKeyPath($this->src, $ps, $pe);
        $n = count($rawSegs);
        $decoded = [];
        $cur = $table;
        foreach ($rawSegs as $i => $rawSeg) {
            // One tick per decoded descent segment: O(segments) is the
            // correct-work bound. The pair lookup below is a PHP hash
            // lookup — trusted and unmeterable — so an uninstrumented
            // rescan of the table would not be caught; the tick bound
            // guards extra per-segment passes (see PerfRegressionSpec
            // HONEST SCOPE).
            PureWorkMeter::tick();
            list($ss, $se) = PureScanner::trimBounds($rawSeg, 0, strlen($rawSeg));
            if ($ss >= $se) {
                $this->fail('EmptyKey', $ps, $pe);
            }
            $trimmed = substr($rawSeg, $ss, $se - $ss);
            $d = PureScanner::decodeKeySegment($trimmed);
            if (isset($d['err'])) {
                if ($d['err'] === 'EmptyKey') {
                    $this->fail('EmptyKey', $ps, $pe);
                }
                if ($d['err'] === 'InvalidKey') {
                    $this->fail('InvalidKey', $ps, $pe, ['key' => $raw]);
                }
                $at = $ps + $ss + $d['at'];
                $this->fail('BadEscapeSequence', $at, $at + strlen($d['seq']), ['seq' => $d['seq']]);
            }
            if ($d['val'] === '') {
                // § 6.5: an empty QUOTED segment ("" / '' / ``) is an EmptyKey.
                $this->fail('EmptyKey', $ps, $pe);
            }
            $decoded[] = $d['val'];
            if ($i + 1 < $n) {
                if (!array_key_exists($d['val'], $cur->pairs)) {
                    $sub = new PureObject();
                    $cur->pairs[$d['val']] = $sub;
                    $cur = $sub;
                    continue;
                }
                $next = $cur->pairs[$d['val']];
                if (!($next instanceof PureObject)) {
                    $this->fail('KeyPathConflict', $ps, $pe, [
                        'pathText' => $raw, 'conflict' => ['blocked'],
                    ]);
                }
                $cur = $next;
            }
        }
        return [
            'segs' => $decoded,
            'table' => $cur,
            'leaf' => $decoded[$n - 1],
            'raw' => $raw,
            'single' => $n === 1,
            'span' => [$ps, $pe],
        ];
    }

    /** Raw key prefix text (envelope payload, undecoded). */
    private function rawKeyText(int $ps, int $pe): string
    {
        return substr($this->src, $ps, $pe - $ps);
    }

    /**
     * Interpret the value region of a pair / array-item line
     * (§ 5.2 / § 5.4). Scalars are inserted immediately; compound and
     * multi-line openers push a frame that inserts on close.
     *
     * @param array $dest insertion destination: walk record, or item
     * @return void
     */
    private function valuePart(array $frame, int $vs, int $te, array $dest)
    {
        $src = $this->src;
        if ($vs >= $te) {
            $this->insertInto($frame, $dest, ''); // empty → empty String
            return;
        }
        $b0 = ord($src[$vs]);
        if ($b0 === 0x7B || $b0 === 0x5B) { // '{' / '['
            $kind = $b0 === 0x7B ? 'o' : 'a';
            if ($this->trimmedIs($vs, $te, $kind === 'o' ? '{' : '[')) {
                // § 5.2 rules 1–2: multi-line compound scope.
                $v = $kind === 'o' ? new PureObject() : new PureList();
                $this->frames[] = ['t' => $kind, 'v' => $v, 'dest' => $dest, 'opener' => $vs, 'events' => []];
                return;
            }
            if ($this->trimmedIs($vs, $te, '{}')) {
                $this->insertInto($frame, $dest, new PureObject());
                return;
            }
            if ($this->trimmedIs($vs, $te, '[]')) {
                $this->insertInto($frame, $dest, new PureList());
                return;
            }
            $this->insertInto($frame, $dest, $this->inlineCompoundValue($kind, $vs, $te));
            return;
        }
        if ($b0 === 0x28) { // '('
            if ($this->trimmedIs($vs, $te, '(')) {
                $this->openMls($dest, false, $vs);
                return;
            }
            if ($this->trimmedIs($vs, $te, '((')) {
                $this->openMls($dest, true, $vs);
                return;
            }
            if ($this->trimmedIs($vs, $te, '()') || $this->trimmedIs($vs, $te, '(())')) {
                $this->insertInto($frame, $dest, '');
                return;
            }
        }
        // Scalar body: trimmed; § 5.2. A multiline pair body processes
        // NO escapes (§ 3.7).
        list($bs, $be) = PureScanner::trimBounds($src, $vs, $te);
        $body = substr($src, $bs, $be - $bs);
        $this->insertInto($frame, $dest, $this->classifyScalar($body, false, $bs, $be));
    }

    /** @return void */
    private function openMls(array $dest, bool $verbatim, int $vs)
    {
        $this->frames[] = [
            't' => 'm',
            'term' => $verbatim ? '))' : ')',
            'buf' => [],
            'dest' => $dest,
            'opener' => $vs,
        ];
    }

    // -----------------------------------------------------------------
    // Array-item lines (§ 5.4)
    // -----------------------------------------------------------------

    /** @return void */
    private function itemLine(array $frame, int $ts, int $te)
    {
        $src = $this->src;
        // Rule 1: raw-marker item commits before anything else.
        if (substr($src, $ts, 2) === '::') {
            $after = $ts + 2;
            if ($after < $te && PureScanner::wsLenAt($src, $after, $te, true) === 0) {
                $this->fail('MissingSeparatorSpace', $ts, $ts + 3);
            }
            while ($after < $te && ($l = PureScanner::wsLenAt($src, $after, $te, true)) > 0) {
                // Width-correct: see pairLine (multibyte ws members).
                $after += $l;
            }
            // Trailing § 3.3 whitespace before EOL is trimmed from the
            // semantic body (§ 5.4 rule 1).
            list($bs, $be) = PureScanner::trimBounds($src, $after, $te);
            $value = $bs >= $be ? '' : substr($src, $bs, $be - $bs);
            $this->insertInto($frame, ['item' => true], $value);
            return;
        }
        $this->valuePart($frame, $ts, $te, ['item' => true]);
    }

    // -----------------------------------------------------------------
    // Multi-line strings (§ 5.6)
    // -----------------------------------------------------------------

    /** @return void */
    private function mlsLine(int $idx, int $start, int $end, int $ts, int $te)
    {
        $f = &$this->frames[$idx];
        $trimmed = substr($this->src, $ts, $te - $ts);
        if ($trimmed === $f['term']) {
            // Close: join content lines with single LF (§ 5.6).
            array_splice($this->frames, $idx, 1);
            $value = $f['term'] === ')' ? self::dedentStripped($f['buf']) : implode("\n", $f['buf']);
            $this->insertInto($this->frames[count($this->frames) - 1], $f['dest'], $value);
            return;
        }
        // Content line: raw (untrimmed) bytes, § 5.6.
        $f['buf'][] = substr($this->src, $start, $end - $start);
    }

    /** § 5.6 stripped form: strip the shared leading-ws prefix and
     * trailing ws per line; blanks contribute "". */
    private static function dedentStripped(array $buf): string
    {
        $common = PureText::commonLeadingWsPrefixLen($buf);
        $out = [];
        foreach ($buf as $line) {
            if ($line === '') {
                $out[] = '';
                continue;
            }
            list($ts, $te) = PureScanner::trimBounds($line, 0, strlen($line));
            if ($ts >= $te) {
                $out[] = ''; // whitespace-only line → empty
                continue;
            }
            $start = min($common, $ts);
            // Trim trailing inline whitespace.
            $end = strlen($line);
            while ($end > $start) {
                $k = $end - 1;
                $found = false;
                while ($k >= $start && $k >= $end - 4) {
                    $l = PureScanner::wsLenAt($line, $k, $end, true);
                    if ($l > 0 && $k + $l === $end) {
                        $end = $k;
                        $found = true;
                        break;
                    }
                    --$k;
                }
                if (!$found) {
                    break;
                }
            }
            $out[] = substr($line, $start, $end - $start);
        }
        return implode("\n", $out);
    }

    // -----------------------------------------------------------------
    // Insertion: dotted merge / duplicates (§ 5.3.2, § 5.5, § 6.2, § 6.3)
    // -----------------------------------------------------------------

    /**
     * Formatter event buffer of the innermost open compound frame
     * (multi-line string frames are transparent); the document stream
     * when none is open. Callers guard on $this->events !== null.
     *
     * @return array reference
     */
    private function &curBuf(): array
    {
        for ($i = count($this->frames) - 1; $i >= 0; --$i) {
            if (isset($this->frames[$i]['events'])) {
                return $this->frames[$i]['events'];
            }
        }
        return $this->events;
    }

    /** @return void */
    private function insertInto(array $parent, array $dest, $value, ?array $kids = null)
    {
        if (isset($dest['item'])) {
            $indent = count($this->frames) - 1;
            if ($this->events !== null) {
                $buf = &$this->curBuf();
                // A valuePart-pushed parent has no 'root' key; its first
                // item is never the implicit root Array's first item.
                $isFirst = (isset($parent['root']) ? $parent['root'] : false)
                    && $this->rootKind === 'a'
                    && $parent['v'] instanceof PureList && $parent['v']->items === [];
                $buf[] = ['item', $value, $indent, $isFirst, $kids];
            }
            $parent['v']->items[] = $value;
            return;
        }
        $walk = $dest['walk'];
        if ($this->events !== null) {
            $buf = &$this->curBuf();
            $buf[] = ['pair', $walk['segs'], $value, count($this->frames) - 1, $kids];
        }
        $this->insertLeaf($walk, $value);
    }

    /**
     * § 5.3.2 expansion with § 6.2 / § 6.3 outcome table, matching the
     * Rust core's insert_leaf: a SINGLE-segment key colliding with a
     * differently shaped value is KeyPathConflict::Overwrite (same
     * shape → DuplicateKey); a DOTTED key's occupied leaf is always a
     * DuplicateKey. Reopen of a dotted-established Object merges.
     *
     * @param array $walk walkKey() record
     * @param mixed $value
     * @return void
     */
    private function insertLeaf(array $walk, $value)
    {
        PureWorkMeter::tick(); // O(1) leaf placement in a correct implementation
        $cur = $walk['table'];
        $seg = $walk['leaf'];
        if (array_key_exists($seg, $cur->pairs)) {
            $existing = $cur->pairs[$seg];
            if ($walk['single']
                && ($existing instanceof PureObject) !== ($value instanceof PureObject)) {
                $this->fail('KeyPathConflict', $walk['span'][0], $walk['span'][1], [
                    'pathText' => $walk['raw'], 'conflict' => ['overwrite', $this->kindLabel($existing), $this->kindLabel($value)],
                ]);
            }
            $this->fail('DuplicateKey', $walk['span'][0], $walk['span'][1], ['key' => $walk['raw']]);
        }
        $cur->pairs[$seg] = $value;
    }
    // -----------------------------------------------------------------
    // Inline compounds (§ 5.8)
    // -----------------------------------------------------------------
    /**
     * Parse a closed inline compound whose body spans
     * [bodyStart, closer). $segs are the depth-0 split points and
     * $kids the nested-compound tree produced by the caller's OWN
     * PureInlineScan over a region that includes the closer — a nested
     * compound is constructed DIRECTLY from its kid entry (never
     * re-scanned; one scan per region, § 5.8).
     *
     * @param array[] $segs
     * @param array $kids segIndex => ['kind','valueStart','closer','segs','kids']
     * @return PureObject|PureList
     */
    private function parseInlineBody(string $kind, int $bodyStart, int $closer, int $depth, array $segs, array $kids = [])
    {
        if ($depth >= PureScanner::MAX_INLINE_DEPTH) {
            $this->fail('MalformedInlineCompound', $bodyStart, $closer, ['detail' => 'nesting depth exceeds limit (128)']);
        }
        $src = $this->src;
        // Trailing comma: a single empty last segment is the § 5.8
        // recognised trailing comma; any other empty segment is a
        // § 6.12 defect.
        if ($segs !== []) {
            list($ls, $le) = PureScanner::trimBounds($src, $segs[count($segs) - 1][0], $segs[count($segs) - 1][1]);
            if ($ls >= $le) {
                array_pop($segs);
            }
        }
        if ($kind === 'o') {
            $obj = new PureObject();
            foreach ($segs as $j => $seg) {
                $this->inlinePair($obj, $seg[0], $seg[1], isset($kids[$j]) ? $kids[$j] : null, $depth);
            }
            return $obj;
        }
        $list = new PureList();
        foreach ($segs as $j => $seg) {
            list($ss, $se) = PureScanner::trimBounds($src, $seg[0], $seg[1]);
            if ($ss >= $se) {
                // § 5.8.3: an empty inline-array item is NOT "".
                $this->fail('MalformedInlineCompound', $seg[0], $seg[1], ['detail' => 'empty inline array item']);
            }
            if (isset($kids[$j])) {
                $kid = $kids[$j];
                $list->items[] = $this->parseInlineBody(
                    $kid['kind'],
                    $kid['valueStart'] + 1,
                    $kid['closer'],
                    $depth + 1,
                    $kid['segs'],
                    $kid['kids']
                );
                // Kid-tree consumption carries the no-rescan contract:
                // bytes between the child's closer and the item end
                // must be whitespace only (§ 6.12 / corpus fixture
                // malformed_trailing_content_nested_array).
                $this->inlineTrailingJunk($kid['closer'] + 1, $se);
                continue;
            }
            $list->items[] = $this->inlineValue($ss, $se, $depth);
        }
        return $list;
    }

    /** @param array|null $kid nested scan-tree entry for this pair's
     *      compound value, when the scanner saw one
     * @return void */
    private function inlinePair(PureObject $obj, int $start, int $end, ?array $kid, int $depth)
    {
        $src = $this->src;
        list($ss, $se) = PureScanner::trimBounds($src, $start, $end);
        if ($ss >= $se) {
            $this->fail('MalformedInlineCompound', $start, $end, ['detail' => 'empty inline pair']);
        }
        list($sep, $marker, $unterm) = PureScanner::findSeparator($src, $ss, $se);
        if ($sep < 0 || $unterm) {
            $this->fail('MalformedInlineCompound', $ss, $se, ['detail' => 'missing pair separator inside inline object']);
        }
        $segs = $this->walkKey($obj, $ss, $sep);
        $vs = $sep + $marker;
        while ($vs < $se && ($l = PureScanner::wsLenAt($src, $vs, $se, true)) > 0) {
            // Width-correct: see pairLine (multibyte ws members).
            $vs += $l;
        }
        if ($marker === 2) {
            // § 5.8.2 raw marker: escapes ARE processed here, leading
            // '{' / '[' is literal data (§ 4 <inline-raw-scalar>).
            list($bs, $be) = PureScanner::trimBounds($src, $vs, $se);
            $value = $this->decodeInlineRaw($bs, $be);
            $this->insertLeaf($segs, $value);
            return;
        }
        if ($vs >= $se) {
            $value = ''; // § 5.8.2 explicitly empty pair value
        } elseif ($kid !== null) {
            // Nested compound straight from the scan tree — no rescan.
            $value = $this->parseInlineBody(
                $kid['kind'],
                $kid['valueStart'] + 1,
                $kid['closer'],
                $depth + 1,
                $kid['segs'],
                $kid['kids']
            );
            // Same no-rescan contract as the array branch: only ws may
            // follow the child's closer within this pair's segment.
            $this->inlineTrailingJunk($kid['closer'] + 1, $se);
        } else {
            $value = $this->inlineValue($vs, $se, $depth);
        }
        $this->insertLeaf($segs, $value);
    }

    /**
     * After a nested inline compound's closer, only § 3.3 whitespace
     * may remain within the consuming segment — anything else is
     * trailing content (§ 6.12; corpus fixture
     * invalid/inline/malformed_trailing_content_nested_array). The
     * scanner does not check this (kid-tree contract), so both kid
     * consumption sites call it.
     *
     * @return void
     */
    private function inlineTrailingJunk(int $after, int $se)
    {
        list($ts, $te) = PureScanner::trimBounds($this->src, $after, $se);
        if ($ts < $te) {
            $this->fail('MalformedInlineCompound', $after, $se, ['detail' => 'content after the closing delimiter']);
        }
    }

    /**
     * One inline SCALAR value in [$start, $end) — the scanner already
     * dispatched nested compounds into the kids tree, so a bracket
     * leading byte here without a kid entry is a scan-tree contract
     * violation (§ 5.8.5 first-byte dispatch).
     *
     * @return mixed
     */
    private function inlineValue(int $start, int $end, int $depth)
    {
        $src = $this->src;
        $i = $start;
        while ($i < $end && ($l = PureScanner::wsLenAt($src, $i, $end, true)) > 0) {
            // Width-correct: see pairLine (multibyte ws members).
            $i += $l;
        }
        if ($i >= $end) {
            return ''; // empty object-pair value (§ 5.8.2)
        }
        $b = ord($src[$i]);
        if ($b === 0x7B || $b === 0x5B) {
            $this->fail('MalformedInlineCompound', $i, $end, ['detail' => 'nested inline compound missing from scan tree']);
        }
        // Inline scalar: to the region end (the scanner already
        // stopped at the unescaped , } ]), trimmed, escapes processed.
        list($bs, $be) = PureScanner::trimBounds($src, $i, $end);
        $raw = substr($src, $bs, $be - $bs);
        // § 5.6 / § 5.6.1 fully-empty and verbatim-empty multiline
        // String forms inside an inline compound: the body `()` is the
        // stripped multiline String with zero content lines and `(())`
        // its verbatim counterpart — both are the EMPTY String, not a
        // two/four-byte literal (corpus triple tests/valid/inline/
        // paren_empty_string_matches_raw_body.*: `a: ()` / `b: (())` /
        // `c: [()]` → "" / "" / [""], while the ESCAPED body
        // `\u0028\u0029` stays the literal "()" and takes the raw
        // marker on emission per § 5.9.6). The line-level dispatcher
        // (valuePart) already handles these two bodies before
        // classification; this is the inline counterpart.
        if ($raw === '()' || $raw === '(())') {
            return '';
        }
        $dec = $this->decodeInlineScalar($raw, $bs);
        return $this->classifyScalar($dec[0], $dec[1], $bs, $be);
    }

    /** Decode escapes in an inline scalar body; returns [text, hadEscape]. */
    private function decodeInlineScalar(string $raw, int $offset): array
    {
        $n = strlen($raw);
        $out = '';
        $had = false;
        $i = 0;
        while ($i < $n) {
            $c = ord($raw[$i]);
            if ($c === 0x5C) {
                $d = PureScanner::decodeEscape($raw, $i, $n);
                if ($d === null) {
                    $this->fail('BadEscapeSequence', $offset + $i, $offset + $i + 2, ['seq' => substr($raw, $i, min(2, $n - $i))]);
                }
                $out .= $d[0];
                $i = $d[1];
                $had = true;
                continue;
            }
            $out .= $raw[$i];
            ++$i;
        }
        return [$out, $had];
    }

    /** Raw-marker inline body: same escape processing, no dispatch. */
    private function decodeInlineRaw(int $start, int $end): string
    {
        $raw = $start >= $end ? '' : substr($this->src, $start, $end - $start);
        return $this->decodeInlineScalar($raw, $start)[0];
    }

    /**
     * A `{`/`[`-leading value region on a pair/item line: scan, junk
     * check, parse (§ 5.2 rules 6–9).
     *
     * @return PureObject|PureList
     */
    private function inlineCompoundValue(string $kind, int $vs, int $te)
    {
        $scan = PureInlineScan::scan($this->src, $kind, $vs + 1, $te);
        if ($scan['badEscape'] !== null) {
            // BadEscapeSequence precedes the missing-closer diagnosis.
            $this->fail('BadEscapeSequence', $scan['badEscape'], $scan['badEscape'] + 2, ['seq' => $this->rawAt($scan['badEscape'])]);
        }
        if ($scan['mismatch']) {
            $this->fail('MalformedInlineCompound', $vs, $te, ['detail' => 'mismatched closing delimiter']);
        }
        if ($scan['closer'] === null) {
            $this->fail('UnterminatedInlineCompound', $vs, $te);
        }
        list($js, $je) = PureScanner::trimBounds($this->src, $scan['closer'] + 1, $te);
        if ($js < $je) {
            // § 6.12: non-ws content after the same-line closer.
            $this->fail('MalformedInlineCompound', $js, $je, ['detail' => 'content after the closing delimiter']);
        }
        return $this->parseInlineBody($kind, $vs + 1, $scan['closer'], 0, $scan['segs'], $scan['kids']);
    }

    /**
     * Classify a trimmed scalar body. $hadEscape forces String (§ 5.2
     * provenance rule). Runs the strict-lossy check when $this->strict.
     *
     * @return mixed
     */
    private function classifyScalar(string $body, bool $hadEscape, int $bs, int $be)
    {
        if ($hadEscape) {
            return $body; // § 5.2: any recognised escape forces String
        }
        if ($body === 'null') {
            return null;
        }
        if ($body === 'true') {
            return true;
        }
        if ($body === 'false') {
            return false;
        }
        $int = PureScanner::matchInteger($body);
        if ($int !== null) {
            $iv = $int[0];
            if ($iv instanceof PureBigInt) {
                // In-i64 magnitude on a host too narrow for the cast:
                // Integer identity via the digit-string carrier. A
                // bigint's canonical form IS its digits — never lossy.
                $this->checkLossy($body, $iv, $bs, $be);
                return $iv;
            }
            if ($iv !== null) {
                $this->checkLossy($body, (int) $iv, $bs, $be);
                return (int) $iv;
            }
            return $body; // i64 overflow → String (§ 5.2 rule 13)
        }
        $float = PureScanner::matchFloat($body);
        if ($float !== null) {
            $this->checkLossy($body, $float, $bs, $be);
            return $float;
        }
        return $body;
    }

}
