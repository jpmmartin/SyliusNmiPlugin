<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Refund;

/**
 * This plugin's own transition on the refund plugin's refund-payment workflow.
 *
 * The refund plugin's `complete` is what its admin button applies, and that one is guarded shut
 * for NMI methods: nobody completes a gateway refund by hand. The gateway's approval takes this
 * transition instead, declared by the plugin on the same workflow, from `new` to `completed`. Its
 * own name, so that another gateway plugin's listeners on a transition of theirs are never in the
 * path.
 */
final class RefundPaymentTransitions
{
    public const TRANSITION_CONFIRM_GATEWAY_REFUND = 'confirm_gateway_refund';

    private function __construct()
    {
    }
}
