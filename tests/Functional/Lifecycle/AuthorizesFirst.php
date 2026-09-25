<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Lifecycle;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;

/**
 * An order placed on a method that authorises first, as checkout leaves it: the payment
 * authorized, the approved authorisation on record, the order new and waiting to be captured —
 * built here rather than found, because continuous integration starts from an empty database.
 */
trait AuthorizesFirst
{
    abstract protected function paymentRequestManager(): EntityManagerInterface;

    /** @return array{OrderInterface, PaymentInterface} */
    private function anAuthorizedOrder(string $authorization): array
    {
        // A checked-out order has a customer, and the order page cannot be drawn without one.
        /** @var CustomerInterface $customer */
        $customer = self::getContainer()->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('authorized+%s@example.com', bin2hex(random_bytes(4))));
        $this->paymentRequestManager()->persist($customer);

        $payment = $this->newPaymentRequest(
            PaymentRequestInterface::STATE_COMPLETED,
            PaymentRequestInterface::ACTION_AUTHORIZE,
            useAuthorize: true,
            customer: $customer,
        )->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $payment->setState(PaymentInterface::STATE_AUTHORIZED);

        /** @var NmiTransactionRecorderInterface $recorder */
        $recorder = self::getContainer()->get('test.jpm_martin_sylius_nmi.recorder.transaction');
        $recorder->record($payment, self::anApproved($authorization), NmiTransactionInterface::TYPE_AUTH);

        $order = $payment->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
        $order->setCheckoutCompletedAt(new \DateTime());
        $order->setPaymentState(OrderPaymentStates::STATE_AUTHORIZED);
        $order->setNumber('A' . random_int(100000000, 999999999));
        $order->setTokenValue('nmi_authorized_' . bin2hex(random_bytes(4)));
        $this->paymentRequestManager()->flush();

        return [$order, $payment];
    }

    /**
     * Handles what was queued on `main` and not handled yet, as a worker would: through the bus the
     * message was sent from, marked as received so it is handled rather than sent again.
     */
    private function runTheQueuedWork(): void
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.main');
        /** @var MessageBusInterface $bus */
        $bus = self::getContainer()->get('messenger.routable_message_bus');

        foreach ($transport->get() as $envelope) {
            $bus->dispatch($envelope->with(new ReceivedStamp('main')));
            $transport->ack($envelope);
        }
    }

    private function assertTheAuthorizationWasVoidedOnce(FakeNmiClient $gateway, PaymentInterface $payment, string $authorization): void
    {
        self::assertSame([$authorization], $gateway->voidedTransactionIds, 'NMI did not receive exactly one void of the authorisation.');

        /** @var NmiTransactionRepositoryInterface $transactions */
        $transactions = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_transaction');
        $void = $transactions->findOneByTransactionIdAndType($authorization, NmiTransactionInterface::TYPE_VOID);
        self::assertNotNull($void, 'The void is not recorded.');
        self::assertSame($payment->getId(), $void->getPayment()?->getId(), 'The void is recorded against another payment.');
    }

    private static function anApproved(string $transactionId): NmiResponse
    {
        return NmiResponse::fromBody(json_encode([
            'object' => 'transaction',
            'id' => $transactionId,
            'amount' => '109.51',
            'currency' => 'USD',
            'status' => 'pending',
            'response' => '1',
            'response_text' => 'SUCCESS',
            'response_code' => '100',
            'auth_code' => '123456',
        ], \JSON_THROW_ON_ERROR));
    }
}
