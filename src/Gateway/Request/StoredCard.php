<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway\Request;

/**
 * A card the gateway already holds, named the way a charge has to name it.
 *
 * Only the vault reference is required, and that is a finding rather than a convenience: a stored
 * card was charged against the gateway carrying `customer_vault: {"id": …}` and nothing else. The
 * other two refine the charge and neither gates it, which is what makes a card added from the
 * account area — with no transaction to cite — chargeable like any other.
 */
final class StoredCard
{
    public function __construct(
        public readonly string $vaultId,
        /**
         * Which billing record inside the vault entry pays.
         *
         * Every entry this plugin creates holds exactly one, so omitting it selects the same
         * record it would have named. Sent when known because naming it is more precise than
         * relying on that; absent for a card saved while paying, which the gateway reports
         * without one.
         */
        public readonly ?string $billingId = null,
        /**
         * The transaction that first stored this card, cited so the card networks can price the
         * charge as a re-use of a credential the shopper already agreed to store.
         *
         * Null for a card added from the account area: nothing was charged, so there is no
         * transaction to cite, and the gateway accepts the charge without one.
         */
        public readonly ?string $initialTransactionId = null,
    ) {
    }
}
