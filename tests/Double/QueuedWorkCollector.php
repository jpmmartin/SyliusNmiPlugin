<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Double;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;

/**
 * Keeps, across kernels, what was sent to `main`.
 *
 * A Behat page runs in a kernel of its own, rebooted before every request, so the in-memory
 * transport a request wrote to is gone by the time a step looks at it. This keeps a copy where a
 * step can reach it — static, like the gateway fake's shared answers. The page's own transport is
 * never consumed, so nothing is handled twice.
 *
 * **Off unless a scenario turns it on**, and emptied when it turns it off. It is registered for the
 * whole test environment, and the PHPUnit suite, which never reads it, would otherwise keep every
 * message it sends for as long as the run lasts. Not reset with the container's services either: the
 * browser resets them between the request that queued the work and the redirect that follows it.
 */
final class QueuedWorkCollector implements EventSubscriberInterface
{
    private const TRANSPORT = 'main';

    /** @var list<Envelope> */
    private static array $sent = [];

    private static bool $collecting = false;

    public static function getSubscribedEvents(): array
    {
        return [SendMessageToTransportsEvent::class => 'collect'];
    }

    public function collect(SendMessageToTransportsEvent $event): void
    {
        if (self::$collecting && array_key_exists(self::TRANSPORT, $event->getSenders())) {
            self::$sent[] = $event->getEnvelope();
        }
    }

    /** @return list<Envelope> everything sent since it was last asked, which it then forgets */
    public static function takeAll(): array
    {
        $sent = self::$sent;
        self::$sent = [];

        return $sent;
    }

    public static function start(): void
    {
        self::$sent = [];
        self::$collecting = true;
    }

    public static function stop(): void
    {
        self::$sent = [];
        self::$collecting = false;
    }
}
