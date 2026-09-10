<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Command\NotifyCardholder;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiCardDetails;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Acts on what the card networks told the gateway about cards this store holds.
 *
 * **These are summaries, not notices.** The gateway does not report a card; it reports a day's
 * worth of them, in arrays, most of which belong to other stores on a shared account. So every
 * entry is resolved on its own and one that resolves to nothing does not stop the rest — the
 * unrecognised ones are the majority and are the ordinary case.
 *
 * **The parallel `recurring_*` arrays are skipped rather than resolved.** They are keyed by
 * `subscription_id`, this plugin creates no subscriptions, and treating them as unresolvable would
 * make every delivery look like a fault.
 *
 * **A renewal can replace the card number, not only the expiry.** The gateway sends two arrays for
 * that reason, and the shopper has to recognise the card in their account afterwards, so the
 * stored last four digits move with the expiry.
 *
 * @internal
 */
final class NmiCardUpdateApplier implements NmiCardUpdateApplierInterface
{
    /**
     * Which arrays each summary carries its vault entries in, and what the entries mean.
     *
     * The two arrays on a renewal differ in what changed — the number, or only the expiry — and
     * are treated identically here: whatever the entry says the card now is, the card now is.
     *
     * @var array<string, array{arrays: list<string>, status: string|null}>
     */
    private const SUMMARIES = [
        'acu.summary.automaticallyupdated' => [
            'arrays' => ['vault_updated_cards', 'vault_updated_expiration_dates'],
            'status' => NmiStoredCardInterface::STATUS_ACTIVE,
        ],
        'acu.summary.closedaccount' => [
            'arrays' => ['vault_updates'],
            'status' => NmiStoredCardInterface::STATUS_CLOSED,
        ],
        'acu.summary.contactcustomer' => [
            'arrays' => ['vault_updates'],
            'status' => NmiStoredCardInterface::STATUS_NEEDS_ATTENTION,
        ],
    ];

    public function __construct(
        private readonly NmiStoredCardLocatorInterface $storedCardLocator,
        private readonly EntityManagerInterface $manager,
        private readonly NmiGatewayConfigurationProviderInterface $configurationProvider,
        private readonly MessageBusInterface $eventBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string $eventType): bool
    {
        return array_key_exists($eventType, self::SUMMARIES);
    }

    public function apply(NmiWebhookEnvelope $envelope, PaymentMethodInterface $paymentMethod): void
    {
        $summary = self::SUMMARIES[$envelope->eventType] ?? null;
        if (null === $summary) {
            return;
        }

        $seen = 0;
        /** @var list<NmiStoredCardInterface> $touched */
        $touched = [];

        foreach ($summary['arrays'] as $arrayName) {
            foreach (self::entriesIn($envelope, $arrayName) as $entry) {
                ++$seen;

                $card = $this->applyEntry($entry, $summary['status'], $paymentMethod);
                if (null !== $card) {
                    $touched[] = $card;
                }
            }
        }

        $applied = count($touched);

        if ($applied > 0) {
            $this->manager->flush();
            // After the flush, so a card that failed to save is not a card somebody was emailed
            // about, and so the identifier the message carries is one that exists.
            $this->tellTheCardholders($touched, $summary['status'], $paymentMethod);
        }

        $this->logger->info('Applied an NMI card updater summary.', [
            'event_id' => $envelope->eventId,
            'event_type' => $envelope->eventType,
            'named' => $seen,
            'applied' => $applied,
        ]);
    }

    /**
     * One entry, and whether it changed anything here.
     *
     * A card this store does not hold is the ordinary outcome and is not logged individually: a
     * shared account produces hundreds of them and the log would be the summary all over again.
     *
     * @param array<string, mixed> $entry
     */
    private function applyEntry(array $entry, ?string $status, PaymentMethodInterface $paymentMethod): ?NmiStoredCardInterface
    {
        $vaultId = self::text($entry['customer_vault_id'] ?? null);
        if (null === $vaultId) {
            return null;
        }

        $card = $this->storedCardLocator->locate($vaultId, $paymentMethod);
        if (null === $card) {
            return null;
        }

        if (null !== $status) {
            $card->setStatus($status);
        }

        // The number and the expiry, when the entry carries them. A closure names them too, and
        // taking them costs nothing: a card that cannot be used is still a card the shopper should
        // recognise in the list where it now says it is unusable.
        $details = NmiCardDetails::fromPaymentDetails(
            ['card_number' => $entry['cc_number'] ?? null, 'card_exp' => $entry['cc_exp'] ?? null],
            $card->getBrand(),
        );

        if (null !== $details) {
            $card->setLastFour($details->lastFour);
            $card->setExpiryMonth($details->expiryMonth);
            $card->setExpiryYear($details->expiryYear);
        }

        $card->setUpdatedAt(new \DateTimeImmutable());

        return $card;
    }

    /**
     * The email, when the operator asked for one and there is something worth saying.
     *
     * **A renewal is not worth saying.** The card the shopper saved still works, with a date they
     * never knew — telling them about it would be mail about nothing. Only a closure and a flag
     * earn one, which is what the requirement names.
     *
     * @param list<NmiStoredCardInterface> $cards
     */
    private function tellTheCardholders(array $cards, ?string $status, PaymentMethodInterface $paymentMethod): void
    {
        if (NmiStoredCardInterface::STATUS_CLOSED !== $status && NmiStoredCardInterface::STATUS_NEEDS_ATTENTION !== $status) {
            return;
        }

        if (!$this->configurationProvider->fromPaymentMethod($paymentMethod)->emailCardholder) {
            return;
        }

        // Captured here because a worker has no request and therefore no locale. The channel's
        // own is the closest thing to the shopper's that a card carries.
        $localeCode = self::localeOf($paymentMethod);

        foreach ($cards as $card) {
            $id = $card->getId();
            if (null === $id) {
                continue;
            }

            $this->eventBus->dispatch(new NotifyCardholder($id, (string) $status, $localeCode));
        }
    }

    /** The locale the email is written in, taken from the first channel this method serves. */
    private static function localeOf(PaymentMethodInterface $paymentMethod): string
    {
        foreach ($paymentMethod->getChannels() as $channel) {
            // The payment method's collection is typed to the narrower channel model, which knows
            // nothing about locales; only a core channel has a default one.
            if (!$channel instanceof ChannelInterface) {
                continue;
            }

            $locale = $channel->getDefaultLocale()?->getCode();
            if (null !== $locale && '' !== $locale) {
                return $locale;
            }
        }

        return 'en_US';
    }

    /**
     * The entries of one named array, skipping anything that is not an entry.
     *
     * @return list<array<string, mixed>>
     */
    private static function entriesIn(NmiWebhookEnvelope $envelope, string $arrayName): array
    {
        $entries = $envelope->eventBody[$arrayName] ?? null;
        if (!is_array($entries)) {
            return [];
        }

        $found = [];
        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $found[] = $entry;
            }
        }

        return $found;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
