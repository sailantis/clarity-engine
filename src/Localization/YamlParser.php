<?php

namespace Clarity\Localization;

/**
 * Minimal YAML parser for flat and nested translation files.
 *
 * Supports the subset of YAML that translation catalogs typically use:
 *   - Key: value pairs (flat and nested, nested flattened to dot notation)
 *   - Quoted strings: single-quoted ('' escape) and double-quoted (\n, \t, …)
 *   - Block scalars: literal (|) and folded (>), with strip (|-) and (>-)
 *   - Inline comments: # after unquoted values
 *   - YAML document comments: # on their own line
 *   - Boolean / null literals returned as empty string (null, ~, true, false)
 *
 * Does NOT support: anchors (&), aliases (*), sequences as mapping values,
 * multi-document streams (---), or other advanced YAML features.
 *
 * This parser is intentionally simple and optimized for translation files.
 * Replace with a full YAML library (e.g. symfony/yaml) when you need full spec
 * compliance — the TranslationLoader only calls parse() so the swap is trivial.
 */
final class YamlParser
{
    /** @var string[] */
    private array $lines;
    private int $pos = 0;
    private int $count = 0;

    private function __construct(string $yaml)
    {
        // Normalize line endings
        $this->lines = \explode("\n", \str_replace(["\r\n", "\r"], "\n", $yaml));
        $this->count = \count($this->lines);
    }

    /**
     * Parse a YAML string and return a flat key → string map.
     * Nested mappings are flattened using dot notation.
     *
     * @return array<string, string>
     */
    public static function parse(string $yaml): array
    {
        if (\trim($yaml) === '') {
            return [];
        }
        $parser = new self($yaml);
        return $parser->parseBlock(0, '');
    }

    // -------------------------------------------------------------------------
    // Core: parse a block of lines at a given indent depth
    // -------------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function parseBlock(int $indent, string $prefix): array
    {
        $result = [];

        while ($this->pos < $this->count) {
            $line = $this->lines[$this->pos];

            // Skip blank and comment-only lines
            if ($this->isSkippable($line)) {
                $this->pos++;
                continue;
            }

            $lineIndent = $this->indentOf($line);

            // Dedented → end of this block
            if ($lineIndent < $indent) {
                break;
            }

            // Over-indented (shouldn't happen in valid YAML, skip gracefully)
            if ($lineIndent > $indent) {
                $this->pos++;
                continue;
            }

            // --- Try to match a mapping entry: "key: value" ---
            $content = \substr($line, $indent);

            [$key, $value] = $this->splitMapping($content);

            if ($key === null) {
                // Not a mapping line (e.g. a list item "- …"), skip
                $this->pos++;
                continue;
            }

            $fullKey = $prefix !== '' ? $prefix . '.' . $key : $key;

            // Block scalar indicator
            if (\in_array($value, ['|', '|-', '>', '>-'], true)) {
                $this->pos++;
                $result[$fullKey] = $this->parseBlockScalar($value, $indent);
                continue;
            }

            // Null / empty value → either nested mapping or empty string
            if ($value === '' || $value === '~' || \strtolower($value) === 'null') {
                $this->pos++;
                $nextIndent = $this->peekIndent();
                if ($nextIndent !== null && $nextIndent > $indent) {
                    // Recurse into nested mapping
                    foreach ($this->parseBlock($nextIndent, $fullKey) as $k => $v) {
                        $result[$k] = $v;
                    }
                } else {
                    $result[$fullKey] = '';
                }
                continue;
            }

            $result[$fullKey] = $this->parseScalar($value);
            $this->pos++;
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Block scalars (| and >)
    // -------------------------------------------------------------------------

    private function parseBlockScalar(string $indicator, int $parentIndent): string
    {
        $blockLines = [];
        $blockIndent = null;

        while ($this->pos < $this->count) {
            $line = $this->lines[$this->pos];
            $trimmed = \rtrim($line);

            if ($trimmed === '') {
                // Completely blank line — always included in block
                $blockLines[] = '';
                $this->pos++;
                continue;
            }

            $lineIndent = $this->indentOf($line);

            if ($blockIndent === null) {
                if ($lineIndent <= $parentIndent) {
                    break; // Nothing in block
                }
                $blockIndent = $lineIndent;
            }

            if ($lineIndent < $blockIndent) {
                break; // Back to parent scope
            }

            $blockLines[] = \substr($line, $blockIndent);
            $this->pos++;
        }

        // Strip trailing blank lines (always done; kept line only matters for trailing \n)
        while (!empty($blockLines) && \end($blockLines) === '') {
            \array_pop($blockLines);
        }

        $clip = \str_ends_with($indicator, '-'); // |- or >-
        $fold = $indicator[0] === '>';            // > or >-

        if ($fold) {
            return $this->foldLines($blockLines, $clip);
        }

        // Literal (|)
        $text = \implode("\n", $blockLines);
        return $clip ? $text : $text . "\n";
    }

    /**
     * Fold block scalar lines: consecutive non-empty lines → single space-joined line;
     * blank lines → paragraph breaks.
     *
     * @param string[] $lines
     */
    private function foldLines(array $lines, bool $clip): string
    {
        $text = '';

        foreach ($lines as $bl) {
            if ($bl === '') {
                $text = \rtrim($text) . "\n\n";
            } else {
                if ($text !== '' && !\str_ends_with($text, "\n")) {
                    $text .= ' ';
                }
                $text .= $bl;
            }
        }

        if ($clip) {
            return \rtrim($text, " \n");
        }

        // Restore single trailing newline
        return \rtrim($text, "\n") . "\n";
    }

    // -------------------------------------------------------------------------
    // Scalar value parsing
    // -------------------------------------------------------------------------

    private function parseScalar(string $value): string
    {
        $value = \trim($value);

        if ($value === '' || $value === '~') {
            return '';
        }

        $lower = \strtolower($value);
        if ($lower === 'null') {
            return '';
        }

        // Double-quoted string: allow \n, \t, \" etc.
        if (\strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') {
            return \stripcslashes(\substr($value, 1, -1));
        }

        // Single-quoted string: '' is the only escape sequence
        if (\strlen($value) >= 2 && $value[0] === "'" && $value[-1] === "'") {
            return \str_replace("''", "'", \substr($value, 1, -1));
        }

        // Unquoted plain scalar: strip inline comment (space + #)
        if (\preg_match('/^(.*?)\s+#/', $value, $m)) {
            $value = \rtrim($m[1]);
        }

        return $value;
    }

    // -------------------------------------------------------------------------
    // Mapping line split: "key: value" → [$key, $value]
    // Returns [null, null] when line is not a mapping entry.
    // -------------------------------------------------------------------------

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function splitMapping(string $content): array
    {
        // YAML mapping indicator: ": " (colon space) or ":" at end of line.
        // Keys must not start with "-" (list item indicator).
        if (\str_starts_with($content, '-') || \str_starts_with($content, '#')) {
            return [null, null];
        }

        // Check for ": " separator first (most common)
        $colonSpace = \strpos($content, ': ');
        if ($colonSpace !== false) {
            $key = \rtrim(\substr($content, 0, $colonSpace));
            $value = \ltrim(\substr($content, $colonSpace + 2));
            // Strip trailing inline comment from value (only outside quotes)
            return [$key === '' ? null : $key, $value === '' ? '' : $value];
        }

        // Check for "key:" at end of line (null / nested mapping value)
        $trimmed = \rtrim($content);
        if (\str_ends_with($trimmed, ':')) {
            $key = \rtrim(\substr($trimmed, 0, -1));
            return [$key === '' ? null : $key, ''];
        }

        return [null, null];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function isSkippable(string $line): bool
    {
        $trimmed = \ltrim($line, ' ');
        return $trimmed === '' || ($trimmed !== '' && $trimmed[0] === '#');
    }

    private function indentOf(string $line): int
    {
        return \strlen($line) - \strlen(\ltrim($line, ' '));
    }

    private function peekIndent(): ?int
    {
        $i = $this->pos;
        while ($i < $this->count) {
            if (!$this->isSkippable($this->lines[$i])) {
                return $this->indentOf($this->lines[$i]);
            }
            $i++;
        }
        return null;
    }
}
