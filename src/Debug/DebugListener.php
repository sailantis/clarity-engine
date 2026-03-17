<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * DebugListener is an interface for objects that want to receive debug events from the DebugEventBus.
 * Implement the onEvent method to handle incoming DebugEvent instances.
 */
interface DebugListener
{
    public function onEvent(DebugEvent $event): void;
}
