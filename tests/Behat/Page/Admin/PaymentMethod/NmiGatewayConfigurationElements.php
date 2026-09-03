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

    /** @return array<string, string> */
    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'tokenization_key' => '[data-test-nmi-tokenization-key]',
            'security_key' => '[data-test-nmi-security-key]',
            'environment' => '[data-test-nmi-environment]',
            'use_authorize' => '[data-test-nmi-use-authorize]',
        ]);
    }
}
