<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Behat\Page\Admin\PaymentMethod;

interface NmiGatewayConfigurationPageInterface
{
    public function setTokenizationKey(string $key): void;

    public function setSecurityKey(string $key): void;

    public function chooseEnvironment(string $environment): void;

    public function enableAuthorizeThenCapture(): void;

    public function isAuthorizeThenCaptureEnabled(): bool;
}
