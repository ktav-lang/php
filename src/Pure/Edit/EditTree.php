<?php

declare(strict_types=1);

namespace Ktav\Pure\Edit;

use Ktav\KtavException;
use Ktav\Pure\PureInlineScan;
use Ktav\Pure\PureScanner;

/**
 * Positional source tree for the pure source-edit engine — a PHP port
 * of the Rust core's `document/parser/edit/tree.rs`. One linear pass
 * over the lines of an ALREADY-VALIDATED source (the caller parses the
 * document first), recording the byte slot of every value with the
 * same dispatch decisions as the real parser:
 *
 *   slot pair  : ['r'=>'pair',  'colon'=>abs, 'start'=>bodyStart, 'end'=>bodyEnd]
 *   slot item  : ['r'=>'item',  'start'=>marker-zone start, 'body0'=>bodyStart, 'end'=>bodyEnd]
 *   slot block : ['r'=>'block', 'anchor'=>colonAbs|null, 'start', 'end']
 *
 * A node whose value was assembled from dotted-key / reopen lines is
 * marked NONCONTIGUOUS by clearing its slot (synthetic intermediate
 * objects never get one). Inline nodes carry inline=true ON THE LEAF —
 * the physical inline context lives at the leaf, so synthetic dotted
 * ancestors and reopen descendants neither inherit nor corrupt it.
 *
 * Object pairs are kept as an ordered list of [key, node] entries (PHP
 * casts numeric-string array keys to int, which would corrupt keys).
 */
final class EditTree
{
    /** @var string */
    private $src;

    /** @var int */
    private $len;

    /** @var array[] frame stack */
    private $stack = [];

    /** @var array|null ['mode'=>'s'|'v', 'opener'=>int] multiline collector */
    private $collecting;

    /** @var int opener byte of the currently open multiline string */
    private $collectingOpener = 0;

    /** @var bool */
    private $rootInitialized = false;

    /** @var bool */
    private $rootConsumed = false;

    /** @var array|null */
    private $rootInline;

    /** @var bool */
    private $rootExplicit = false;

    private function __construct(string $src)
    {
        $this->src = $src;
        $this->len = strlen($src);
    }

    // -----------------------------------------------------------------
    // Node / table helpers
    // -----------------------------------------------------------------

    /** @return array */
    private static function scalarNode(): array
    {
        return ['kind' => 'scalar', 'inline' => false, 'slot' => null];
    }

    /** @return array */
    private static function compoundNode(string $kind): array
    {
        return [
            'kind' => $kind,
            'inline' => false,
            'slot' => null,
            'pairs' => $kind === 'object' ? [] : null,
            'items' => $kind === 'array' ? [] : null,
        ];
    }

    /**
     * Build the positional tree of a valid document. Returns
     * [rootNode, rootIsExplicit]. Any inconsistency (unreachable after
     * a successful parse) surfaces as an EditUnsupported envelope at
     * the splice layer.
     *
     * @return array{array, bool}
     */
    public static function build(string $src): array
    {
        $b = new self($src);
        try {
            return $b->run();
        } catch (KtavException $e) {
            // Re-throw as unsupported: the positional pass must never
            // produce a parser-looking diagnostic of its own.
            throw EditEnvelope::unsupported('source text does not parse: ' . $e->getMessage());
        }
    }

    /** @return array{array, bool} */
    private function run(): array
    {
        $src = $this->src;
        $start = substr($src, 0, 3) === "\xEF\xBB\xBF" ? 3 : 0; // § 3.1: one BOM
        $i = $start;
        $lineNum = 0;
        while ($i <= $this->len) {
            // Find the line terminator: LF, lone CR or CRLF (one EOL).
            $j = $i;
            $termLen = 0;
            while ($j < $this->len) {
                $byte = ord($src[$j]);
                if ($byte === 0x0A) {
                    $termLen = 1;
                    break;
                }
                if ($byte === 0x0D) {
                    $termLen = ($j + 1 < $this->len && ord($src[$j + 1]) === 0x0A) ? 2 : 1;
                    break;
                }
                ++$j;
            }
            ++$lineNum;
            $this->handleLine($i, $j);
            if ($j >= $this->len) {
                break;
            }
            $i = $j + $termLen;
        }
        if ($this->collecting !== null || count($this->stack) > 1
            || ($this->rootExplicit && !$this->rootConsumed)) {
            // Unclosed compound: unreachable after a successful parse.
            throw new \RuntimeException('unclosed compound');
        }
        if ($this->rootInline !== null) {
            return [$this->rootInline, $this->rootExplicit];
        }
        $frame = array_pop($this->stack);
        if ($frame === null) {
            return [self::compoundNode('object'), false]; // empty document
        }
        // The ROOT node is the frame's VALUE, not the frame itself: a
        // frame carries kind/rangeStart/pendingKey bookkeeping, and
        // returning it handed descend a node-shaped array WITHOUT the
        // pairs/items fields (the "Undefined array key pairs" class).
        $node = $frame['node'];
        $node['slot'] = null;
        return [$node, $this->rootExplicit];
    }

    // -----------------------------------------------------------------
    // Line dispatch
    // -----------------------------------------------------------------

    /** @return void */
    private function handleLine(int $start, int $end)
    {
        $src = $this->src;
        if ($this->collecting !== null) {
            list($ts, $te) = PureScanner::trimBounds($src, $start, $end);
            $term = $this->collecting['mode'] === 'v' ? '))' : ')';
            if (substr($src, $ts, $te - $ts) === $term) {
                $opener = $this->collectingOpener;
                $this->collecting = null;
                $node = self::scalarNode();
                $node['slot'] = ['r' => 'block', 'anchor' => null, 'start' => $opener, 'end' => $te];
                $this->attachScalar($node);
            }
            return;
        }

        list($ts, $te) = PureScanner::trimBounds($src, $start, $end);
        if ($ts >= $te) {
            return; // blank
        }
        if (substr($src, $ts, 2) === '##') {
            return; // comment — never part of the value layout
        }
        if ($this->rootConsumed) {
            throw new \RuntimeException('orphan line after root inline');
        }

        if (!$this->rootInitialized) {
            $this->rootInitialized = true;
            if ($this->detectRoot($ts, $te)) {
                return;
            }
        }

        if ($this->trimmedIs($ts, $te, '}')) {
            $this->closeFrame('object', $ts, $te);
            return;
        }
        if ($this->trimmedIs($ts, $te, ']')) {
            $this->closeFrame('array', $ts, $te);
            return;
        }

        $top = $this->stack[count($this->stack) - 1];
        if ($top['kind'] === 'array') {
            $this->handleArrayItem($ts, $te);
        } else {
            $this->handleObjectPair($ts, $te);
        }
    }

    /** @return bool true when the root line was consumed here */
    private function detectRoot(int $ts, int $te): bool
    {
        $b0 = ord($this->src[$ts]);
        if ($b0 !== 0x7B && $b0 !== 0x5B) {
            // Implicit root: § 5.0.1 — a pair candidate opens an Object,
            // otherwise the first item line opens an Array.
            list($sep, , $unterm) = PureScanner::findSeparator($this->src, $ts, $te);
            $kind = ($sep >= 0 && !$unterm) ? 'object' : 'array';
            $this->stack[] = $this->newFrame($kind, 0);
            return false;
        }
        $kind = $b0 === 0x7B ? 'object' : 'array';
        $closer = $kind === 'object' ? '}' : ']';
        if ($this->trimmedIs($ts, $te, $kind === 'object' ? '{' : '[')) {
            // Explicit root: lone opener.
            $this->rootExplicit = true;
            $this->stack[] = $this->newFrame($kind, $ts);
            return true;
        }
        if ($this->trimmedIs($ts, $te, $kind === 'object' ? '{}' : '[]')) {
            // Empty inline root.
            $this->rootConsumed = true;
            $this->rootInline = self::compoundNode($kind);
            return true;
        }
        if ($this->src[$te - 1] === $closer) {
            // Closed inline root — position it and consume the document.
            $this->rootConsumed = true;
            $this->rootInline = $this->positionInline($ts, $te, $kind);
            return true;
        }
        // Unterminated inline root attempt: unreachable post-parse.
        throw new \RuntimeException('unterminated inline root');
    }

    /** @return array */
    private function newFrame(string $kind, int $rangeStart): array
    {
        return [
            'kind' => $kind,
            'node' => self::compoundNode($kind),
            'rangeStart' => $rangeStart,
            'pendingKey' => null,      // [rawKeyText, keySpanStart, keySpanEnd, anchorColon|null]
        ];
    }

    // -----------------------------------------------------------------
    // Object pairs (block context)
    // -----------------------------------------------------------------

    /** @return void */
    private function handleObjectPair(int $ts, int $te)
    {
        $src = $this->src;
        list($sep, $marker, $unterm) = PureScanner::findSeparator($src, $ts, $te);
        if ($sep < 0 || $unterm) {
            throw new \RuntimeException('pair line without separator');
        }
        // Raw key text: [ts, sep) trimmed.
        list($ks, $ke) = PureScanner::trimBounds($src, $ts, $sep);
        $rawKey = substr($src, $ks, $ke - $ks);
        $colon = $sep;
        $after = $sep + $marker;
        $vs = $after;
        while ($vs < $te && ($l = PureScanner::wsLenAt($src, $vs, $te, true)) > 0) {
            $vs += $l;
        }
        $this->dispatchValue($rawKey, $ks, $ke, $colon, $marker === 2, $vs, $te, true);
    }

    /** @return void */
    private function handleArrayItem(int $ts, int $te)
    {
        $src = $this->src;
        if (substr($src, $ts, 2) === '::') {
            $vs = $ts + 2;
            while ($vs < $te && ($l = PureScanner::wsLenAt($src, $vs, $te, true)) > 0) {
                $vs += $l;
            }
            $node = self::scalarNode();
            $node['slot'] = ['r' => 'item', 'start' => $ts, 'body0' => $vs, 'end' => $te];
            $this->pushItem($node);
            return;
        }
        $this->dispatchValue(null, $ts, $te, $ts, false, $ts, $te, false);
    }

    /**
     * Shared value-shape dispatch for pair values and array items.
     *
     * @return void
     */
    private function dispatchValue(?string $rawKey, int $ks, int $ke, int $colon, bool $raw, int $vs, int $te, bool $isPair)
    {
        $src = $this->src;
        if ($vs >= $te) {
            // Empty body: empty String scalar (§ 5.2).
            $this->attachValue($rawKey, $ks, $ke, $colon, self::scalarNode(), $vs, $te, 'pair', $isPair);
            return;
        }
        $b0 = ord($src[$vs]);
        $closer = $b0 === 0x7B ? '}' : ($b0 === 0x5B ? ']' : '');
        $openKind = $b0 === 0x7B ? 'object' : ($b0 === 0x5B ? 'array' : '');
        $bare = $vs + 1 === $te;

        if ($openKind !== '') {
            if ($bare) {
                // Open multiline compound `{` / `[`.
                $frame = $this->newFrame($openKind, $vs);
                if ($isPair) {
                    $this->setPending($rawKey, $ks, $ke, $colon);
                }
                $this->stack[] = $frame;
                return;
            }
            if ($src[$te - 1] === $closer && $te - $vs >= 2) {
                if ($te - $vs === 2) {
                    // Empty inline compound `{}` / `[]`.
                    $node = self::compoundNode($openKind);
                    if ($openKind === 'object') {
                        $node['inline'] = false;
                    }
                    $this->attachValue($rawKey, $ks, $ke, $colon, $node, $vs, $te, 'pair', $isPair);
                    return;
                }
                // Closed inline compound — position the whole subtree.
                $node = $this->positionInline($vs, $te, $openKind);
                $node['inline'] = false; // container lies on the LINE, kids stay inline
                $this->attachValue($rawKey, $ks, $ke, $colon, $node, $vs, $te, 'pair', $isPair);
                return;
            }
        }
        if ($b0 === 0x28) { // '('
            $body = substr($src, $vs, $te - $vs);
            list($bts, $bte) = PureScanner::trimBounds($src, $vs, $te);
            $trimmed = substr($src, $bts, $bte - $bts);
            if ($trimmed === '(' || $trimmed === '((') {
                // Open multiline string (stripped / verbatim). A pair
                // keeps its pending key; an array item is frame-less
                // (attachScalar appends to the array frame directly).
                if ($isPair) {
                    $this->setPending($rawKey, $ks, $ke, $colon);
                }
                $this->collecting = ['mode' => $trimmed === '(' ? 's' : 'v'];
                $this->collectingOpener = $bts;
                return;
            }
            if ($trimmed === '()' || $trimmed === '(())') {
                // § 5.6 / § 5.6.1 empty multiline forms — empty String.
                $this->attachValue($rawKey, $ks, $ke, $colon, self::scalarNode(), $vs, $te, 'pair', $isPair);
                return;
            }
        }
        // Plain scalar / raw scalar body.
        $node = self::scalarNode();
        $this->attachValue($rawKey, $ks, $ke, $colon, $node, $vs, $te, $raw ? 'pair' : 'pair', $isPair);
    }

    /**
     * Attach a completed value node to the current frame (pair key or
     * array item). $slotRole 'pair' gives the node a Pair slot;
     * array items get an Item slot.
     *
     * @return void
     */
    private function attachValue(?string $rawKey, int $ks, int $ke, int $colon, array $node, int $vs, int $te, string $slotRole, bool $isPair)
    {
        if ($isPair) {
            $node['slot'] = ['r' => 'pair', 'colon' => $colon, 'start' => $vs, 'end' => $te];
            $this->insertPair($rawKey, $ks, $ke, $node);
            return;
        }
        $node['slot'] = ['r' => 'item', 'start' => $ks, 'body0' => $vs, 'end' => $te];
        $this->pushItem($node);
    }

    /** @return void */
    private function attachScalar(array $node)
    {
        $n = count($this->stack);
        if ($n === 0) {
            throw new \RuntimeException('multiline closed without a frame');
        }
        $frame = &$this->stack[$n - 1];
        if ($frame['kind'] === 'object') {
            $pending = $frame['pendingKey'];
            if ($pending === null) {
                throw new \RuntimeException('multiline closed without pending key');
            }
            $frame['pendingKey'] = null;
            $node['slot']['anchor'] = $pending[3];
            $this->insertPair($pending[0], $pending[1], $pending[2], $node);
            return;
        }
        $frame['node']['items'][] = $node;
    }

    // -----------------------------------------------------------------
    // Insertion: dotted keys, reopen, noncontiguity
    // -----------------------------------------------------------------

    /** @return void */
    private function setPending(?string $rawKey, int $ks, int $ke, int $colon)
    {
        $n = count($this->stack);
        if ($n === 0 || $this->stack[$n - 1]['kind'] !== 'object') {
            throw new \RuntimeException('pending key outside object frame');
        }
        $this->stack[$n - 1]['pendingKey'] = [$rawKey, $ks, $ke, $colon];
    }

    /**
     * Insert a pair value under the raw (undecoded) dotted key:
     * decode segments per § 5.3.2 / § 4, descend creating synthetic
     * object nodes (slot=null), mark REOPENED positioned objects
     * noncontiguous (slot=null), then place the leaf.
     *
     * @return void
     */
    private function insertPair(?string $rawKey, int $ks, int $ke, array $leaf)
    {
        $n = count($this->stack);
        if ($n === 0) {
            throw new \RuntimeException('pair outside object frame');
        }
        $raw = $rawKey !== null ? $rawKey : substr($this->src, $ks, $ke - $ks);
        $segs = PureScanner::splitKeyPath($this->src, $ks, $ke);
        $total = count($segs);
        $table = &$this->stack[$n - 1]['node'];
        foreach ($segs as $i => $rawSeg) {
            list($ss, $se) = PureScanner::trimBounds($rawSeg, 0, strlen($rawSeg));
            $trimmed = $ss >= $se ? '' : substr($rawSeg, $ss, $se - $ss);
            $d = PureScanner::decodeKeySegment($trimmed);
            $key = isset($d['val']) && is_string($d['val']) ? $d['val'] : $trimmed;
            $isLeaf = $i + 1 === $total;
            $idx = self::findEntry($table, $key);
            if ($isLeaf) {
                $child = $leaf;
                if ($idx !== null) {
                    // Post-parse unreachable (duplicate / conflict).
                    $table['pairs'][$idx][1] = $child;
                } else {
                    $table['pairs'][] = [$key, $child];
                }
                return;
            }
            if ($idx !== null) {
                $next = $table['pairs'][$idx][1];
                if ($next['kind'] !== 'object') {
                    throw new \RuntimeException('blocked path');
                }
                // Reopen: the intermediate object is noncontiguous.
                // IN PLACE on the stored node — PHP arrays are value
                // types, so nulling a COPY ($next['slot']) would leave
                // the tree's slot valid and route a noncontiguous whole
                // target into the splice + semantic guard instead.
                $table['pairs'][$idx][1]['slot'] = null;
                $table = &$table['pairs'][$idx][1];
                continue;
            }
            $synthetic = self::compoundNode('object');
            $synthetic['slot'] = null;
            $table['pairs'][] = [$key, $synthetic];
            $table = &$table['pairs'][count($table['pairs']) - 1][1];
        }
    }

    /** @return int|null index of the entry with $key */
    private static function findEntry(array $table, string $key): ?int
    {
        foreach ($table['pairs'] as $i => $pair) {
            if ($pair[0] === $key) {
                return $i;
            }
        }
        return null;
    }

    /** @return void */
    private function pushItem(array $node)
    {
        $n = count($this->stack);
        if ($n === 0 || $this->stack[$n - 1]['kind'] !== 'array') {
            throw new \RuntimeException('item outside array frame');
        }
        $this->stack[$n - 1]['node']['items'][] = $node;
    }

    /** @return void */
    private function closeFrame(string $expected, int $ts, int $te)
    {
        $n = count($this->stack);
        if ($n === 1 && $this->rootExplicit) {
            $this->rootConsumed = true;
            return;
        }
        if ($n <= 1) {
            throw new \RuntimeException('unbalanced bracket');
        }
        $frame = array_pop($this->stack);
        if ($frame['kind'] !== $expected) {
            throw new \RuntimeException('mismatched closer');
        }
        $node = $frame['node'];
        // anchor: the colon of the pair that opened this block.
        $anchor = null;
        $top = $this->stack[count($this->stack) - 1];
        if ($top['kind'] === 'object' && $top['pendingKey'] !== null) {
            $anchor = $top['pendingKey'][3];
        }
        $node['slot'] = ['r' => 'block', 'anchor' => $anchor, 'start' => $frame['rangeStart'], 'end' => $te];
        if ($top['kind'] === 'object') {
            if ($top['pendingKey'] === null) {
                throw new \RuntimeException('closed compound without pending key');
            }
            $pending = $top['pendingKey'];
            $this->stack[count($this->stack) - 1]['pendingKey'] = null;
            $this->insertPair($pending[0], $pending[1], $pending[2], $node);
            return;
        }
        $this->stack[count($this->stack) - 1]['node']['items'][] = $node;
    }

    // -----------------------------------------------------------------
    // Inline positioning (one PureInlineScan per region)
    // -----------------------------------------------------------------

    /**
     * Position a closed inline compound spanning [vs, te): scan once,
     * build the node tree from the scan segments (the scanner's kid
     * map feeds nested compounds — no region is scanned twice).
     *
     * @return array node with inline=true, slot=null
     */
    private function positionInline(int $vs, int $te, string $kind): array
    {
        $scan = PureInlineScan::scan($this->src, $kind === 'object' ? 'o' : 'a', $vs + 1, $te);
        if ($scan['badEscape'] !== null || $scan['closer'] === null) {
            throw new \RuntimeException('inline positioning failed');
        }
        return $this->buildInline($kind, $scan['segs'], $scan['kids']);
    }

    /**
     * Build an inline compound node from scan segments (absolute
     * offsets) and the kid map segIndex => ['kind','valueStart',
     * 'closer','segs','kids'].
     *
     * @param array[] $segs
     * @param array $kids
     * @return array
     */
    private function buildInline(string $kind, array $segs, array $kids): array
    {
        $node = self::compoundNode($kind);
        $node['inline'] = true;
        if ($kind === 'object') {
            foreach ($segs as $j => $seg) {
                list($ss, $se) = PureScanner::trimBounds($this->src, $seg[0], $seg[1]);
                if ($ss >= $se) {
                    continue; // trailing comma
                }
                list($sep, $marker, $unterm) = PureScanner::findSeparator($this->src, $ss, $se);
                if ($sep < 0 || $unterm) {
                    throw new \RuntimeException('inline pair without separator');
                }
                list($ks, $ke) = PureScanner::trimBounds($this->src, $ss, $sep);
                $rawKey = substr($this->src, $ks, $ke - $ks);
                $vs2 = $sep + $marker;
                while ($vs2 < $se && ($l = PureScanner::wsLenAt($this->src, $vs2, $se, true)) > 0) {
                    $vs2 += $l;
                }
                if ($marker === 2) {
                    // § 5.8.2 raw inline scalar; leaf is PHYSICALLY inline.
                    $child = self::scalarNode();
                    $child['inline'] = true;
                    $child['slot'] = ['r' => 'pair', 'colon' => $sep, 'start' => $vs2, 'end' => $se];
                    $this->insertInlinePair($node, $rawKey, $child);
                    continue;
                }
                if ($vs2 >= $se) {
                    $child = self::scalarNode();
                    $child['inline'] = true;
                    $child['slot'] = ['r' => 'pair', 'colon' => $sep, 'start' => $vs2, 'end' => $se];
                    $this->insertInlinePair($node, $rawKey, $child);
                    continue;
                }
                $b = ord($this->src[$vs2]);
                if ($b === 0x7B || $b === 0x5B) {
                    $kid = $kids[$j] ?? null;
                    if ($kid === null) {
                        throw new \RuntimeException('nested inline compound missing from scan tree');
                    }
                    $child = $this->buildInline(
                        $b === 0x7B ? 'object' : 'array',
                        $kid['segs'],
                        $kid['kids']
                    );
                } else {
                    $child = self::scalarNode();
                    $child['inline'] = true;
                }
                $child['slot'] = ['r' => 'pair', 'colon' => $sep, 'start' => $vs2, 'end' => $se];
                $this->insertInlinePair($node, $rawKey, $child);
            }
            return $node;
        }
        // Inline array: `::` is literal DATA here — the item body is
        // the whole trimmed segment.
        foreach ($segs as $j => $seg) {
            list($ss, $se) = PureScanner::trimBounds($this->src, $seg[0], $seg[1]);
            if ($ss >= $se) {
                continue; // trailing comma
            }
            $vs2 = $ss;
            $b = ord($this->src[$vs2]);
            if ($b === 0x7B || $b === 0x5B) {
                $kid = $kids[$j] ?? null;
                if ($kid === null) {
                    throw new \RuntimeException('nested inline compound missing from scan tree');
                }
                $child = $this->buildInline(
                    $b === 0x7B ? 'object' : 'array',
                    $kid['segs'],
                    $kid['kids']
                );
            } else {
                $child = self::scalarNode();
                $child['inline'] = true;
            }
            $child['slot'] = ['r' => 'item', 'start' => $ss, 'body0' => $vs2, 'end' => $se];
            $node['items'][] = $child;
        }
        return $node;
    }

    /**
     * Insert under an already-decoded inline key (no dotted expansion
     * inside inline compounds — § 5.8 keys are single segments;
     * post-parse duplicates are unreachable).
     *
     * @return void
     */
    private function insertInlinePair(array &$table, string $rawKey, array $child)
    {
        $d = PureScanner::decodeKeySegment($rawKey);
        $key = isset($d['val']) && is_string($d['val']) ? $d['val'] : $rawKey;
        $idx = self::findEntry($table, $key);
        if ($idx !== null) {
            // Reopen / duplicate: post-parse unreachable; mark the slot
            // chain noncontiguous like the Rust descend would.
            $table['pairs'][$idx][1]['slot'] = null;
            if ($table['pairs'][$idx][1]['kind'] === 'object' && $child['kind'] === 'object') {
                $sub = &$table['pairs'][$idx][1]; // in place: arrays copy
                foreach ($child['pairs'] as $pair) {
                    $existing = self::findEntry($sub, $pair[0]);
                    if ($existing !== null) {
                        $sub['pairs'][$existing][1] = $pair[1];
                    } else {
                        $sub['pairs'][] = $pair;
                    }
                }
                unset($sub);
                return;
            }
            $table['pairs'][$idx][1] = $child;
            return;
        }
        $table['pairs'][] = [$key, $child];
    }

    /** @return bool */
    private function trimmedIs(int $ts, int $te, string $tok): bool
    {
        return $te - $ts === strlen($tok) && substr($this->src, $ts, $te - $ts) === $tok;
    }

    // -----------------------------------------------------------------
    // Shared offset helpers (used by the splice engine too)
    // -----------------------------------------------------------------

    /** Leading ' '/'\t' run of the line containing byte $pos. */
    public static function lineIndent(string $text, int $pos): string
    {
        $start = 0;
        $i = $pos - 1;
        while ($i >= 0) {
            $b = ord($text[$i]);
            if ($b === 0x0A || $b === 0x0D) {
                $start = $i + 1;
                break;
            }
            --$i;
        }
        $end = $start;
        $n = strlen($text);
        while ($end < $n && (ord($text[$end]) === 0x20 || ord($text[$end]) === 0x09)) {
            ++$end;
        }
        return substr($text, $start, $end - $start);
    }

    /** EOL style of the first terminator at/after $pos (fallback look
     * behind; no terminators at all — LF). */
    public static function detectEol(string $text, int $pos): string
    {
        $n = strlen($text);
        $i = $pos;
        while ($i < $n) {
            $b = ord($text[$i]);
            if ($b === 0x0A) {
                return "\n";
            }
            if ($b === 0x0D) {
                return ($i + 1 < $n && ord($text[$i + 1]) === 0x0A) ? "\r\n" : "\r";
            }
            ++$i;
        }
        $j = min($pos, $n);
        while ($j > 0) {
            $b = ord($text[$j - 1]);
            if ($b === 0x0A) {
                return ($j >= 2 && ord($text[$j - 2]) === 0x0D) ? "\r\n" : "\n";
            }
            if ($b === 0x0D) {
                return "\r";
            }
            --$j;
        }
        return "\n";
    }

    /** Full physical line containing byte $pos. */
    public static function physicalLine(string $text, int $pos): string
    {
        $n = strlen($text);
        $start = $pos;
        while ($start > 0) {
            $b = ord($text[$start - 1]);
            if ($b === 0x0A || $b === 0x0D) {
                break;
            }
            --$start;
        }
        $end = $pos;
        while ($end < $n) {
            $b = ord($text[$end]);
            if ($b === 0x0A || $b === 0x0D) {
                break;
            }
            ++$end;
        }
        return substr($text, $start, $end - $start);
    }
}
