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

    /**
     * The gateway host this account is served from: an `https` URL with nothing after the host.
     *
     * NMI's own accounts live at `https://secure.nmi.com` (live) and `https://sandbox.nmi.com`
     * (sandbox); an account opened through a reseller lives at the reseller's host, which is the
     * one its merchant portal answers on. It is part of the account exactly like the keys, so it is
     * configured on the method beside them and read from nowhere else — not from the bundle's
     * configuration, not from the environment. Only the server-side calls go there: the browser
     * component tokenises and authenticates against NMI's own hosts whatever this says.
     */
    public const CONFIG_API_BASE_URL = 'api_base_url';

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
     * Whether an event naming a transaction this store does not know raises an operator notice.
     *
     * **Off, and the default is the whole point.** A gateway account shared with another store
     * produces these continuously — every one of that store's sales, refunds and voids arrives
     * here too — so a store that turned this on by inheritance would drown. It exists for the
     * store that has the account to itself, where an unknown transaction means something.
     */
    public const CONFIG_NOTIFY_UNKNOWN_TRANSACTIONS = 'notify_unknown_transactions';

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

    /** NMI's own live host, for help text and fixtures; an operator types it, the plugin never assumes it. */
    public const NMI_PRODUCTION_HOST = 'https://secure.nmi.com';

    /** NMI's own sandbox host, likewise. */
    public const NMI_SANDBOX_HOST = 'https://sandbox.nmi.com';

    private function __construct()
    {
    }
}
