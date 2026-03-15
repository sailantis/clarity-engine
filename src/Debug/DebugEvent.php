<?php

declare(strict_types=1);

namespace Clarity\Debug;

final class DebugEvent
{
    public function __construct(
        public readonly string $type,
        public readonly array $payload,
        public readonly float $timestamp,
    ) {
    }
}
