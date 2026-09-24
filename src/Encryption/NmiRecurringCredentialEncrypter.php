<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Encryption;

use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use Sylius\Component\Payment\Encryption\EncrypterInterface;
use Sylius\Component\Payment\Encryption\EncryptionAwareInterface;
use Sylius\Component\Payment\Encryption\EncryptionCheckTrait;
use Sylius\Component\Payment\Encryption\EntityEncrypterInterface;

/**
 * Encrypts the three gateway identifiers on a recurring credential and nothing else — the same
 * fields, for the same reason, as the other two card records do. The platform's encrypter does the
 * work.
 *
 * @implements EntityEncrypterInterface<NmiRecurringCredentialInterface>
 *
 * @internal
 */
final readonly class NmiRecurringCredentialEncrypter implements EntityEncrypterInterface
{
    use EncryptionCheckTrait;

    public function __construct(
        private EncrypterInterface $encrypter,
    ) {
    }

    public function encrypt(EncryptionAwareInterface $resource): void
    {
        if (!$resource instanceof NmiRecurringCredentialInterface) {
            return;
        }

        $resource->setVaultId($this->encrypted($resource->getVaultId()));
        $resource->setBillingId($this->encrypted($resource->getBillingId()));
        $resource->setInitialTransactionId($this->encrypted($resource->getInitialTransactionId()));
    }

    public function decrypt(EncryptionAwareInterface $resource): void
    {
        if (!$resource instanceof NmiRecurringCredentialInterface) {
            return;
        }

        $resource->setVaultId($this->decrypted($resource->getVaultId()));
        $resource->setBillingId($this->decrypted($resource->getBillingId()));
        $resource->setInitialTransactionId($this->decrypted($resource->getInitialTransactionId()));
    }

    /** Idempotent, because the listener decrypts in memory after a flush and can hand ciphertext back. */
    private function encrypted(?string $value): ?string
    {
        if (null === $value || '' === $value || $this->isEncrypted($value)) {
            return $value;
        }

        return $this->encrypter->encrypt($value);
    }

    private function decrypted(?string $value): ?string
    {
        if (null === $value || '' === $value || !$this->isEncrypted($value)) {
            return $value;
        }

        return $this->encrypter->decrypt($value);
    }
}
