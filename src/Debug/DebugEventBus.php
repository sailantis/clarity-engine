<?php

declare(strict_types=1);

namespace Clarity\Debug;

final class DebugEventBus
{
    /** @var list<DebugListener|callable> */
    private array $listeners = [];

    /** @var list<DebugEvent> */
    private array $events = [];

    public function subscribe(DebugListener|callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function emit(string $type, array $payload = []): void
    {
        $event = new DebugEvent($type, $payload, \microtime(true));
        $this->events[] = $event;
        foreach ($this->listeners as $listener) {
            if ($listener instanceof DebugListener) {
                $listener->onEvent($event);
            } else {
                ($listener)($event);
            }
        }
    }

    /** @return list<DebugEvent> */
    public function getEvents(): array
    {
        return $this->events;
    }
}
