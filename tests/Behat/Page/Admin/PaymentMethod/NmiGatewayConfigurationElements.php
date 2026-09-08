<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Behat\Page\Admin\PaymentMethod;

/**
 * Shared by the create and update pages: element names match what Sylius's
 * "its gateway configuration :element should be :value" step derives from the field label.
 */
trait NmiGatewayConfigurationElements
{
    public function setTokenizationKey(string $key): void
    {
        $this->getElement('tokenization_key')->setValue($key);
    }

    public function setSecurityKey(string $key): void
    {
        $this->getElement('security_key')->setValue($key);
    }

    public function chooseEnvironment(string $environment): void
    {
        $this->getElement('environment')->selectOption($environment);
    }

    public function enableAuthorizeThenCapture(): void
    {
        $this->getElement('use_authorize')->check();
    }

    public function isAuthorizeThenCaptureEnabled(): bool
    {
        return $this->getElement('use_authorize')->isChecked();
    }

    public function enableCardSaving(): void
    {
        $this->getElement('store_cards')->check();
    }

    public function isCardSavingEnabled(): bool
    {
        return $this->getElement('store_cards')->isChecked();
    }

    public function disableStoredCardAuthentication(): void
    {
        $this->getElement('authenticate_stored_cards')->uncheck();
    }

    public function isStoredCardAuthenticationEnabled(): bool
    {
        return $this->getElement('authenticate_stored_cards')->isChecked();
    }

    /**
     * The help text an operator reads before deciding.
     *
     * Found by following the checkbox's own `aria-describedby` rather than by naming the element's
     * id, which is Sylius's form name and not this plugin's to depend on. The link is also the
     * thing that makes the text an accessible description rather than nearby prose, so following
     * it asserts something worth asserting.
     */
    public function getStoredCardAuthenticationHelp(): string
    {
        $describedBy = $this->getElement('authenticate_stored_cards')->getAttribute('aria-describedby');

        if (null === $describedBy || '' === $describedBy) {
            return '';
        }

        return $this->getDocument()->find('css', '#' . $describedBy)?->getText() ?? '';
    }

    /** @return array<string, string> */
    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'tokenization_key' => '[data-test-nmi-tokenization-key]',
            'security_key' => '[data-test-nmi-security-key]',
            'environment' => '[data-test-nmi-environment]',
            'use_authorize' => '[data-test-nmi-use-authorize]',
            'store_cards' => '[data-test-nmi-store-cards]',
            'authenticate_stored_cards' => '[data-test-nmi-authenticate-stored-cards]',
        ]);
    }
}
