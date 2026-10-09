<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * An immutable debug event: a type, a payload and the time it was emitted.
 *
 * The timestamp is microtime(true): seconds since the Unix epoch, with a fractional part.
 */
final class DebugEvent
{
    public function __construct(
        public readonly string $type,
        public readonly array $payload,
        public readonly float $timestamp,
    ) {
    }
}
