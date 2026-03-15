<?php

declare(strict_types=1);

namespace Clarity\Debug;

interface DebugListener
{
    public function onEvent(DebugEvent $event): void;
}
