<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandHandler;

use JpmMartin\SyliusNmiPlugin\Command\NotifyCardholder;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Mailer\NmiEmails;
use JpmMartin\SyliusNmiPlugin\Repository\NmiStoredCardRepositoryInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * Sends the one email this plugin sends.
 *
 * **The locale travels with the message.** A worker handles this outside any request, so there is
 * no locale to inherit and the translator would fall back to the application's default — which for
 * a shopper who reads Spanish means a payment email in English. It is captured where the event
 * arrived and restored here, and put back afterwards so the worker's next message is unaffected.
 *
 * @internal
 */
final class NotifyCardholderHandler
{
    public function __construct(
        private readonly NmiStoredCardRepositoryInterface $storedCardRepository,
        private readonly SenderInterface $sender,
        private readonly LocaleAwareInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(NotifyCardholder $command): void
    {
        /** @var NmiStoredCardInterface|null $card */
        $card = $this->storedCardRepository->find($command->storedCardId);

        // Deleted between the event arriving and this being handled. Logged and ignored: the
        // shopper removed the card themselves, and telling them about a card they no longer have
        // would be worse than telling them nothing.
        if (null === $card) {
            $this->logger->info('The stored card an NMI cardholder email was about is gone; nothing sent.', [
                'stored_card_id' => $command->storedCardId,
            ]);

            return;
        }

        $email = $card->getCustomer()?->getEmail();
        if (null === $email || '' === $email) {
            $this->logger->info('The customer an NMI cardholder email was about has no address; nothing sent.', [
                'stored_card_id' => $command->storedCardId,
            ]);

            return;
        }

        // The platform's email layout renders the store's own header from the channel, so the
        // email cannot be built without one. It comes from the card's payment method, which is the
        // only channel a stored card is attached to at all.
        $channel = self::channelOf($card);
        if (null === $channel) {
            $this->logger->info('The stored card an NMI cardholder email was about belongs to no channel; nothing sent.', [
                'stored_card_id' => $command->storedCardId,
            ]);

            return;
        }

        $previous = $this->translator->getLocale();
        $this->translator->setLocale($command->localeCode);

        try {
            $this->sender->send(NmiEmails::STORED_CARD_ATTENTION, [$email], [
                'card' => $card,
                'status' => $command->status,
                'channel' => $channel,
                'localeCode' => $command->localeCode,
            ]);
        } finally {
            $this->translator->setLocale($previous);
        }
    }

    private static function channelOf(NmiStoredCardInterface $card): ?ChannelInterface
    {
        $paymentMethod = $card->getPaymentMethod();
        if (null === $paymentMethod) {
            return null;
        }

        foreach ($paymentMethod->getChannels() as $channel) {
            if ($channel instanceof ChannelInterface) {
                return $channel;
            }
        }

        return null;
    }
}
