<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Recurring;

use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiChargeOutcome;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Charges a recurring credential for a payment, with nobody present.
 *
 * For a store's own server-side code — a message worker renewing a subscription, a console command.
 * The payment is one the store created for the renewal, new, or the held payment that opened the
 * credential; the amount is that payment's own and may differ from every earlier charge. The charge
 * is declared to the card networks as merchant-initiated and cites the credential's first
 * transaction.
 *
 * What this does not do is decide *whether* a renewal is due, or how much it is. Both are the store's
 * decisions, taken before this is called, and a charge never lets the credential go: that is the
 * store's decision too.
 */
interface NmiRecurringChargerInterface
{
    /**
     * The payment-request action the charge is recorded under. It names the record only: no request
     * created with it anywhere else — through the shop API, say — can cause a charge.
     */
    public const ACTION = 'nmi_charge_recurring';

    /**
     * Charges and answers when the gateway has, synchronously.
     *
     * @param array<string, mixed> $extra fields of the gateway's API the plugin does not model — a
     *                                    descriptor, merchant-defined fields — merged beneath the
     *                                    plugin's own exactly as on any charge, so they cannot change
     *                                    the amount, the card or the declaration
     */
    public function charge(PaymentInterface $payment, NmiRecurringCredentialInterface $credential, array $extra = []): NmiChargeOutcome;
}
