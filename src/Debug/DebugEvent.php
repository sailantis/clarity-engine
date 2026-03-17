<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * DebugEvent represents a single debug event emitted on the DebugEventBus.
 * It contains a type, an optional payload, and a timestamp of when it was emitted.
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
