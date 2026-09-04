<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Twig;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Answers whether `sylius/refund-plugin` will offer this gateway.
 *
 * That plugin keeps its own list of gateways it refunds through and silently ignores every
 * other one, so a store that installs both and changes nothing gets no NMI option and no
 * explanation. The answer is a container parameter, which means it is settled at compile time
 * and this only reads it.
 *
 * Registered only when the refund plugin's bundle is: without it the parameter does not exist
 * and this service could not be constructed at all.
 */
final class NmiRefundSupportExtension extends AbstractExtension
{
    /** @param list<string> $supportedGateways */
    public function __construct(private readonly array $supportedGateways)
    {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('jpm_martin_sylius_nmi_refunds_offered', $this->refundsAreOffered(...)),
        ];
    }

    public function refundsAreOffered(): bool
    {
        return in_array(NmiGatewayFactory::NAME, $this->supportedGateways, true);
    }
}
