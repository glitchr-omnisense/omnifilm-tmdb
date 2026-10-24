<?php

namespace Omnifilm\Tmdb;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * The PSR-14 dispatcher php-tmdb/api sends its requests through, and no
 * more: listeners by event class (the event's own, not its parents'),
 * called in the order added, until one stops the event. It keeps symfony/event-dispatcher out of a provider that
 * needs five listeners.
 */
final class EventDispatcher implements EventDispatcherInterface
{
    /** @var array<class-string, list<callable>> */
    private array $listeners = [];

    /**
     * @param class-string $event
     * @param callable     $listener called with the event
     */
    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(object $event): object
    {
        // By the event's own class, as php-tmdb/api's listeners expect (Symfony's dispatcher does
        // the same): BeforeRequestEvent extends RequestEvent, and the listener of the one must not
        // hear the other.
        foreach ($this->listeners[$event::class] ?? [] as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }

        return $event;
    }
}
