<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Webhook;

interface NmiReceivedEventLedgerInterface
{
    /**
     * Records the delivery, and answers whether it is the first time this store has seen it.
     *
     * False means it is a repeat: the caller must apply nothing and answer the gateway with
     * success, so that it stops redelivering.
     */
    public function accept(NmiWebhookEnvelope $envelope, string $payload, string $paymentMethodCode): bool;
}
