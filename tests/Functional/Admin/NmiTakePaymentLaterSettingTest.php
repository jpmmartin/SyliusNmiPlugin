<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Admin;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Locale\Model\Locale;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The setting that makes checkout put the card on file instead of charging it, read off the page an
 * operator actually sees: the embedded configuration form is laid out field by field, so a field
 * the template leaves out is one no operator can set.
 */
final class NmiTakePaymentLaterSettingTest extends WebTestCase
{
    private const FIELD = 'sylius_admin_payment_method[gatewayConfig][config][' . NmiGatewayFactory::CONFIG_TAKE_PAYMENT_LATER . ']';

    private const USE_AUTHORIZE = 'sylius_admin_payment_method[gatewayConfig][config][' . NmiGatewayFactory::CONFIG_USE_AUTHORIZE . ']';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    /** *Off by default* — and present, which the template decides, not the form type. */
    public function testTheSettingIsOnThePageAndOffOnANewMethod(): void
    {
        $crawler = $this->openTheAdminPage('en');

        $field = $crawler->filter(sprintf('[name$="[%s]"]', NmiGatewayFactory::CONFIG_TAKE_PAYMENT_LATER));
        self::assertCount(1, $field, 'The setting is in the form type but not on the page, so no operator can set it.');
        self::assertNull($field->attr('checked'));
    }

    public function testAMethodSavedWithoutTouchingItTakesPaymentAtCheckout(): void
    {
        $this->client->loginUser($this->anAdministrator('en'), 'admin');

        $code = $this->submitTheCreateForm();

        $config = $this->paymentMethod($code)?->getGatewayConfig()?->getConfig() ?? [];
        self::assertFalse((bool) ($config[NmiGatewayFactory::CONFIG_TAKE_PAYMENT_LATER] ?? false));
    }

    /**
     * *The setting explains itself.* The scenario names four claims the help text must make — nothing
     * charged or reserved, the later charge can be declined, the shopper is not present, and the
     * store's terms must say so — so each is looked for by name, in each language the plugin ships.
     *
     * @param list<string> $expected
     */
    #[DataProvider('explanations')]
    public function testTheSettingExplainsItselfInTheLanguageItIsRenderedIn(string $locale, array $expected): void
    {
        $this->openTheAdminPage($locale);
        $page = (string) $this->client->getResponse()->getContent();

        foreach ($expected as $text) {
            self::assertStringContainsString($text, $page, sprintf('The %s page does not say "%s".', $locale, $text));
        }
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function explanations(): iterable
    {
        yield 'English' => ['en', [
            'Take payment later',
            'nothing is charged or reserved',
            'that later charge can be declined',
            'without the shopper present',
            'Your own terms have to tell shoppers',
        ]];

        yield 'Spanish' => ['es', [
            'Cobrar más tarde',
            'sin cobrar ni reservar nada',
            'puede ser rechazado',
            'sin el comprador presente',
            'Vuestras condiciones tienen que decir',
        ]];
    }

    /** *Not together with authorise-then-capture.* The error sits on the new field, and nothing is saved. */
    public function testItIsRefusedTogetherWithAuthorizeFirstAndNothingIsPersisted(): void
    {
        $this->client->loginUser($this->anAdministrator('en'), 'admin');

        $code = $this->submitTheCreateForm(takePaymentLater: true, useAuthorize: true);

        self::assertNull($this->paymentMethod($code), 'A method taking payment later and authorising first was saved.');

        // On the new field, not merely somewhere on the page: the operator has to see which of the
        // two switches the refusal is about.
        $page = new Crawler((string) $this->client->getResponse()->getContent());
        $field = $page->filter(sprintf('[name$="[%s]"]', NmiGatewayFactory::CONFIG_TAKE_PAYMENT_LATER));
        self::assertStringContainsString('is-invalid', (string) $field->attr('class'));
        self::assertStringNotContainsString('is-invalid', (string) $page->filter(sprintf('[name$="[%s]"]', NmiGatewayFactory::CONFIG_USE_AUTHORIZE))->attr('class'));
        self::assertStringContainsString(
            'cannot be used with authorize first, capture later',
            (string) $field->closest('.col-12')?->text(),
        );
    }

    public function testItIsSavedWhenChosenOnItsOwn(): void
    {
        $this->client->loginUser($this->anAdministrator('en'), 'admin');

        $code = $this->submitTheCreateForm(takePaymentLater: true);

        $config = $this->paymentMethod($code)?->getGatewayConfig()?->getConfig() ?? [];
        self::assertTrue((bool) ($config[NmiGatewayFactory::CONFIG_TAKE_PAYMENT_LATER] ?? false));
    }

    private function submitTheCreateForm(bool $takePaymentLater = false, bool $useAuthorize = false): string
    {
        // The name goes into the locale's own block, which exists only for a locale the store has;
        // continuous integration loads no fixtures, so it is built here.
        $this->aLocale('en_US');

        $code = 'nmi_' . bin2hex(random_bytes(3));
        $crawler = $this->client->request('GET', '/admin/payment-methods/new/' . NmiGatewayFactory::NAME);
        $form = $crawler->selectButton('Create')->form();

        $form['sylius_admin_payment_method[code]'] = $code;
        $form['sylius_admin_payment_method[translations][en_US][name]'] = 'Card';
        $form['sylius_admin_payment_method[gatewayConfig][config][' . NmiGatewayFactory::CONFIG_TOKENIZATION_KEY . ']'] = 'tok-public-0123';
        $form['sylius_admin_payment_method[gatewayConfig][config][' . NmiGatewayFactory::CONFIG_SECURITY_KEY . ']'] = 'sec-private-4567';
        $form['sylius_admin_payment_method[gatewayConfig][config][' . NmiGatewayFactory::CONFIG_API_BASE_URL . ']'] = 'https://sandbox.nmi.com';

        self::assertTrue($form->has(self::FIELD), 'The page has no field for taking payment later.');
        if ($takePaymentLater) {
            $form[self::FIELD]->tick();
        }
        if ($useAuthorize) {
            $form[self::USE_AUTHORIZE]->tick();
        }

        $this->client->submit($form);

        return $code;
    }

    private function openTheAdminPage(string $locale): Crawler
    {
        $this->client->loginUser($this->anAdministrator($locale), 'admin');

        return $this->client->request('GET', '/admin/payment-methods/new/' . NmiGatewayFactory::NAME);
    }

    private function aLocale(string $code): void
    {
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        if (null !== $manager->getRepository(Locale::class)->findOneBy(['code' => $code])) {
            return;
        }

        $locale = new Locale();
        $locale->setCode($code);
        $manager->persist($locale);
        $manager->flush();
    }

    private function paymentMethod(string $code): ?PaymentMethodInterface
    {
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $manager->clear();

        /** @var PaymentMethodInterface|null $method */
        $method = $manager->getRepository(PaymentMethod::class)->findOneBy(['code' => $code]);

        return $method;
    }

    /** The page's language is the administrator's own, which is how Sylius decides it. */
    private function anAdministrator(string $locale): AdminUserInterface
    {
        $container = self::getContainer();

        /** @var AdminUserInterface $user */
        $user = $container->get('sylius.factory.admin_user')->createNew();
        $user->setEmail(sprintf('ada+%s@example.com', bin2hex(random_bytes(4))));
        $user->setUsername('ada-' . bin2hex(random_bytes(4)));
        $user->setPlainPassword('not-checked');
        $user->setEnabled(true);
        $user->setLocaleCode('en' === $locale ? 'en_US' : 'es_ES');

        $manager = $container->get('doctrine.orm.default_entity_manager');
        $manager->persist($user);
        $manager->flush();

        return $user;
    }
}
