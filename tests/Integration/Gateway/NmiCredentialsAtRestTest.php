<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\Gateway;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Credentials at rest, for a gateway configuration this suite builds rather than one the admin
 * form built.
 *
 * Sylius encrypts a gateway configuration only when it is *not* a Payum one, and a fresh config
 * says it is: `usePayum` defaults to true. The admin form turns it off for any factory Payum does
 * not know, which is why the credentials are encrypted there — and why a store that creates its
 * payment methods by fixture, migration or API, and leaves the flag alone, stores its security key
 * in plain text without being told.
 *
 * This asserts both halves, because the first without the second reads like an accident.
 */
final class NmiCredentialsAtRestTest extends KernelTestCase
{
    private const SECURITY_KEY = 'sec-at-rest-4567';

    private const WEBHOOK_SIGNING_KEY = 'sign-at-rest-89AB';

    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    public function testAGatewayConfigurationThisPluginsFixturesBuildIsEncrypted(): void
    {
        self::assertStringNotContainsString(self::SECURITY_KEY, $this->storedConfigFor(false));
    }

    /**
     * The webhook signing key is a shared secret of the same kind as the security key, and it
     * lives in the same array — so it is encrypted by the same mechanism and for the same reason.
     * Asserted separately rather than assumed from the line above, because "it is in the same
     * array" is exactly the sort of thing that stops being true when somebody moves a field.
     */
    public function testTheWebhookSigningKeyIsEncryptedToo(): void
    {
        self::assertStringNotContainsString(self::WEBHOOK_SIGNING_KEY, $this->storedConfigFor(false));
    }

    /**
     * The other half: left as Payum's, it is stored readable. Pinned so the first assertion cannot
     * quietly start passing for the wrong reason, and so the cost of the flag is written down
     * somewhere an author will meet it.
     */
    public function testTheSameConfigurationLeftAsAPayumOneIsNot(): void
    {
        self::assertStringContainsString(self::SECURITY_KEY, $this->storedConfigFor(true));
    }

    private function storedConfigFor(bool $usePayum): string
    {
        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = self::getContainer()->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setConfig([
            NmiGatewayFactory::CONFIG_SECURITY_KEY => self::SECURITY_KEY,
            NmiGatewayFactory::CONFIG_WEBHOOK_SIGNING_KEY => self::WEBHOOK_SIGNING_KEY,
        ]);
        $gatewayConfig->setUsePayum($usePayum);

        $this->manager->persist($gatewayConfig);
        $this->manager->flush();

        // Straight from the row: the ORM decrypts on load, so reading the entity proves nothing.
        return (string) $this->manager->getConnection()->fetchOne(
            'SELECT config FROM sylius_gateway_config WHERE id = :id',
            ['id' => $gatewayConfig->getId()],
        );
    }
}
