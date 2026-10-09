<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * Key masking and object property access shared by the dump renderers.
 */
trait DumpMaskingTrait
{
    private function isMasked(string $key, DumpOptions $opts): bool
    {
        $lower = \strtolower($key);
        foreach ($opts->getMaskKeys() as $mask) {
            if (\str_contains($lower, \strtolower((string) $mask))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Closures and enums keep their own rendering and are not expanded.
     */
    private function isExpandableObject(mixed $value): bool
    {
        return \is_object($value) && !$value instanceof \Closure && !$value instanceof \UnitEnum;
    }

    /**
     * The (array) cast prefixes protected and private names with NUL bytes.
     * They are removed so that mask keys match the plain property name.
     *
     * @return array<string, mixed>
     */
    private function objectProperties(object $object): array
    {
        $props = [];
        foreach ((array) $object as $name => $value) {
            $name = (string) $name;
            $pos = \strrpos($name, "\0");
            $props[$pos === false ? $name : \substr($name, $pos + 1)] = $value;
        }
        return $props;
    }
}
