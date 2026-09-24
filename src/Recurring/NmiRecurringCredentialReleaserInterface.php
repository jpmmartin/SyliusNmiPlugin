<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Recurring;

use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;

/**
 * Lets a recurring credential go, when the renewals it was kept for have ended.
 *
 * The store's call to make — a subscription cancelled, a contract run out. Nothing in the plugin lets
 * a credential go on its own: not a charge, not the cancellation or deletion of the order that opened
 * it. Only deleting the customer does, because then there is nobody left to renew for.
 */
interface NmiRecurringCredentialReleaserInterface
{
    /**
     * Refused from this moment on, and its stored card removed from the gateway by the same queued
     * removal that forgets any card the store no longer holds. Letting go a credential already let go
     * changes nothing.
     */
    public function release(NmiRecurringCredentialInterface $credential): void;
}
