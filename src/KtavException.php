<?php

declare(strict_types=1);

namespace Ktav;

/**
 * Thrown on native failure. Carries the nine-field structured error
 * envelope (issue rust#12) as first-class accessors; getMessage()
 * stays human-readable, reconstructed from the envelope fields —
 * never the raw JSON.
 *
 * Envelope field `line` -> getErrorLine(); PHP's final
 * Exception::getLine() keeps its native meaning (the PHP throw site).
 * The nine structured fields are all exposed - getErrorLine() is a
 * naming constraint of the host language, not a missing field.
 */
final class KtavException extends \RuntimeException
{
    /** @var string|null */
    private $error;

    /** @var string|null */
    private $reason;

    /** @var int|null */
    private $errorLine;

    /** @var string|null */
    private $lineText;

    /** @var array|null the {"start","end"} map */
    private $span;

    /** @var array|null list of decoded key segments */
    private $path;

    /** @var string|null */
    private $body;

    /** @var string|null */
    private $canonical;

    /** @var string|null */
    private $specSection;

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Builds an instance from the raw nine-field envelope decoded
     * from the native error buffer. Fields are coerced defensively —
     * the native side guarantees all nine keys since 0.7.1, but
     * never trust wire data.
     *
     * @param array<string, mixed> $fields
     */
    public static function fromEnvelope(array $fields): self
    {
        $error = isset($fields['error']) && is_string($fields['error']) && $fields['error'] !== ''
            ? $fields['error']
            : 'Unknown';

        $stringOrNull = static function ($v): ?string {
            return isset($v) && is_string($v) ? $v : null;
        };
        $reason = $stringOrNull($fields['reason'] ?? null);
        $lineText = $stringOrNull($fields['line_text'] ?? null);
        $body = $stringOrNull($fields['body'] ?? null);
        $canonical = $stringOrNull($fields['canonical'] ?? null);
        $specSection = $stringOrNull($fields['spec_section'] ?? null);

        $line = null;
        if (isset($fields['line'])) {
            if (is_int($fields['line'])) {
                $line = $fields['line'];
            } elseif (is_string($fields['line']) && is_numeric($fields['line'])) {
                $line = (int) $fields['line'];
            }
        }

        $span = null;
        if (isset($fields['span']) && is_array($fields['span'])) {
            $start = $fields['span']['start'] ?? null;
            $end = $fields['span']['end'] ?? null;
            if ((is_int($start) || (is_string($start) && is_numeric($start)))
                && (is_int($end) || (is_string($end) && is_numeric($end)))) {
                $span = ['start' => (int) $start, 'end' => (int) $end];
            }
        }

        $path = null;
        if (array_key_exists('path', $fields) && is_array($fields['path'])) {
            $path = [];
            foreach ($fields['path'] as $seg) {
                if (is_string($seg)) {
                    $path[] = $seg;
                }
            }
        }

        $message = 'Ktav ' . $error;
        if ($reason !== null) {
            $message .= ' [' . $reason . ']';
        }
        if ($line !== null) {
            $message .= ' on line ' . $line;
        }
        if (is_string($body) && $body !== '') {
            $message .= ': ' . $body;
        } elseif (is_string($lineText) && $lineText !== '') {
            $message .= ': ' . $lineText;
        }
        if ($path !== null && $path !== []) {
            $message .= ' (path: ' . implode('.', $path) . ')';
        }

        $e = new self($message);
        $e->error = $error;
        $e->reason = $reason;
        $e->errorLine = $line;
        $e->lineText = $lineText;
        $e->span = $span;
        $e->path = $path;
        $e->body = $body;
        $e->canonical = $canonical;
        $e->specSection = $specSection;
        return $e;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getErrorLine(): ?int
    {
        return $this->errorLine;
    }

    public function getLineText(): ?string
    {
        return $this->lineText;
    }

    public function getSpan(): ?array
    {
        return $this->span;
    }

    public function getPath(): ?array
    {
        return $this->path;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getCanonical(): ?string
    {
        return $this->canonical;
    }

    public function getSpecSection(): ?string
    {
        return $this->specSection;
    }
}
