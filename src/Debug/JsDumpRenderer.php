<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * Renders debug values as a JS comment: ;/* DEBUG_DUMP: {json} *\/
 *
 * The output is valid JavaScript in any statement position and does not
 * interfere with surrounding script logic.  Sensitive keys are masked in the
 * JSON payload.  Any '*\/' sequence inside the JSON is escaped to '*\\\/' to
 * prevent comment injection.
 */
final class JsDumpRenderer implements DumpRenderer
{
    public function render(mixed $value, DumpOptions $opts): string
    {
        $masked = $this->maskValue($value, $opts, 0);
        $json = (string) \json_encode(
            $masked,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        // Escape any '*/' to prevent closing the JS comment early
        $json = \str_replace('*/', '*\\/', $json);

        return ';/* DEBUG_DUMP: ' . $json . ' */';
    }

    private function maskValue(mixed $value, DumpOptions $opts, int $depth): mixed
    {
        if ($depth >= $opts->maxDepth || !\is_array($value)) {
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
