<?php

declare(strict_types=1);

namespace AIArmada\Customers\Concerns;

use AIArmada\Customers\Enums\AddressType;
use AIArmada\Customers\Models\Customer;

trait SynchronizesCustomerAddresses
{
    /**
     * @param  array<string, mixed>  $billingData
     * @param  array<string, mixed>  $shippingData
     */
    private function syncAddressesFromPayload(
        Customer $customer,
        array $billingData,
        array $shippingData,
    ): void {
        $this->createAddress($customer, $billingData, AddressType::Billing, true, false);
        $this->createAddress($customer, $shippingData, AddressType::Shipping, false, true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createAddress(
        Customer $customer,
        array $data,
        AddressType $type,
        bool $setDefaultBilling,
        bool $setDefaultShipping,
    ): void {
        $payload = $this->normalizeAddressPayload($customer, $data, $type, $setDefaultBilling, $setDefaultShipping);

        if ($payload === null || $this->hasMatchingAddress($customer, $payload)) {
            return;
        }

        $customer->legacyAddresses()->create($payload);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function normalizeAddressPayload(
        Customer $customer,
        array $data,
        AddressType $type,
        bool $setDefaultBilling,
        bool $setDefaultShipping,
    ): ?array {
        $line1 = $this->resolveAddressField($data, ['line1']);
        $city = $this->resolveAddressField($data, ['city', 'town']);
        $postcode = $this->resolveAddressField($data, ['postcode', 'zip']);
        $country = $this->resolveAddressField($data, ['country', 'country_code']);

        if ($line1 === null || $city === null || $postcode === null || $country === null) {
            return null;
        }

        $line2 = $this->resolveAddressField($data, ['line2']);
        $state = $this->resolveAddressField($data, ['state', 'province', 'region']);

        $defaultBilling = $setDefaultBilling && ! $customer->legacyAddresses()->where('is_default_billing', true)->exists();
        $defaultShipping = $setDefaultShipping && ! $customer->legacyAddresses()->where('is_default_shipping', true)->exists();

        return [
            'type' => $type->value,
            'label' => $this->cleanString($data['label'] ?? null),
            'recipient_name' => $this->resolveRecipientName($data),
            'company' => $this->cleanString($data['company'] ?? null),
            'line1' => $line1,
            'line2' => $line2,
            'city' => $city,
            'state' => $state,
            'postcode' => $postcode,
            'country_code' => mb_strtoupper($country),
            'is_default_billing' => $defaultBilling,
            'is_default_shipping' => $defaultShipping,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasMatchingAddress(Customer $customer, array $payload): bool
    {
        $query = $customer->legacyAddresses()
            ->where('type', $payload['type'])
            ->where('line1', $payload['line1'])
            ->where('city', $payload['city'])
            ->where('postcode', $payload['postcode'])
            ->where('country_code', $payload['country_code']);

        if ($payload['line2'] === null) {
            $query->whereNull('line2');
        } else {
            $query->where('line2', $payload['line2']);
        }

        if ($payload['state'] === null) {
            $query->whereNull('state');
        } else {
            $query->where('state', $payload['state']);
        }

        return $query->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveRecipientName(array $data): ?string
    {
        $firstName = $this->cleanString($data['first_name'] ?? null);
        $lastName = $this->cleanString($data['last_name'] ?? null);

        if ($firstName !== null || $lastName !== null) {
            return mb_trim(mb_trim((string) ($firstName ?? '') . ' ' . (string) ($lastName ?? '')));
        }

        $name = $this->cleanString($data['name'] ?? $data['full_name'] ?? null);

        return $name !== '' ? $name : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     */
    private function resolveAddressField(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $value = $this->cleanString($data[$key]);

                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }
}
