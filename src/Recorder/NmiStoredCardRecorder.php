<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Recorder;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Repository\NmiStoredCardRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Resource\Factory\FactoryInterface;

final class NmiStoredCardRecorder implements NmiStoredCardRecorderInterface
{
    /** @param FactoryInterface<NmiStoredCardInterface> $storedCardFactory */
    public function __construct(
        private readonly FactoryInterface $storedCardFactory,
        private readonly NmiStoredCardRepositoryInterface $storedCardRepository,
        private readonly ObjectManager $manager,
    ) {
    }

    public function record(
        CustomerInterface $customer,
        PaymentMethodInterface $paymentMethod,
        NmiResponse $response,
    ): ?NmiStoredCardInterface {
        $vaultId = $response->customerVaultId;
        $card = $response->card;

        // The gateway returns the vault key on every charge and leaves it empty unless it kept
        // the card, so an approval that stored nothing arrives here looking exactly like this.
        if (null === $vaultId || null === $card) {
            return null;
        }

        // The last word on "no second row for the same card", and the only one that holds when
        // the browser could not describe the card in advance. A heuristic, never a constraint:
        // two genuinely different cards can share a brand, four digits and an expiry.
        $existing = $this->storedCardRepository->findOneDuplicate(
            $customer,
            $paymentMethod,
            $card->brand,
            $card->lastFour,
            $card->expiryMonth,
            $card->expiryYear,
        );

        if (null !== $existing) {
            return $existing;
        }

        $storedCard = $this->storedCardFactory->createNew();
        $storedCard->setCustomer($customer);
        // A vault record lives inside one NMI account, so which payment method stored it is part
        // of what the card *is*, not a convenience: another account cannot charge it.
        $storedCard->setPaymentMethod($paymentMethod);
        $storedCard->setVaultId($vaultId);
        // The charge that stored the card is the one worth citing when it is used again. A card
        // added from the account area has none, which is why the column is nullable.
        $storedCard->setVaultingTransactionId($response->transactionId);
        // The billing identifier is deliberately not filled here: the vaulting charge does not
        // return it, nothing this plugin does reads it, and fetching it would be a second call.
        $storedCard->setBrand($card->brand);
        $storedCard->setLastFour($card->lastFour);
        $storedCard->setExpiryMonth($card->expiryMonth);
        $storedCard->setExpiryYear($card->expiryYear);

        $this->manager->persist($storedCard);

        return $storedCard;
    }
}
