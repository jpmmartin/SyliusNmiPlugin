<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Webhook;

use Sylius\Component\Core\Model\PaymentMethodInterface;

interface NmiWebhookRouterInterface
{
    /**
     * Acts on an event that has already been verified and recorded.
     *
     * It answers nothing. Everything that gets this far is acknowledged to the gateway whatever
     * happens next, because a delivery it cannot act on is not a delivery worth twenty retries.
     */
    public function route(NmiWebhookEnvelope $envelope, PaymentMethodInterface $paymentMethod): void;
}
