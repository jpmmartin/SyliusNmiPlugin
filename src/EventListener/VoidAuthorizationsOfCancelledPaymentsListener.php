<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\EventListener;

use Doctrine\ORM\Event\OnFlushEventArgs;
use JpmMartin\SyliusNmiPlugin\Command\VoidAuthorization;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Lifecycle\NmiOpenAuthorizationResolver;
use JpmMartin\SyliusNmiPlugin\Lifecycle\NmiReportedVoids;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues the void of the authorisation every payment a flush saves as cancelled from `authorized`
 * leaves open, whatever cancelled it.
 *
 * **The flush, not the order screen.** Sylius cancels an order's payments through its state machine,
 * and so do the admin API and a store's own code; none of them fires the order screen's events.
 * Every one of them ends in a flush that saves the payment as cancelled, and nothing that is not
 * saved does — so that is where the void is decided.
 *
 * **From `authorized`, not "with an authorisation on record".** Checkout records a declined
 * authorisation too, so a payment cancelled while still awaiting payment can have one; only an
 * approval moves a payment to `authorized`.
 *
 * **Decided in `onFlush`, dispatched in `postFlush`**, on the event bus, as a cancelled payment's card
 * on file is let go: the message leaves once the cancellation has been written, and with the default
 * queue it is written in the same transaction. Not on the command bus, whose transaction middleware
 * would flush again from inside this flush.
 *
 * Nothing is scheduled here, so where this runs among the flush's other listeners does not matter.
 *
 * @internal
 */
final class VoidAuthorizationsOfCancelledPaymentsListener
{
    /** @var list<VoidAuthorization> */
    private array $pending = [];

    private bool $dispatching = false;

    public function __construct(
        private readonly NmiOpenAuthorizationResolver $openAuthorizations,
        private readonly NmiReportedVoids $reportedVoids,
        private readonly MessageBusInterface $eventBus,
    ) {
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        // Replaced, not appended: a flush that failed after this ran must leave nothing for the next.
        $this->pending = [];

        $unitOfWork = $event->getObjectManager()->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityUpdates() as $payment) {
            if (!$payment instanceof PaymentInterface) {
                continue;
            }

            $state = $unitOfWork->getEntityChangeSet($payment)['state'] ?? null;
            if (!is_array($state) || PaymentInterface::STATE_AUTHORIZED !== $state[0] || PaymentInterface::STATE_CANCELLED !== $state[1]) {
                continue;
            }

            if (NmiGatewayFactory::NAME !== $payment->getMethod()?->getGatewayConfig()?->getFactoryName()) {
                continue;
            }

            // Cancelled because the gateway said it voided the authorisation itself.
            if ($this->reportedVoids->isReported($payment)) {
                continue;
            }

            // The payment row's own void has already recorded its void by the time the cancellation
            // is saved, and a captured authorisation is not the payment's to give back.
            if (null === $this->openAuthorizations->resolve($payment)) {
                continue;
            }

            $this->pending[] = new VoidAuthorization((int) $payment->getId());
        }
    }

    public function postFlush(): void
    {
        // Emptied before dispatching: a dispatch that throws must not queue the same voids again.
        $pending = $this->pending;
        $this->pending = [];

        $this->dispatching = true;

        try {
            foreach ($pending as $void) {
                $this->eventBus->dispatch($void);
            }
        } finally {
            $this->dispatching = false;
        }
    }

    /**
     * Whether a void is being dispatched right now, from inside the flush that saved its
     * cancellation. A handler that finds this true is being run by a synchronous queue — an
     * asynchronous one hands the message to a worker, where this is never true — and must not write:
     * a flush from here would re-enter one that has not finished.
     */
    public function isDispatching(): bool
    {
        return $this->dispatching;
    }
}
