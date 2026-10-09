<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * Renders debug values as an indented tree for the terminal.
 *
 * By default, dump() writes the tree to STDERR and returns ''. With
 * DumpOptions::forceToTemplate(true), render() returns the text instead.
 * renderForced() always returns the text; dd() uses it and writes the result to
 * STDERR (see {@see DebugRuntime::dumpAndDie()}).
 *
 * Associative arrays and objects are shown as {key: value} and sequential
 * arrays as [item, item], one item per line. Sensitive keys and property names
 * are replaced with ***. ANSI colors are used when the stream the output goes
 * to supports them: VT100 on Windows, a TTY elsewhere.
 */
final class CliDumpRenderer implements DumpRenderer
{
    use DumpMaskingTrait;

    public function render(mixed $value, DumpOptions $opts): string
    {
        $ansi = self::supportsColor(\STDERR);

        $output = '[DUMP] ' . $this->renderValue($value, $opts, 0, $ansi) . "\n";

        if (!$opts->getForceToTemplate()) {
            \fwrite(\STDERR, $output);
            return '';
        }

        return $output;
    }

    /**
     * Returns the rendered text whatever forceToTemplate is set to. dd() uses this.
     */
    public function renderForced(mixed $value, DumpOptions $opts): string
    {
        $ansi = self::supportsColor(\STDERR);

        return '[DUMP] ' . $this->renderValue($value, $opts, 0, $ansi) . "\n";
    }

    /**
     * Windows needs VT100 support enabled on the console, so it is checked
     * with sapi_windows_vt100_support(). Other systems check for a TTY.
     *
     * @param resource $stream
     */
    private static function supportsColor(mixed $stream): bool
    {
        if (\PHP_SAPI !== 'cli') {
            return false;
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            return \function_exists('sapi_windows_vt100_support')
                && @\sapi_windows_vt100_support($stream);
        }

        return \function_exists('stream_isatty') && @\stream_isatty($stream);
    }

    private function renderValue(mixed $value, DumpOptions $opts, int $depth, bool $ansi): string
    {
        if ($depth >= $opts->getMaxDepth()) {
            return $ansi ? "\e[90m…\e[0m" : '…';
        }

        if (\is_array($value)) {
            return $this->renderArray($value, $opts, $depth, $ansi);
        }

        if (\is_null($value)) {
            return $ansi ? "\e[38;5;141mnull\e[0m" : 'null';
        }

        if (\is_bool($value)) {
            $str = $value ? 'true' : 'false';
            return $ansi ? "\e[33m{$str}\e[0m" : $str;
        }

        if (\is_string($value)) {
            $escaped = \addcslashes($value, '"\\');
            return $ansi ? "\e[32m\"{$escaped}\"\e[0m" : "\"{$escaped}\"";
        }

        if (\is_int($value) || \is_float($value)) {
            return $ansi ? "\e[36m{$value}\e[0m" : (string) $value;
        }

        if ($this->isExpandableObject($value)) {
            return $this->renderArray($this->objectProperties($value), $opts, $depth, $ansi);
        }

        $repr = \print_r($value, true);
        return $ansi ? "\e[35m{$repr}\e[0m" : $repr;
    }

    private function renderArray(array $arr, DumpOptions $opts, int $depth, bool $ansi): string
    {
        $count = \count($arr);
        $isAssoc = $count > 0 && \array_keys($arr) !== \range(0, $count - 1);
        $indent = \str_repeat('  ', $depth);
        $inner = \str_repeat('  ', $depth + 1);
        $open = $isAssoc ? '{' : '[';
        $close = $isAssoc ? '}' : ']';

        if ($count === 0) {
            return $open . $close;
        }

        $items = [];
        $shown = 0;
        foreach ($arr as $k => $v) {
            if ($shown >= $opts->getMaxItems()) {
                $remaining = $count - $shown;
                $items[] = $inner . ($ansi ? "\e[90m… {$remaining} more …\e[0m" : '…');
                break;
            }

            $masked = \is_string($k) && $this->isMasked($k, $opts);

            if ($isAssoc) {
                $keyStr = $ansi ? "\e[33m{$k}\e[0m: " : "{$k}: ";
                $valStr = $masked
                    ? ($ansi ? "\e[31m***\e[0m" : '***')
                    : $this->renderValue($v, $opts, $depth + 1, $ansi);
                $items[] = $inner . $keyStr . $valStr;
            } else {
                $valStr = $masked
                    ? ($ansi ? "\e[31m***\e[0m" : '***')
                    : $this->renderValue($v, $opts, $depth + 1, $ansi);
                $items[] = $inner . $valStr;
            }

            $shown++;
        }

        return $open . "\n" . \implode(",\n", $items) . "\n" . $indent . $close;
    }
}
