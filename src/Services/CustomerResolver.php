<?php

declare(strict_types=1);

namespace AIArmada\Customers\Services;

use AIArmada\Customers\Enums\AddressType;
use AIArmada\Customers\Models\Address;
use AIArmada\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

final class CustomerResolver
{
    /**
     * @param  array<string, mixed>  $billingData
     * @param  array<string, mixed>  $shippingData
     */
    public function resolve(?Model $user, ?Customer $sessionCustomer, array $billingData, array $shippingData): ?Customer
    {
        $email = $this->resolveEmail($billingData, $shippingData, $user, $sessionCustomer);

        if ($user !== null) {
            $userCustomer = $this->resolveUserCustomer($user);

            if ($userCustomer !== null) {
                if ($sessionCustomer !== null && $sessionCustomer->is_guest && $sessionCustomer->id !== $userCustomer->id) {
                    $this->mergeCustomers($sessionCustomer, $userCustomer);
                }

                $this->syncAddressesFromPayload($userCustomer, $billingData, $shippingData);

                return $userCustomer;
            }

            if ($sessionCustomer !== null && $sessionCustomer->is_guest) {
                $sessionCustomer->update([
                    'user_id' => $user->getKey(),
                    'is_guest' => false,
                ]);

                $this->syncAddressesFromPayload($sessionCustomer, $billingData, $shippingData);

                return $sessionCustomer;
            }

            if ($email === null) {
                return null;
            }

            $customer = $this->createCustomer($email, $billingData, $shippingData, $user, false);
            $this->syncAddressesFromPayload($customer, $billingData, $shippingData);

            return $customer;
        }

        if ($sessionCustomer !== null) {
            $this->syncAddressesFromPayload($sessionCustomer, $billingData, $shippingData);

            return $sessionCustomer;
        }

        if ($email === null) {
            return null;
        }

        $existingCustomer = Customer::query()
            ->where('email', $email)
            ->first();

        if ($existingCustomer !== null) {
            $this->syncAddressesFromPayload($existingCustomer, $billingData, $shippingData);

            return $existingCustomer;
        }

        $customer = $this->createCustomer($email, $billingData, $shippingData, null, true);
        $this->syncAddressesFromPayload($customer, $billingData, $shippingData);

        return $customer;
    }

    public function mergeCustomers(Customer $source, Customer $target): Customer
    {
        $this->moveAddresses($source, $target);
        $this->mergeSegments($source, $target);
        $this->mergeGroups($source, $target);
        $this->moveNotes($source, $target);

        if (! empty($source->metadata) && empty($target->metadata)) {
            $target->metadata = $source->metadata;
            $target->save();
        }

        $source->delete();

        return $target;
    }

    private function resolveUserCustomer(Model $user): ?Customer
    {
        if (method_exists($user, 'customer')) {
            /** @var Relation|null $relation */
            $relation = $user->customer();

            if ($relation !== null) {
                $customer = $relation->getResults();

                if ($customer instanceof Customer) {
                    return $customer;
                }
            }
        }

        if (method_exists($user, 'customerProfile')) {
            /** @var Relation|null $relation */
            $relation = $user->customerProfile();

            if ($relation !== null) {
                $customer = $relation->getResults();

                if ($customer instanceof Customer) {
                    return $customer;
                }
            }
        }

        if (method_exists($user, 'getOrCreateCustomerProfile')) {
            $customer = $user->getOrCreateCustomerProfile();

            if ($customer instanceof Customer) {
                return $customer;
            }
        }

        $userId = $user->getKey();

        if ($userId === null) {
            return null;
        }

        return Customer::query()
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $billingData
     * @param  array<string, mixed>  $shippingData
     */
    private function createCustomer(
        string $email,
        array $billingData,
        array $shippingData,
        ?Model $user,
        bool $isGuest,
    ): Customer {
        [$firstName, $lastName] = $this->resolveNameParts($billingData, $shippingData, $user);

        $phone = $this->cleanString($billingData['phone'] ?? null)
            ?? $this->cleanString($shippingData['phone'] ?? null)
            ?? $this->cleanString($user?->getAttribute('phone'));
        $company = $this->cleanString($billingData['company'] ?? null)
            ?? $this->cleanString($shippingData['company'] ?? null);

        return Customer::create([
            'user_id' => $user?->getKey(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'company' => $company,
            'is_guest' => $isGuest,
        ]);
    }

    /**
     * @param  array<string, mixed>  $billingData
     * @param  array<string, mixed>  $shippingData
     */
    private function syncAddressesFromPayload(Customer $customer, array $billingData, array $shippingData): void
    {
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

        if ($payload === null) {
            return;
        }

        if ($this->hasMatchingAddress($customer, $payload)) {
            return;
        }

        $customer->addresses()->create($payload);
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

        $defaultBilling = $setDefaultBilling && ! $customer->addresses()->where('is_default_billing', true)->exists();
        $defaultShipping = $setDefaultShipping && ! $customer->addresses()->where('is_default_shipping', true)->exists();

        return [
            'type' => $type->value,
            'label' => $this->cleanString($data['label'] ?? null),
            'recipient_name' => $this->resolveRecipientName($data),
            'company' => $this->cleanString($data['company'] ?? null),
            'phone' => $this->cleanString($data['phone'] ?? null),
            'line1' => $line1,
            'line2' => $line2,
            'city' => $city,
            'state' => $state,
            'postcode' => $postcode,
            'country' => mb_strtoupper($country),
            'is_default_billing' => $defaultBilling,
            'is_default_shipping' => $defaultShipping,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasMatchingAddress(Customer $customer, array $payload): bool
    {
        $query = $customer->addresses()
            ->where('type', $payload['type'])
            ->where('line1', $payload['line1'])
            ->where('city', $payload['city'])
            ->where('postcode', $payload['postcode'])
            ->where('country', $payload['country']);

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

    private function moveAddresses(Customer $source, Customer $target): void
    {
        $targetDefaultBilling = $target->addresses()->where('is_default_billing', true)->exists();
        $targetDefaultShipping = $target->addresses()->where('is_default_shipping', true)->exists();

        $source->loadMissing('addresses');

        foreach ($source->addresses as $address) {
            if ($this->isDuplicateAddress($target, $address)) {
                $address->delete();

                continue;
            }

            if ($address->is_default_billing && $targetDefaultBilling) {
                $address->is_default_billing = false;
            }

            if ($address->is_default_shipping && $targetDefaultShipping) {
                $address->is_default_shipping = false;
            }

            $address->customer_id = $target->id;
            $address->save();
        }
    }

    private function isDuplicateAddress(Customer $customer, Address $address): bool
    {
        $query = $customer->addresses()
            ->where('type', $address->type->value)
            ->where('line1', $address->line1)
            ->where('city', $address->city)
            ->where('postcode', $address->postcode)
            ->where('country', $address->country);

        if ($address->line2 === null) {
            $query->whereNull('line2');
        } else {
            $query->where('line2', $address->line2);
        }

        if ($address->state === null) {
            $query->whereNull('state');
        } else {
            $query->where('state', $address->state);
        }

        return $query->exists();
    }

    private function mergeSegments(Customer $source, Customer $target): void
    {
        $segmentIds = $source->segments()
            ->pluck('customer_segments.id')
            ->all();

        if (! empty($segmentIds)) {
            $target->segments()->syncWithoutDetaching($segmentIds);
        }
    }

    private function mergeGroups(Customer $source, Customer $target): void
    {
        $groupIds = $source->groups()
            ->pluck('customer_groups.id')
            ->all();

        if (! empty($groupIds)) {
            $target->groups()->syncWithoutDetaching($groupIds);
        }
    }

    private function moveNotes(Customer $source, Customer $target): void
    {
        $source->notes()->update(['customer_id' => $target->id]);
    }

    /**
     * @param  array<string, mixed>  $billingData
     * @param  array<string, mixed>  $shippingData
     */
    private function resolveEmail(array $billingData, array $shippingData, ?Model $user, ?Customer $sessionCustomer): ?string
    {
        $email = $this->cleanString($billingData['email'] ?? $shippingData['email'] ?? $user?->getAttribute('email') ?? $sessionCustomer?->email);

        if ($email === null) {
            return null;
        }

        return mb_strtolower($email);
    }

    /**
     * @param  array<string, mixed>  $billingData
     * @param  array<string, mixed>  $shippingData
     * @return array{0: string, 1: string}
     */
    private function resolveNameParts(array $billingData, array $shippingData, ?Model $user): array
    {
        $firstName = $this->cleanString($billingData['first_name'] ?? $shippingData['first_name'] ?? null);
        $lastName = $this->cleanString($billingData['last_name'] ?? $shippingData['last_name'] ?? null);

        if ($firstName !== null || $lastName !== null) {
            return [$firstName ?? 'Guest', $lastName ?? ''];
        }

        $name = $this->cleanString(
            $billingData['name']
                ?? $billingData['full_name']
                ?? $shippingData['name']
                ?? $shippingData['full_name']
                ?? $user?->getAttribute('name')
        );

        return $this->splitName($name);
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

    private function splitName(?string $name): array
    {
        $name = $this->cleanString($name) ?? '';

        if ($name === '') {
            return ['Guest', ''];
        }

        $parts = preg_split('/\s+/', $name) ?: [];

        $firstName = $parts[0] ?? $name;
        $lastName = count($parts) > 1 ? (string) end($parts) : '';

        return [$firstName, $lastName];
    }

    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = mb_trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
