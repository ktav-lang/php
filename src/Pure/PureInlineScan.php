<?php

declare(strict_types=1);

namespace Ktav\Pure;

/**
 * Quote-aware, escape-aware inline-compound scanner (§ 5.8), split out
 * of PureParser to keep each file small. Static and side-effect free:
 * it never fails, it returns diagnostics for the caller to turn into
 * error envelopes.
 */
final class PureInlineScan
{
    /**
     * Scan over [$start, $end) — a region that MUST include this
     * compound's own closing delimiter exactly once (the scan stops
     * there); anything past it is the caller's junk-check business.
     *
     * One pass with a per-frame STATE STACK. Each open compound is a
     * frame ['kind'=>'o'|'a', 'state', 'segStart', 'segs', 'kids',
     * 'pendingKid']; the byte dispatch always reads the TOP frame's
     * state (§ 5.8.5):
     *  - keyStart / itemStart: a key / item may begin (a quoted key
     *    opens an opaque skip here; '{' / '[' are literal KEY data).
     *  - keyBody: inside the key; ':' dispatches the value.
     *  - valueStart: after ':', the first non-ws byte decides ONCE —
     *    '{' / '[' push a child frame, an escape / other byte goes
     *    scalar.
     *  - valueRaw ('::'): everything until , } ] is literal data
     *    except those three closers.
     *  - valueScalar / valueCompound: braces are literal data; the
     *    frame's own closer still closes it.
     *
     * Returns the matching closer position, the depth-0 segments
     * [start,end), a kids map segIndex => ['kind','valueStart',
     * 'closer','segs','kids'] for nested compound values (so the
     * parser NEVER re-scans a nested region), and diagnostics.
     *
     * @return array{closer:?int, mismatch:bool, badEscape:?int, segs:array[], kids:array}
     */
    public static function scan(string $src, string $kind, int $start, int $end): array
    {
        $frames = [[
            'kind' => $kind,
            'state' => $kind === 'o' ? 'keyStart' : 'itemStart',
            'segStart' => $start,
            'segs' => [],
            'kids' => [],
            'pendingKid' => null,
        ]];
        $i = $start;
        while ($i < $end) {
            PureWorkMeter::tick();
            $t = count($frames) - 1;
            $b = ord($src[$i]);
            if ($b === 0x5C) { // escape lead: validate outside quotes
                $d = PureScanner::decodeEscape($src, $i, $end);
                if ($d === null) {
                    return ['closer' => null, 'mismatch' => false, 'badEscape' => $i, 'segs' => [], 'kids' => []];
                }
                $i = $d[1];
                if ($frames[$t]['state'] === 'keyStart' || $frames[$t]['state'] === 'itemStart') {
                    $frames[$t]['state'] = 'keyBody';
                } elseif ($frames[$t]['state'] === 'valueStart') {
                    // An escaped first value byte closes valueStart —
                    // a later literal bracket must stay scalar data.
                    $frames[$t]['state'] = 'valueScalar';
                }
                continue;
            }
            if ($frames[$t]['state'] === 'keyStart' && PureScanner::isQuoteByte($b)) {
                // Quoted key segment: opaque through its closer.
                ++$i;
                $closed = false;
                while ($i < $end) {
                    $c = ord($src[$i]);
                    if ($c === 0x5C) {
                        $i += 2;
                        continue;
                    }
                    if ($c === $b) {
                        $closed = true;
                        ++$i;
                        break;
                    }
                    ++$i;
                }
                if (!$closed) {
                    // § 6.16 / § 5.3.3: swallowed everything incl. the
                    // closer — UnterminatedInlineCompound here, never
                    // UnterminatedQuotedKey.
                    return ['closer' => null, 'mismatch' => false, 'badEscape' => null, 'segs' => [], 'kids' => []];
                }
                $frames[$t]['state'] = 'keyBody';
                continue;
            }
            if ($b === 0x3A && $frames[$t]['kind'] === 'o' && $frames[$t]['state'] === 'keyBody') { // ':'
                if ($i + 1 < $end && ord($src[$i + 1]) === 0x3A) {
                    // '::' raw value: literal until , } ] (§ 4); a
                    // leading '{' / '[' never recurses.
                    $frames[$t]['state'] = 'valueRaw';
                    $i += 2;
                    continue;
                }
                $frames[$t]['state'] = 'valueStart';
                ++$i;
                continue;
            }
            if ($b === 0x7B || $b === 0x5B) { // '{' '['
                $st = $frames[$t]['state'];
                if ($st === 'itemStart' || $st === 'valueStart') {
                    // § 5.8.5: the decision is made ONCE at the first
                    // byte of an inline value (array item or object
                    // pair value). Keys are never compounds.
                    $ck = $b === 0x7B ? 'o' : 'a';
                    $frames[$t]['state'] = 'valueCompound';
                    $frames[$t]['pendingKid'] = [
                        'segIdx' => count($frames[$t]['segs']),
                        'valueStart' => $i,
                        'kind' => $ck,
                    ];
                    $frames[] = [
                        'kind' => $ck,
                        'state' => $ck === 'o' ? 'keyStart' : 'itemStart',
                        'segStart' => $i + 1,
                        'segs' => [],
                        'kids' => [],
                        'pendingKid' => null,
                    ];
                    ++$i;
                    continue;
                }
                // Literal data in any other state (mid-key, mid-scalar,
                // raw value).
                ++$i;
                continue;
            }
            if ($b === 0x7D || $b === 0x5D) { // '}' ']'
                $want = $b === 0x7D ? 'o' : 'a';
                if ($frames[$t]['kind'] !== $want) {
                    return ['closer' => null, 'mismatch' => true, 'badEscape' => null, 'segs' => [], 'kids' => []];
                }
                $f = $frames[$t];
                $f['segs'][] = [$f['segStart'], $i];
                array_pop($frames);
                if ($frames === []) {
                    // The root frame's own closer: done.
                    return ['closer' => $i, 'mismatch' => false, 'badEscape' => null, 'segs' => $f['segs'], 'kids' => $f['kids']];
                }
                $p = count($frames) - 1;
                $pk = $f['pendingKid'];
                if ($pk !== null) {
                    $frames[$p]['kids'][$pk['segIdx']] = [
                        'kind' => $pk['kind'],
                        'valueStart' => $pk['valueStart'],
                        'closer' => $i,
                        'segs' => $f['segs'],
                        'kids' => $f['kids'],
                    ];
                }
                // The parent stays in valueCompound: its own value is
                // complete, only ',' or its closer may follow.
                ++$i;
                continue;
            }
            if ($b === 0x2C) { // ','
                // § 4: , } ] terminate a raw / scalar value too, so a
                // comma ALWAYS closes the current segment.
                $frames[$t]['segs'][] = [$frames[$t]['segStart'], $i];
                ++$i;
                $frames[$t]['segStart'] = $i;
                $frames[$t]['state'] = $frames[$t]['kind'] === 'o' ? 'keyStart' : 'itemStart';
                continue;
            }
            // Other bytes: state entry transitions.
            $st = $frames[$t]['state'];
            if (($st === 'keyStart' || $st === 'itemStart' || $st === 'valueStart')
                && PureScanner::wsLenAt($src, $i, $end, true) === 0) {
                $frames[$t]['state'] = $st === 'valueStart' ? 'valueScalar' : 'keyBody';
            }
            // 'valueRaw' / 'valueScalar' / 'valueCompound' never leave
            // their state here.
            ++$i;
        }
        return ['closer' => null, 'mismatch' => false, 'badEscape' => null, 'segs' => [], 'kids' => []];
    }
}
