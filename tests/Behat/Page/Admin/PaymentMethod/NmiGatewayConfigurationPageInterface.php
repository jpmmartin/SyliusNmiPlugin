<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Behat\Page\Admin\PaymentMethod;

interface NmiGatewayConfigurationPageInterface
{
    public function setTokenizationKey(string $key): void;

    public function setSecurityKey(string $key): void;

    public function setGatewayHost(string $host): void;

    public function enableAuthorizeThenCapture(): void;

    public function isAuthorizeThenCaptureEnabled(): bool;

    public function enableCardSaving(): void;

    public function isCardSavingEnabled(): bool;

    public function disableStoredCardAuthentication(): void;

    public function isStoredCardAuthenticationEnabled(): bool;

    public function getStoredCardAuthenticationHelp(): string;
}
