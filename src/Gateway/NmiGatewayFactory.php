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

    public const ENVIRONMENT_PRODUCTION = 'production';

    public const ENVIRONMENT_SANDBOX = 'sandbox';

    /** @var list<string> */
    public const ENVIRONMENTS = [self::ENVIRONMENT_PRODUCTION, self::ENVIRONMENT_SANDBOX];

    private function __construct()
    {
    }
}
