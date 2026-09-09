<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway;

/**
 * The single name under which this plugin is known to Sylius.
 *
 * It is the gateway factory name tagged on the configuration form type, the `factoryName` stored on
 * every NMI GatewayConfig, and the key every `gateway_factory`-indexed tag must use. The config keys
 * below are stored, encrypted, in `sylius_gateway_config.config`; renaming any of them is a breaking
 * change for every installed store.
 */
final class NmiGatewayFactory
{
    public const NAME = 'nmi';

    /** Public key with the Tokenization permission; shipped to the browser for Collect.js. */
    public const CONFIG_TOKENIZATION_KEY = 'tokenization_key';

    /** Private API security key; only ever sent to the gateway. */
    public const CONFIG_SECURITY_KEY = 'security_key';

    /** One of the ENVIRONMENT_* values. */
    public const CONFIG_ENVIRONMENT = 'environment';

    /** Read by Sylius's own DefaultActionProvider: truthy selects the authorize action. */
    public const CONFIG_USE_AUTHORIZE = 'use_authorize';

    /**
     * Whether shoppers may keep a card on file with this account.
     *
     * Off unless an operator says otherwise, and off is the whole feature absent: no option on the
     * pay page, no entry in the account menu, no row ever written.
     */
    public const CONFIG_STORE_CARDS = 'store_cards';

    /**
     * Whether paying with a stored card runs 3-D Secure again.
     *
     * On by default, and deliberately so: the alternative is a payment nobody authenticated, and a
     * store that wants that should have to say so rather than inherit it.
     */
    public const CONFIG_AUTHENTICATE_STORED_CARDS = 'authenticate_stored_cards';

    /**
     * Whether the shopper is emailed when the issuer closes or flags a card they saved.
     *
     * Off unless an operator says otherwise, because it is outbound mail sent on the store's
     * behalf about something the store did not do. Everything else the card updater reports
     * happens whether this is on or off: the card is marked, the account shows it, and the
     * checkout stops offering it.
     */
    public const CONFIG_EMAIL_CARDHOLDER = 'email_cardholder';

    /**
     * The key the gateway signs its webhook deliveries with, shown on its Webhooks settings page.
     *
     * Absent is the ordinary case and means this method receives no events: the endpoint has
     * nothing to verify against, so it refuses everything. It is a shared secret of the same kind
     * as the security key and is stored the same way, encrypted with the rest of this array.
     *
     * Note it is one key per **gateway account**, not per endpoint — the portal shows a single
     * signing key above the whole endpoint list. Two payment methods pointing at the same NMI
     * account therefore carry the same value, which is correct and not duplication to factor out.
     */
    public const CONFIG_WEBHOOK_SIGNING_KEY = 'webhook_signing_key';

    public const ENVIRONMENT_PRODUCTION = 'production';

    public const ENVIRONMENT_SANDBOX = 'sandbox';

    /** @var list<string> */
    public const ENVIRONMENTS = [self::ENVIRONMENT_PRODUCTION, self::ENVIRONMENT_SANDBOX];

    private function __construct()
    {
    }
}
