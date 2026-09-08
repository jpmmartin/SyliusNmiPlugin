<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A form with no fields, and that is the whole point.
 *
 * Choosing a default is not editing a card: there is nothing for the shopper to fill in, only an
 * intent to express. The form exists so the platform's resource controller has something to bind
 * and so the request carries a CSRF token; what it means is decided by the listener on the update
 * event, not by a field the shopper could set to the wrong value.
 */
final class NmiStoredCardDefaultType extends AbstractType
{
    public const CSRF_TOKEN_ID = 'jpm_martin_sylius_nmi_stored_card_default';

    /** @param class-string $dataClass */
    public function __construct(
        private readonly string $dataClass,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => $this->dataClass,
            // Named explicitly so the listing page can emit the token without building a form view
            // for every card it renders.
            'csrf_token_id' => self::CSRF_TOKEN_ID,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return self::CSRF_TOKEN_ID;
    }
}
