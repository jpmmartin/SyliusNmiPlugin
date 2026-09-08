<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Encryption;

use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use Sylius\Component\Payment\Encryption\EncrypterInterface;
use Sylius\Component\Payment\Encryption\EncryptionAwareInterface;
use Sylius\Component\Payment\Encryption\EncryptionCheckTrait;
use Sylius\Component\Payment\Encryption\EntityEncrypterInterface;

/**
 * Encrypts the three gateway identifiers on a stored card and nothing else.
 *
 * This plugin contains no cryptography: the platform's own encrypter does the work and this only
 * says which fields it applies to. The brand, the last four digits and the expiry stay in the
 * clear deliberately — the account listing and the duplicate check query them, and encrypting them
 * would mean decrypting every row to render a page or to answer "do you already have this card".
 * They are also what is already printed on a receipt.
 *
 * @implements EntityEncrypterInterface<NmiStoredCardInterface>
 */
final readonly class NmiStoredCardEncrypter implements EntityEncrypterInterface
{
    use EncryptionCheckTrait;

    public function __construct(
        private EncrypterInterface $encrypter,
    ) {
    }

    public function encrypt(EncryptionAwareInterface $resource): void
    {
        if (!$resource instanceof NmiStoredCardInterface) {
            return;
        }

        $resource->setVaultId($this->encrypted($resource->getVaultId()));
        $resource->setBillingId($this->encrypted($resource->getBillingId()));
        $resource->setVaultingTransactionId($this->encrypted($resource->getVaultingTransactionId()));
    }

    public function decrypt(EncryptionAwareInterface $resource): void
    {
        if (!$resource instanceof NmiStoredCardInterface) {
            return;
        }

        $resource->setVaultId($this->decrypted($resource->getVaultId()));
        $resource->setBillingId($this->decrypted($resource->getBillingId()));
        $resource->setVaultingTransactionId($this->decrypted($resource->getVaultingTransactionId()));
    }

    /**
     * Encrypting twice would be unreadable afterwards, and the listener decrypts in memory after a
     * flush, so an entity can reach this method already carrying ciphertext.
     */
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
