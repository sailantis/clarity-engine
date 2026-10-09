<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * Receives events from a DebugEventBus.
 */
interface DebugListener
{
    public function onEvent(DebugEvent $event): void;
}
