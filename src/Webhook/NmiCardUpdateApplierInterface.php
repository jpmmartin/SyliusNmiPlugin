<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Webhook;

use Sylius\Component\Core\Model\PaymentMethodInterface;

interface NmiCardUpdateApplierInterface
{
    public function supports(string $eventType): bool;

    /** Applies every entry the summary names that this store recognises. */
    public function apply(NmiWebhookEnvelope $envelope, PaymentMethodInterface $paymentMethod): void;
}
