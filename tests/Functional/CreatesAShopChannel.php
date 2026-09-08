<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Model\Locale;

/**
 * A channel the storefront will actually resolve, built rather than found.
 *
 * **Continuous integration migrates an empty database and loads no fixtures**, so a test that took
 * whichever channel existed found none and every shop page answered *"Channel could not be
 * found!"*. It passed on a developer's machine only because a channel happened to be lying around
 * from something else — which is the worst way for a suite to be green.
 *
 * The hostname is what makes it resolve: Sylius picks the channel by the host of the request, and
 * the test client asks for `localhost`. Find-or-create rather than create, because two channels
 * answering to the same hostname would make the choice between them arbitrary.
 */
trait CreatesAShopChannel
{
    private function aShopChannel(): ChannelInterface
    {
        $manager = $this->shopChannelManager();

        /** @var ChannelInterface|null $existing */
        $existing = $manager->getRepository(Channel::class)->findOneBy(['hostname' => 'localhost']);
        if (null !== $existing) {
            return $existing;
        }

        $currency = $manager->getRepository(Currency::class)->findOneBy(['code' => 'USD']) ?? new Currency();
        $currency->setCode('USD');
        $manager->persist($currency);

        $locale = $manager->getRepository(Locale::class)->findOneBy(['code' => 'en_US']) ?? new Locale();
        $locale->setCode('en_US');
        $manager->persist($locale);

        $channel = new Channel();
        $channel->setCode('nmi_test_' . bin2hex(random_bytes(4)));
        $channel->setName('NMI test channel');
        $channel->setHostname('localhost');
        $channel->setBaseCurrency($currency);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $channel->addCurrency($currency);
        $channel->setEnabled(true);
        $channel->setTaxCalculationStrategy('order_items_based');
        $manager->persist($channel);
        $manager->flush();

        return $channel;
    }

    abstract protected function shopChannelManager(): EntityManagerInterface;
}
