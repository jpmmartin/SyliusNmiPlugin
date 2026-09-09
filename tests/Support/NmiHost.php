<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Support;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;

/**
 * The gateway host the suite's fixtures configure on the methods they build.
 *
 * A store has no such variable: the host is typed into the payment method form. The suite keeps
 * one because its fixtures build methods without a form, and on this project's own machine the
 * sandbox account is served by a reseller. Empty means NMI's own sandbox host, which is what the
 * suite gets in continuous integration, where nothing real is ever reached.
 */
final class NmiHost
{
    public static function forTests(): string
    {
        $configured = $_SERVER['NMI_API_BASE_URL'] ?? $_ENV['NMI_API_BASE_URL'] ?? getenv('NMI_API_BASE_URL');

        return is_string($configured) && '' !== trim($configured) ? rtrim(trim($configured), '/') : NmiGatewayFactory::NMI_SANDBOX_HOST;
    }

    private function __construct()
    {
    }
}
