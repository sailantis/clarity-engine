<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * Passes debug events to listeners and keeps emitted events in memory,
 * available through getEvents(). A listener is a DebugListener or any callable.
 * Only the most recent $maxEvents events are kept; older ones are dropped.
 */
final class DebugEventBus
{
    /** @var list<DebugListener|callable> */
    private array $listeners = [];

    /** @var list<DebugEvent> */
    private array $events = [];

    public function __construct(private readonly int $maxEvents = 1000)
    {
    }

    public function subscribe(DebugListener|callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function emit(string $type, array $payload = []): void
    {
        $event = new DebugEvent($type, $payload, \microtime(true));
        $this->events[] = $event;
        while (\count($this->events) > $this->maxEvents) {
            \array_shift($this->events);
        }
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
