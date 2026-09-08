<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Admin;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Component\Core\Model\AdminUserInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The gateway configuration form as an operator reads it — **on the page an operator opens**.
 *
 * Every label and help text in this form is a translation key, which means a template rendering
 * it can be perfectly correct and still show the key itself — a missing catalogue entry does not
 * fail, it just prints `jpm_martin_sylius_nmi.form...` where a sentence should be. The only way
 * to know is to render the form and read the words, in each language the plugin claims to speak.
 *
 * **It used to render the form type on its own, and that is how two settings shipped invisible.**
 * `form_widget(form)` renders every child a form has; the admin page renders the fields its
 * hookable template names, one `form_row` at a time, and nothing passes the rest. So a field left
 * out of that template reached no operator at all while this test went on passing. It now asks the
 * admin page, which is the only thing that can answer the question it claims to ask.
 */
final class NmiGatewayConfigurationFormTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    /** Label and help text, for every field the form has. */
    private const EXPECTED = [
        'en' => [
            'Tokenization key',
            'The public key with the Tokenization permission.',
            'Security key',
            'The private API key.',
            'Environment',
            'Sandbox sends transactions to NMI&#039;s sandbox gateway',
            'Choose an environment',
            'Production',
            'Sandbox',
            'Authorize first, capture later',
            'checkout only authorizes the card',
            // *The setting explains itself.* The scenario names three claims the help text must
            // make, so the test names them too rather than checking that some help text exists.
            'Authenticate saved cards with 3-D Secure',
            'paying with a saved card is one click',
            'the issuer may decline a payment that carries no authentication',
            'liability for a chargeback stays with you',
            'Selling into North',
            'Let shoppers save their card',
            'may keep a card on file with NMI',
        ],
        'es' => [
            'Clave de tokenización',
            'La clave pública con el permiso Tokenization.',
            'Clave de seguridad',
            'La clave privada de la API.',
            'Entorno',
            'Sandbox envía las transacciones a la pasarela de pruebas de NMI',
            'Elige un entorno',
            'Producción',
            'Sandbox',
            'Autorizar primero, capturar después',
            'el checkout sólo autoriza la tarjeta',
            'Autenticar las tarjetas guardadas con 3-D Secure',
            'pagar con una tarjeta guardada es un solo clic',
            'el emisor puede rechazar un pago que no lleve autenticación',
            'la responsabilidad por un contracargo se queda en la tienda',
            'Norteamérica',
            'Permitir que los compradores guarden su tarjeta',
            'puede dejar una tarjeta guardada en NMI',
        ],
    ];

    /** @return iterable<string, array{string, list<string>}> */
    public static function locales(): iterable
    {
        foreach (self::EXPECTED as $locale => $expected) {
            yield $locale => [$locale, $expected];
        }
    }

    /**
     * @param list<string> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testTheFormSpeaksTheLocaleItIsRenderedIn(string $locale, array $expected): void
    {
        $html = $this->renderTheAdminPage($locale);

        foreach ($expected as $words) {
            self::assertStringContainsString($words, $html, sprintf('Missing from the %s form.', $locale));
        }

        self::assertStringNotContainsString(
            'jpm_martin_sylius_nmi.',
            $html,
            'An untranslated key reaches the operator as its own name, which reads as a bug in the store.',
        );
    }

    /**
     * Every field the form declares is on the page.
     *
     * Enumerated from the form type rather than written out, so a field added to the configuration
     * and forgotten in the template fails here instead of shipping as a setting nobody can reach.
     * That is not hypothetical: it is what happened to both card settings.
     */
    public function testEveryFieldTheFormDeclaresIsRenderedOnThePage(): void
    {
        $crawler = $this->openTheAdminPage('en');

        $fields = [
            NmiGatewayFactory::CONFIG_TOKENIZATION_KEY,
            NmiGatewayFactory::CONFIG_SECURITY_KEY,
            NmiGatewayFactory::CONFIG_ENVIRONMENT,
            NmiGatewayFactory::CONFIG_USE_AUTHORIZE,
            NmiGatewayFactory::CONFIG_STORE_CARDS,
            NmiGatewayFactory::CONFIG_AUTHENTICATE_STORED_CARDS,
        ];

        foreach ($fields as $field) {
            self::assertCount(
                1,
                $crawler->filter(sprintf('[name$="[%s]"]', $field)),
                sprintf('"%s" is in the form type but not on the page, so no operator can set it.', $field),
            );
        }
    }

    /**
     * The form has to agree with the code about what silence means.
     *
     * The provider reads an absent key as "authenticate", but a checkbox rendered unchecked posts
     * nothing, and Symfony stores that as an explicit false — so a form that showed this off would
     * write the answer nobody gave into every payment method created through it, and the default
     * would only ever apply to configurations written before the field existed.
     */
    public function testANewPaymentMethodStartsWithStoredCardAuthenticationOn(): void
    {
        $crawler = $this->openTheAdminPage('en');

        self::assertNotNull(
            $crawler->filter(sprintf('[name$="[%s]"]', NmiGatewayFactory::CONFIG_AUTHENTICATE_STORED_CARDS))->attr('checked'),
            'A new payment method must show authentication on, because that is what the code reads silence as.',
        );

        // The other two are off until asked for, and this is what says the listener above did not
        // quietly tick everything.
        foreach ([NmiGatewayFactory::CONFIG_STORE_CARDS, NmiGatewayFactory::CONFIG_USE_AUTHORIZE] as $field) {
            self::assertNull(
                $crawler->filter(sprintf('[name$="[%s]"]', $field))->attr('checked'),
                sprintf('"%s" must start off.', $field),
            );
        }
    }

    private function renderTheAdminPage(string $locale): string
    {
        $this->openTheAdminPage($locale);

        return (string) $this->client->getResponse()->getContent();
    }

    private function openTheAdminPage(string $locale): Crawler
    {
        $this->client->loginUser($this->anAdministrator($locale), 'admin');

        return $this->client->request('GET', '/admin/payment-methods/new/' . NmiGatewayFactory::NAME);
    }

    /**
     * The locale of the page is the administrator's own, which is how Sylius decides it. Switching
     * the translator instead would render a page no operator ever sees.
     */
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
