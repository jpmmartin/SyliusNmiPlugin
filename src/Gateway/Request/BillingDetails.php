<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway\Request;

/**
 * Cardholder details sent with a charge under `billing_address`; every field is optional and
 * null means "do not send". Omitting is not the same as sending an empty value here — the
 * gateway rejects a body carrying fields it does not expect.
 */
final class BillingDetails
{
    public function __construct(
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $company = null,
        public readonly ?string $address1 = null,
        public readonly ?string $address2 = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
        public readonly ?string $zip = null,
        public readonly ?string $country = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
    ) {
    }

    /** @return array<string, string> Gateway field name to value, without the empty ones */
    public function toArray(): array
    {
        return array_filter([
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'company' => $this->company,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'city' => $this->city,
            'state' => $this->state,
            'zip' => $this->zip,
            'country' => $this->country,
            'phone' => $this->phone,
            'email' => $this->email,
        ], static fn (?string $value): bool => null !== $value && '' !== $value);
    }
}
