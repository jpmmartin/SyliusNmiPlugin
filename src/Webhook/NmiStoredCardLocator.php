<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Encryption\EncrypterInterface;
use Sylius\Component\Payment\Encryption\EncryptionCheckTrait;

/**
 * Finds the stored card a gateway event names, by a reference that **cannot be queried**.
 *
 * The vault reference is encrypted at rest, deliberately, and the platform's encrypter is not
 * deterministic — the same value encrypts differently every time, which was measured rather than
 * assumed. So `WHERE vault_id = ?` matches nothing, and neither does encrypting the search term:
 * there is no ciphertext to compare against.
 *
 * What is left is to bring the candidates back and decrypt them. This does that as cheaply as it
 * can: a **scalar** query for two columns rather than a hydration of whole entities, decryption of
 * plain strings, and then a single load of the one row that matched. Nothing but the match becomes
 * an entity.
 *
 * **The cost grows with how many cards a store has saved**, and that is the honest limit of this
 * approach. It is affordable because of what it is for: card-updater summaries arrive at most once
 * a day, and the whole summary is resolved against one query. It would stop being affordable for a
 * store with a very large vault, and the answer then is an indexed keyed fingerprint beside the
 * ciphertext — a column, a secret and a backfill, which is a price worth paying only once the
 * scanning actually hurts.
 */
final class NmiStoredCardLocator implements NmiStoredCardLocatorInterface
{
    use EncryptionCheckTrait;

    /** @param class-string $cardClass */
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly EncrypterInterface $encrypter,
        private readonly string $cardClass,
    ) {
    }

    public function locate(string $vaultId, PaymentMethodInterface $paymentMethod): ?NmiStoredCardInterface
    {
        foreach ($this->candidates($paymentMethod) as $candidate) {
            if (!hash_equals($this->readable((string) $candidate['vaultId']), $vaultId)) {
                continue;
            }

            /** @var NmiStoredCardInterface|null $card */
            $card = $this->manager->find($this->cardClass, $candidate['id']);

            return $card;
        }

        return null;
    }

    /**
     * Identifier and ciphertext for every card filed against this payment method.
     *
     * Scoped to the method rather than the whole table because the reference belongs to one
     * gateway account, and two accounts could in principle mint the same one. It is also the only
     * narrowing available: nothing else in the event identifies the owner.
     *
     * @return list<array{id: int, vaultId: string|null}>
     */
    private function candidates(PaymentMethodInterface $paymentMethod): array
    {
        /** @var list<array{id: int, vaultId: string|null}> $rows */
        $rows = $this->manager->createQuery(
            sprintf('SELECT c.id, c.vaultId FROM %s c WHERE c.paymentMethod = :paymentMethod', $this->cardClass),
        )->setParameter('paymentMethod', $paymentMethod)->getArrayResult();

        return $rows;
    }

    /**
     * A scalar query returns what the column holds, so the decryption the ORM does on an entity
     * has not happened here. A value that is not encrypted is returned as it is — a store may have
     * rows written before the encryption existed, and refusing to read them would lose the card.
     */
    private function readable(string $value): string
    {
        return $this->isEncrypted($value) ? $this->encrypter->decrypt($value) : $value;
    }
}
