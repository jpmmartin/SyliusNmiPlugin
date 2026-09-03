<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Resource;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\DependencyInjection\JpmMartinSyliusNmiExtension;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransaction;
use JpmMartin\SyliusNmiPlugin\JpmMartinSyliusNmiPlugin;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Container;

/**
 * The resource bundle derives a prefix from the bundle class name and the extension derives an
 * alias from its own; the mapping only loads when the two agree. Neither is assumed here: the
 * prefix is recomputed the way the bundle computes it and everything is looked up through it.
 */
final class NmiTransactionResourceTest extends KernelTestCase
{
    private string $prefix;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->prefix = Container::underscore(substr((string) strrchr(JpmMartinSyliusNmiPlugin::class, '\\'), 1, -6));
    }

    public function testTheDerivedBundlePrefixIsTheExtensionAlias(): void
    {
        self::assertSame((new JpmMartinSyliusNmiExtension())->getAlias(), $this->prefix);
        self::assertTrue(self::getContainer()->getParameter(sprintf('%s.driver.doctrine/orm', $this->prefix)));
    }

    public function testTheTransactionIsARegisteredResource(): void
    {
        $container = self::getContainer();

        /** @var array<string, array<string, mixed>> $resources */
        $resources = $container->getParameter('sylius.resources');
        self::assertArrayHasKey(sprintf('%s.nmi_transaction', $this->prefix), $resources);
        self::assertSame(NmiTransaction::class, $container->getParameter(sprintf('%s.model.nmi_transaction.class', $this->prefix)));

        self::assertInstanceOf(NmiTransactionRepositoryInterface::class, $container->get(sprintf('%s.repository.nmi_transaction', $this->prefix)));
        self::assertTrue($container->has(sprintf('%s.factory.nmi_transaction', $this->prefix)));
    }

    public function testTheMappingLoadsAsAConcreteEntity(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        $metadata = $entityManager->getClassMetadata(NmiTransaction::class);

        self::assertFalse($metadata->isMappedSuperclass);
        self::assertSame('jpm_martin_sylius_nmi_transaction', $metadata->getTableName());
        self::assertEqualsCanonicalizing(['id', 'transactionId', 'type', 'parentTransactionId', 'amount', 'currencyCode', 'authCode', 'createdAt', 'settledAt'], $metadata->getFieldNames());
        self::assertSame('payment_id', $metadata->getSingleAssociationJoinColumnName('payment'));
    }
}
