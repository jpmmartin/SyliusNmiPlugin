<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Form\Type;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Bundle\PaymentBundle\Attribute\AsGatewayConfigurationType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Embedded by Sylius under `gatewayConfig.config` of the payment method form. The data is the
 * plain `config` array of the GatewayConfig, so there is no data class and every child maps to
 * an array key. Constraints live here, in the `sylius` validation group Sylius applies to the
 * gateway configuration, because an array has no validation metadata of its own.
 */
#[AsGatewayConfigurationType(type: NmiGatewayFactory::NAME, label: 'jpm_martin_sylius_nmi.gateway_factory.nmi')]
final class NmiGatewayConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(NmiGatewayFactory::CONFIG_TOKENIZATION_KEY, TextType::class, [
                'label' => 'jpm_martin_sylius_nmi.form.gateway_config.tokenization_key',
                'help' => 'jpm_martin_sylius_nmi.form.gateway_config.tokenization_key_help',
                'constraints' => [
                    new NotBlank(message: 'jpm_martin_sylius_nmi.gateway_config.tokenization_key.not_blank', groups: ['sylius']),
                ],
            ])
            ->add(NmiGatewayFactory::CONFIG_SECURITY_KEY, TextType::class, [
                'label' => 'jpm_martin_sylius_nmi.form.gateway_config.security_key',
                'help' => 'jpm_martin_sylius_nmi.form.gateway_config.security_key_help',
                'constraints' => [
                    new NotBlank(message: 'jpm_martin_sylius_nmi.gateway_config.security_key.not_blank', groups: ['sylius']),
                ],
            ])
            ->add(NmiGatewayFactory::CONFIG_ENVIRONMENT, ChoiceType::class, [
                'label' => 'jpm_martin_sylius_nmi.form.gateway_config.environment',
                'help' => 'jpm_martin_sylius_nmi.form.gateway_config.environment_help',
                'placeholder' => 'jpm_martin_sylius_nmi.form.gateway_config.environments.placeholder',
                'choices' => [
                    'jpm_martin_sylius_nmi.form.gateway_config.environments.production' => NmiGatewayFactory::ENVIRONMENT_PRODUCTION,
                    'jpm_martin_sylius_nmi.form.gateway_config.environments.sandbox' => NmiGatewayFactory::ENVIRONMENT_SANDBOX,
                ],
                'constraints' => [
                    new NotBlank(message: 'jpm_martin_sylius_nmi.gateway_config.environment.not_blank', groups: ['sylius']),
                    new Choice(choices: NmiGatewayFactory::ENVIRONMENTS, message: 'jpm_martin_sylius_nmi.gateway_config.environment.invalid', groups: ['sylius']),
                ],
            ])
            ->add(NmiGatewayFactory::CONFIG_USE_AUTHORIZE, CheckboxType::class, [
                'label' => 'jpm_martin_sylius_nmi.form.gateway_config.use_authorize',
                'help' => 'jpm_martin_sylius_nmi.form.gateway_config.use_authorize_help',
                'required' => false,
            ])
            // Off is the feature absent, not merely dormant: nothing is offered, nothing is
            // listed and nothing is stored. That is what lets a store install this plugin and
            // see exactly what it saw before.
            ->add(NmiGatewayFactory::CONFIG_STORE_CARDS, CheckboxType::class, [
                'label' => 'jpm_martin_sylius_nmi.form.gateway_config.store_cards',
                'help' => 'jpm_martin_sylius_nmi.form.gateway_config.store_cards_help',
                'required' => false,
            ])
            // Only meaningful once cards are stored at all, which is why it reads as a follow-up
            // question rather than a fifth independent switch.
            ->add(NmiGatewayFactory::CONFIG_AUTHENTICATE_STORED_CARDS, CheckboxType::class, [
                'label' => 'jpm_martin_sylius_nmi.form.gateway_config.authenticate_stored_cards',
                'help' => 'jpm_martin_sylius_nmi.form.gateway_config.authenticate_stored_cards_help',
                'help_html' => true,
                'required' => false,
            ])
            // Only reachable once webhooks are wired, because nothing else tells the store that a
            // card was closed. Rendered anyway rather than hidden behind the signing key: a
            // setting that appears and disappears is harder to reason about than one that is
            // simply off.
            ->add(NmiGatewayFactory::CONFIG_EMAIL_CARDHOLDER, CheckboxType::class, [
                'label' => 'jpm_martin_sylius_nmi.form.gateway_config.email_cardholder',
                'help' => 'jpm_martin_sylius_nmi.form.gateway_config.email_cardholder_help',
                'required' => false,
            ])
            // Off by default and deliberately so: on an account shared with another store this
            // would fire on every one of that store's transactions.
            ->add(NmiGatewayFactory::CONFIG_NOTIFY_UNKNOWN_TRANSACTIONS, CheckboxType::class, [
                'label' => 'jpm_martin_sylius_nmi.form.gateway_config.notify_unknown_transactions',
                'help' => 'jpm_martin_sylius_nmi.form.gateway_config.notify_unknown_transactions_help',
                'required' => false,
            ])
            // Optional, and last because it is the only field that is about the gateway talking to
            // the store rather than the other way round. Leaving it blank is a complete answer: no
            // key, no events accepted.
            ->add(NmiGatewayFactory::CONFIG_WEBHOOK_SIGNING_KEY, TextType::class, [
                'label' => 'jpm_martin_sylius_nmi.form.gateway_config.webhook_signing_key',
                'help' => 'jpm_martin_sylius_nmi.form.gateway_config.webhook_signing_key_help',
                'required' => false,
            ])
        ;

        // The provider reads an absent key as "yes", and the form has to agree with it or the
        // agreement is worthless: a checkbox rendered unchecked posts nothing, which Symfony
        // stores as an explicit false. Every payment method saved through this form would then
        // carry the answer nobody gave — and the "silence means authenticate" reading would only
        // ever apply to configurations written before the field existed.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $config = $event->getData();

            if (!is_array($config) || array_key_exists(NmiGatewayFactory::CONFIG_AUTHENTICATE_STORED_CARDS, $config)) {
                return;
            }

            // Only when the key is absent, so a store that deliberately turned it off keeps it off.
            $config[NmiGatewayFactory::CONFIG_AUTHENTICATE_STORED_CARDS] = true;

            $event->setData($config);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('data_class', null);
    }

    public function getBlockPrefix(): string
    {
        return 'jpm_martin_sylius_nmi_gateway_configuration';
    }
}
