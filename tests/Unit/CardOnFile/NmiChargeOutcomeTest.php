<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\CardOnFile;

use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiChargeOutcome;
use PHPUnit\Framework\TestCase;

final class NmiChargeOutcomeTest extends TestCase
{
    public function testOnlyAnApprovalIsApproved(): void
    {
        self::assertTrue(NmiChargeOutcome::approved('12584746059')->isApproved());
        self::assertFalse(NmiChargeOutcome::declined('k', 'DECLINE')->isApproved());
        self::assertFalse(NmiChargeOutcome::refused('k')->isApproved());
        self::assertFalse(NmiChargeOutcome::unknown('k')->isApproved());
    }

    /** An unanswered charge is never reported as a decline: a caller who retried on one could charge twice. */
    public function testAnUnansweredChargeIsUnknownNotDeclined(): void
    {
        self::assertSame(NmiChargeOutcome::UNKNOWN, NmiChargeOutcome::unknown('k', 'Connection timed out')->status);
    }

    public function testItSurvivesTheRoundTripThroughAPaymentRequest(): void
    {
        foreach ([
            NmiChargeOutcome::approved('12584746059'),
            NmiChargeOutcome::declined('jpm_martin_sylius_nmi.payment.card_on_file_declined', 'DECLINE', '12584700002'),
            NmiChargeOutcome::declined('jpm_martin_sylius_nmi.payment.card_on_file_declined', 'DECLINE', '12584700002', 200),
            NmiChargeOutcome::refused('jpm_martin_sylius_nmi.payment.card_on_file_closed'),
            NmiChargeOutcome::unknown('jpm_martin_sylius_nmi.payment.card_on_file_charge_unknown', 'Connection timed out'),
        ] as $outcome) {
            self::assertEquals($outcome, NmiChargeOutcome::fromArray($outcome->toArray()));
        }
    }

    /** The machine-readable half of a decline, beside the issuer's wording; absent where the gateway gave none. */
    public function testADeclineCarriesTheGatewaysCodeWhenItGaveOne(): void
    {
        self::assertSame(200, NmiChargeOutcome::declined('k', 'DECLINE', '12584700002', 200)->code);
        self::assertNull(NmiChargeOutcome::declined('k', 'Invalid expiration date')->code);
        self::assertNull(NmiChargeOutcome::approved('12584746059')->code);
    }

    /** Response data written before the code existed still reads back, as a decline with no code. */
    public function testAnOutcomeStoredWithoutACodeStillReadsBack(): void
    {
        $outcome = NmiChargeOutcome::fromArray(['outcome' => NmiChargeOutcome::DECLINED, 'message_key' => 'k', 'detail' => 'DECLINE', 'transaction_id' => '12584700002']);

        self::assertNotNull($outcome);
        self::assertNull($outcome->code);
        self::assertSame('DECLINE', $outcome->reason);
    }

    public function testResponseDataThatIsNoOutcomeReadsAsNone(): void
    {
        self::assertNull(NmiChargeOutcome::fromArray([]));
        self::assertNull(NmiChargeOutcome::fromArray(['outcome' => 'maybe', 'message_key' => 'k']));
        self::assertNull(NmiChargeOutcome::fromArray(['outcome' => NmiChargeOutcome::APPROVED]));
    }
}
