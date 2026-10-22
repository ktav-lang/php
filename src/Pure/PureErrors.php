<?php

declare(strict_types=1);

namespace Ktav\Pure;

use Ktav\KtavException;

/**
 * Envelope construction shared by the pure parser (a trait so the
 * error paths keep $this->src / span bookkeeping): the exact
 * nine-field shapes and Rust-Display-verbatim messages.
 */
trait PureErrors
{

    /** @return string */
    private function kindLabel($v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return 'bool';
        }
        if (is_int($v)) {
            return 'integer';
        }
        if (is_float($v)) {
            return 'float';
        }
        if (is_string($v)) {
            return 'string';
        }
        return $v instanceof PureObject ? 'object' : 'array';
    }


    /** @return void */
    private function checkLossy(string $body, $value, int $bs, int $be)
    {
        if (!$this->strict) {
            return;
        }
        $canonical = is_int($value) ? (string) $value : PureFloat::formatFloatText($value);
        if ($canonical === $body) {
            return;
        }
        list($line, $lineText) = PureScanner::locate($this->src, $bs);
        // Like the Rust core, the span starts at the LINE start and
        // runs to the end of the scalar body.
        $lineStart = $bs;
        while ($lineStart > 0) {
            $p = $lineStart - 1;
            $b = ord($this->src[$p]);
            if ($b === 0x0A || $b === 0x0D) {
                break;
            }
            --$lineStart;
        }
        PureKtav::failEnvelope([
            'error' => 'LossyScalar',
            'spec_section' => '§3.6/§5.2',
            'line' => $line,
            'line_text' => $lineText,
            'span' => ['start' => $lineStart, 'end' => $be],
            'body' => $body,
            'canonical' => $canonical,
            'message' => 'Syntax error: Line ' . $line
                . ": LossyScalar: '" . $body . "' would be inferred as a number and silently canonicalised to '"
                . $canonical . "'; append '::' to keep it a String or write the canonical form",
        ]);
    }

    private function rawAt(int $i): string
    {
        return substr($this->src, $i, min(2, $this->len - $i));
    }

    /**
     * Build and throw the nine-field envelope for a parse error.
     *
     * @param array $opts keys: key, seq, detail, found, conflict, pathText
     * @return void
     */
    private function fail(string $category, int $spanStart, int $spanEnd, array $opts = [])
    {
        if ($spanEnd < $spanStart) {
            $spanEnd = $spanStart;
        }
        list($line, $lineText) = PureScanner::locate($this->src, $spanStart);
        $env = [
            'error' => $category,
            'line' => $line,
            'line_text' => $lineText,
            'span' => ['start' => $spanStart, 'end' => $spanEnd],
        ];
        $msg = 'Syntax error: Line ' . $line . ': ' . $category;
        switch ($category) {
            case 'MissingSeparatorSpace':
                $env['spec_section'] = '§6.10';
                $msg .= ': separator must be followed by whitespace or end of line';
                break;
            case 'DuplicateKey':
                $env['spec_section'] = '§6.2';
                $env['path'] = $this->decodedPath($opts['key'] ?? '');
                $msg = 'Syntax error: Line ' . $line . ": duplicate key '" . ($opts['key'] ?? '') . "'";
                break;
            case 'KeyPathConflict':
                $env['spec_section'] = '§6.3';
                $env['path'] = $this->decodedPath($opts['pathText'] ?? '');
                $c = $opts['conflict'];
                if ($c[0] === 'overwrite') {
                    $msg = 'Syntax error: Line ' . $line . ": conflict at '" . $opts['pathText']
                        . "' \u{2014} cannot overwrite " . $c[1] . ' with ' . $c[2];
                } else {
                    $msg = 'Syntax error: Line ' . $line . ": conflict at '" . $opts['pathText']
                        . "' \u{2014} an existing value blocks the path";
                }
                break;
            case 'EmptyKey':
                $env['spec_section'] = '§6.5';
                $msg = 'Syntax error: Empty key at line ' . $line;
                break;
            case 'InvalidKey':
                $env['spec_section'] = '§6.4';
                $env['path'] = $this->rawPath($opts['key'] ?? '');
                $msg = 'Syntax error: Invalid key at line ' . $line . ": '" . ($opts['key'] ?? '') . "'";
                break;
            case 'UnbalancedBracket':
                $env['spec_section'] = '§6.1';
                $found = $opts['found'] ?? '?';
                $opener = $found === '}' ? '{' : '[';
                $msg .= ": '" . $found . "' without matching '" . $opener . "'";
                break;
            case 'MissingSeparator':
                $env['spec_section'] = '§6.6';
                $msg .= ': object entries must be \'key: value\' pairs';
                break;
            case 'UnterminatedInlineCompound':
                $env['spec_section'] = '§6.11';
                $msg .= ': inline compound not closed on the same line';
                break;
            case 'UnterminatedQuotedKey':
                $env['spec_section'] = '§6.16';
                $msg .= ': quoted key segment not closed on the same line';
                break;
            case 'MalformedInlineCompound':
                $env['spec_section'] = '§6.12';
                $env['body'] = $opts['detail'] ?? '';
                $msg .= ': ' . ($opts['detail'] ?? '');
                break;
            case 'BadEscapeSequence':
                $env['spec_section'] = '§6.13';
                $env['body'] = $opts['seq'] ?? '';
                $msg .= ": invalid escape sequence '" . ($opts['seq'] ?? '') . "'";
                break;
            case 'OrphanLineAfterTopLevelInline':
                $env['spec_section'] = '§6.14';
                $msg .= ': content after root-level inline compound';
                break;
            case 'UnclosedCompound':
                $env['spec_section'] = '§6.1';
                // Verbatim Rust Display: no category prefix, no line
                // prefix — the opener line travels in line/span.
                $msg = 'Syntax error: ' . ($opts['detail'] ?? 'unclosed compound');
                break;
        }
        $env['message'] = $msg;
        PureKtav::failEnvelope($env);
    }

    /** @return void */
    private function unclosed(array $frame)
    {
        $detail = $frame['t'] === 'o' ? 'Unclosed object at end of input'
            : ($frame['t'] === 'a' ? 'Unclosed array at end of input'
                : 'Unclosed multi-line string at end of input');
        $opener = isset($frame['opener']) ? $frame['opener'] : 0;
        $this->fail('UnclosedCompound', $opener, $this->len, ['detail' => $detail]);
    }

    /**
     * Envelope path: decoded segments of the raw dotted path,
     * best-effort (falls back to the raw spelling, § envelope.rs).
     *
     * @return string[]|null
     */
    private function decodedPath(string $raw)
    {
        $out = [];
        foreach ($this->rawPath($raw) as $seg) {
            $d = PureScanner::decodeKeySegment($seg);
            $out[] = isset($d['val']) && $d['val'] !== '' ? $d['val'] : $seg;
        }
        return $out;
    }

    /**
     * Envelope path WITHOUT decoding — the InvalidKey case (Rust's
     * kind_path splits but does not decode, since decoding is what
     * just failed).
     *
     * @return string[]
     */
    private function rawPath(string $raw)
    {
        $segs = PureScanner::splitKeyPath($raw, 0, strlen($raw));
        $out = [];
        foreach ($segs as $seg) {
            list($ss, $se) = PureScanner::trimBounds($seg, 0, strlen($seg));
            $out[] = $ss >= $se ? '' : substr($seg, $ss, $se - $ss);
        }
        return $out;
    }
}
