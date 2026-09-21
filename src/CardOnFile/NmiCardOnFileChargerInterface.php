<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CardOnFile;

use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Charges the card put on file for a payment at checkout, with nobody present.
 *
 * For a store's own server-side code — a message worker, a console command, an admin action. The
 * card charged is the one put on file for this payment, and the amount is the payment's own: there
 * is no way to name another card or another amount. The charge is declared to the card networks as
 * merchant-initiated and cites the verification that put the card on file.
 *
 * What this does not do is decide *whether* a payment should be charged. That is the store's
 * decision — an order approved, a made-to-order item finished — and it is taken before this is
 * called.
 */
interface NmiCardOnFileChargerInterface
{
    /**
     * The payment-request action the charge is recorded under. It names the record only: no request
     * created with it anywhere else — through the shop API, say — can cause a charge.
     */
    public const ACTION = 'nmi_charge_card_on_file';

    /**
     * Charges and answers when the gateway has, synchronously.
     *
     * @param array<string, mixed> $extra fields of the gateway's API the plugin does not model — a
     *                                    descriptor, merchant-defined fields — merged beneath the
     *                                    plugin's own exactly as on any charge, so they cannot change
     *                                    the amount, the card or the declaration
     */
    public function charge(PaymentInterface $payment, array $extra = []): NmiChargeOutcome;
}
