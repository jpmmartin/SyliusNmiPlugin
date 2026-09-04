<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Twig;

use JpmMartin\SyliusNmiPlugin\Twig\NmiRefundSupportExtension;
use PHPUnit\Framework\TestCase;

/**
 * The refund plugin's list is matched against the *factory* name, which is what its own provider
 * reads off the gateway configuration. A store that guessed and wrote the payment method's code
 * or its name there gets no error and no refund option, so the name being matched matters.
 */
final class NmiRefundSupportExtensionTest extends TestCase
{
    public function testAListThatDoesNotNameThisGatewayMeansNoRefundOption(): void
    {
        self::assertFalse((new NmiRefundSupportExtension(['offline']))->refundsAreOffered());
    }

    public function testAListThatNamesThisGatewayMeansItIsOffered(): void
    {
        self::assertTrue((new NmiRefundSupportExtension(['offline', 'nmi']))->refundsAreOffered());
    }

    /** An empty list is the store having thought about it and said no, not a misconfiguration. */
    public function testAnEmptyListOffersNothing(): void
    {
        self::assertFalse((new NmiRefundSupportExtension([]))->refundsAreOffered());
    }

    public function testTheFunctionIsAvailableToTemplates(): void
    {
        $names = array_map(
            static fn (\Twig\TwigFunction $function): string => $function->getName(),
            (new NmiRefundSupportExtension(['offline']))->getFunctions(),
        );

        self::assertContains('jpm_martin_sylius_nmi_refunds_offered', $names);
    }
}
