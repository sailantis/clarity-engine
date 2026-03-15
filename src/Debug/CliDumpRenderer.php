<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * Renders debug values as an ANSI-colored (or plain-text) tree on STDERR.
 *
 * By default, output goes to STDERR (pipeline-safe: does not corrupt stdout).
 * Set DumpOptions::$forceToTemplate = true to receive the string instead.
 *
 * Associative arrays → {key: value}, sequential arrays → [item, …].
 * Sensitive keys are replaced with ***.
 */
final class CliDumpRenderer implements DumpRenderer
{
    public function render(mixed $value, DumpOptions $opts): string
    {
        $isTty = \PHP_SAPI === 'cli'
            && \function_exists('posix_isatty')
            && @\posix_isatty(\STDIN);

        $output = '[DUMP] ' . $this->renderValue($value, $opts, 0, $isTty) . "\n";

        if (!$opts->forceToTemplate) {
            \fwrite(\STDERR, $output);
            return '';
        }

        return $output;
    }

    public function renderForced(mixed $value, DumpOptions $opts): string
    {
        $isTty = \PHP_SAPI === 'cli'
            && \function_exists('posix_isatty')
            && @\posix_isatty(\STDIN);

        return '[DUMP] ' . $this->renderValue($value, $opts, 0, $isTty) . "\n";
    }

    private function renderValue(mixed $value, DumpOptions $opts, int $depth, bool $ansi): string
    {
        if ($depth >= $opts->maxDepth) {
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

        $repr = \print_r($value, true);
        return $ansi ? "\e[35m{$repr}\e[0m" : $repr;
    }

    private function renderArray(array $arr, DumpOptions $opts, int $depth, bool $ansi): string
    {
        $isAssoc = \array_keys($arr) !== \range(0, \count($arr) - 1);
        $count = \count($arr);
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
            if ($shown >= $opts->maxItems) {
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

    private function isMasked(string $key, DumpOptions $opts): bool
    {
        $lower = \strtolower($key);
        foreach ($opts->maskKeys as $mask) {
            if (\str_contains($lower, \strtolower((string) $mask))) {
                return true;
            }
        }
        return false;
    }
}
