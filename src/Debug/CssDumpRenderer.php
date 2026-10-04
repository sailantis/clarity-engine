<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * Renders debug values as a CSS comment: /* DEBUG_DUMP: {json} *\/
 *
 * Closing comment sequences in the JSON are escaped, and tag delimiters are
 * encoded to protect the surrounding <style> element.
 */
final class CssDumpRenderer implements DumpRenderer
{
    public function render(mixed $value, DumpOptions $opts): string
    {
        $masked = $this->maskValue($value, $opts, 0);
        $json = (string) \json_encode(
            $masked,
            \JSON_UNESCAPED_UNICODE
                | \JSON_UNESCAPED_SLASHES
                | \JSON_PARTIAL_OUTPUT_ON_ERROR
                | \JSON_HEX_TAG
        );

        $json = \str_replace('*/', '*\\/', $json);

        return '/* DEBUG_DUMP: ' . $json . ' */';
    }

    private function maskValue(mixed $value, DumpOptions $opts, int $depth): mixed
    {
        if ($depth >= $opts->maxDepth) {
            return '…';
        }

        if (!\is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $k => $v) {
            if (\is_string($k) && $this->isMasked($k, $opts)) {
                $result[$k] = '***';
            } else {
                $result[$k] = $this->maskValue($v, $opts, $depth + 1);
            }
        }
        return $result;
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
