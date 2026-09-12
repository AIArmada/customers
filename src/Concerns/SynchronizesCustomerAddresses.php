<?php

declare(strict_types=1);

namespace AIArmada\Customers\Concerns;

use AIArmada\Addressing\Data\AddressData;
use AIArmada\Addressing\Models\Address;
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
        $this->createAddress($customer, $billingData, AddressType::Billing, true);
        $this->createAddress($customer, $shippingData, AddressType::Shipping, true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createAddress(
        Customer $customer,
        array $data,
        AddressType $type,
        bool $setAsPrimary,
    ): void {
        $payload = $this->normalizeAddressPayload($data);

        if ($payload === null) {
            return;
        }

        $matchingAddress = $this->findMatchingAddress($customer, $payload, $type);

        if ($matchingAddress instanceof Address) {
            if ($setAsPrimary && $customer->primaryAddress($type->value) === null) {
                $customer->setPrimaryAddress($matchingAddress, type: $type->value);
            }

            return;
        }

        $address = Address::create($payload->toModelAttributes());

        $customer->attachAddress(
            address: $address,
            type: $type->value,
            isPrimary: $setAsPrimary && $customer->primaryAddress($type->value) === null,
            label: $payload->label,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function normalizeAddressPayload(array $data): ?AddressData
    {
        $line1 = $this->resolveAddressField($data, ['line1']);
        $city = $this->resolveAddressField($data, ['city', 'town']);
        $postcode = $this->resolveAddressField($data, ['postcode', 'zip']);
        $country = $this->resolveAddressField($data, ['country', 'country_code']);

        if ($line1 === null || $city === null || $postcode === null || $country === null) {
            return null;
        }

        $line2 = $this->resolveAddressField($data, ['line2']);
        $state = $this->resolveAddressField($data, ['state', 'province', 'region']);
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $metadata['recipient_name'] = $this->resolveRecipientName($data);
        $metadata['company'] = $this->cleanString($data['company'] ?? null);

        return AddressData::from([
            'label' => $this->cleanString($data['label'] ?? null),
            'line1' => $line1,
            'line2' => $line2,
            'city' => $city,
            'state' => $state,
            'postcode' => $postcode,
            'countryCode' => mb_strtoupper($country),
            'metadata' => $metadata,
        ]);
    }

    private function findMatchingAddress(Customer $customer, AddressData $payload, AddressType $type): ?Address
    {
        $query = $customer->addresses()
            ->wherePivot('type', $type->value)
            ->where('line1', $payload->line1)
            ->where('city', $payload->city)
            ->where('postcode', $payload->postcode)
            ->where('country_code', $payload->countryCode);

        if ($payload->line2 === null) {
            $query->whereNull('line2');
        } else {
            $query->where('line2', $payload->line2);
        }

        if ($payload->state === null) {
            $query->whereNull('state');
        } else {
            $query->where('state', $payload->state);
        }

        return $query->first();
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
