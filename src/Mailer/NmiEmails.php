<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Mailer;

/**
 * The one email this plugin sends, named once so the code and the configuration cannot drift.
 */
final class NmiEmails
{
    /** A saved card the issuer closed, or asked that the cardholder be contacted about. */
    public const STORED_CARD_ATTENTION = 'jpm_martin_sylius_nmi_stored_card_attention';

    private function __construct()
    {
    }
}
