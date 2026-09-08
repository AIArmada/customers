<?php

declare(strict_types=1);

namespace AIArmada\Customers\Services;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Customers\Actions\CreateCustomer;
use AIArmada\Customers\Actions\MergeCustomers;
use AIArmada\Customers\Actions\UpdateCustomerProfile;
use AIArmada\Customers\Enums\AddressType;
use AIArmada\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CustomerResolver
{
    public function __construct(
        private readonly CreateCustomer $createCustomer,
        private readonly UpdateCustomerProfile $updateCustomerProfile,
    ) {}

    /**
     * @param  array<string, mixed>  $billingData
     * @param  array<string, mixed>  $shippingData
     */
    public function resolveExisting(
        ?Model $user,
        ?Customer $sessionCustomer,
        array $billingData,
        array $shippingData,
        Model | string | null $owner = OwnerContext::CURRENT,
    ): ?Customer {
        return $this->runWithinOwnerContext($owner, function () use ($billingData, $sessionCustomer, $shippingData, $user): ?Customer {
            $email = $this->resolveEmail($billingData, $shippingData, $user, $sessionCustomer);

            if ($user !== null) {
                $userCustomer = $this->findUserCustomer($user);

                if (
                    $sessionCustomer !== null
                    && $userCustomer !== null
                    && $sessionCustomer->is_guest
                    && $sessionCustomer->id !== $userCustomer->id
                ) {
                    return $sessionCustomer;
                }

                if ($userCustomer !== null) {
                    return $userCustomer;
                }

                return $sessionCustomer;
            }

            if ($sessionCustomer !== null) {
                return $sessionCustomer;
            }

            if ($email === null) {
                return null;
            }

            return $this->findReusableGuestCustomerByEmail($email);
        });
    }

    /**
     * @param  array<string, mixed>  $billingData
     * @param  array<string, mixed>  $shippingData
     */
    public function resolve(
        ?Model $user,
        ?Customer $sessionCustomer,
        array $billingData,
        array $shippingData,
        Model | string | null $owner = OwnerContext::CURRENT,
    ): ?Customer {
        return $this->runWithinOwnerContext($owner, function () use ($billingData, $sessionCustomer, $shippingData, $user): ?Customer {
            return DB::transaction(function () use ($billingData, $sessionCustomer, $shippingData, $user): ?Customer {
                $email = $this->resolveEmail($billingData, $shippingData, $user, $sessionCustomer);

                if ($user !== null) {
                    $userCustomer = $this->findUserCustomer($user);

                    if ($userCustomer !== null) {
                        if ($sessionCustomer !== null && $sessionCustomer->is_guest && $sessionCustomer->id !== $userCustomer->id) {
                            if ($this->customersShareOwnerContext($sessionCustomer, $userCustomer)) {
                                $this->mergeCustomers($sessionCustomer, $userCustomer);
                            }
                        }

                        $this->updateCustomerProfile->execute($userCustomer, $billingData, $shippingData, $user);
                        $this->syncAddressesFromPayload($userCustomer, $billingData, $shippingData);

                        return $userCustomer;
                    }

                    if ($sessionCustomer !== null && $sessionCustomer->is_guest) {
                        $sessionCustomer->update([
                            'user_id' => $user->getKey(),
                            'is_guest' => false,
                        ]);

                        $this->updateCustomerProfile->execute($sessionCustomer, $billingData, $shippingData, $user);
                        $this->syncAddressesFromPayload($sessionCustomer, $billingData, $shippingData);

                        return $sessionCustomer;
                    }

                    if ($email === null) {
                        return null;
                    }

                    $emailCustomer = $this->findCustomerByEmail($email);

                    if ($emailCustomer !== null) {
                        if ($emailCustomer->user_id !== null && (string) $emailCustomer->user_id !== (string) $user->getKey()) {
                            return null;
                        }

                        if (
                            $sessionCustomer !== null
                            && $sessionCustomer->is_guest
                            && $sessionCustomer->id !== $emailCustomer->id
                            && $this->customersShareOwnerContext($sessionCustomer, $emailCustomer)
                        ) {
                            $this->mergeCustomers($sessionCustomer, $emailCustomer);
                        }

                        $emailCustomer->fill([
                            'user_id' => $user->getKey(),
                            'is_guest' => false,
                        ]);

                        if ($emailCustomer->isDirty()) {
                            $emailCustomer->save();
                        }

                        $this->updateCustomerProfile->execute($emailCustomer, $billingData, $shippingData, $user);
                        $this->syncAddressesFromPayload($emailCustomer, $billingData, $shippingData);

                        return $emailCustomer;
                    }

                    $customer = $this->createCustomer->execute($email, $billingData, $shippingData, $user, false);
                    $this->updateCustomerProfile->execute($customer, $billingData, $shippingData, $user);
                    $this->syncAddressesFromPayload($customer, $billingData, $shippingData);

                    return $customer;
                }

                if ($sessionCustomer !== null) {
                    $this->updateCustomerProfile->execute($sessionCustomer, $billingData, $shippingData, null);
                    $this->syncAddressesFromPayload($sessionCustomer, $billingData, $shippingData);

                    return $sessionCustomer;
                }

                if ($email === null) {
                    return null;
                }

                $existingCustomer = $this->findCustomerByEmail($email);

                if ($existingCustomer !== null) {
                    if (! $existingCustomer->is_guest || $existingCustomer->user_id !== null) {
                        return null;
                    }

                    $this->updateCustomerProfile->execute($existingCustomer, $billingData, $shippingData, null);
                    $this->syncAddressesFromPayload($existingCustomer, $billingData, $shippingData);

                    return $existingCustomer;
                }

                $customer = $this->createCustomer->execute($email, $billingData, $shippingData, null, true);
                $this->updateCustomerProfile->execute($customer, $billingData, $shippingData, null);
                $this->syncAddressesFromPayload($customer, $billingData, $shippingData);

                return $customer;
            });
        });
    }

    public function mergeCustomers(Customer $source, Customer $target): Customer
    {
        return app(MergeCustomers::class)->execute($target, $source);
    }

    private function findUserCustomer(Model $user): ?Customer
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

        $userId = $user->getKey();

        if ($userId === null) {
            return null;
        }

        return Customer::query()
            ->where('user_id', $userId)
            ->first();
    }

    private function findReusableGuestCustomerByEmail(string $email): ?Customer
    {
        $existingCustomer = $this->findCustomerByEmail($email);

        if ($existingCustomer === null) {
            return null;
        }

        if (! $existingCustomer->is_guest || $existingCustomer->user_id !== null) {
            return null;
        }

        return $existingCustomer;
    }

    private function findCustomerByEmail(string $email): ?Customer
    {
        $normalizedEmail = Customer::normalizeEmail($email) ?? '';

        return Customer::query()
            ->forOwner(includeGlobal: (bool) config('customers.features.owner.include_global', false))
            ->whereHas('contactMethods', function (Builder $contactMethods) use ($normalizedEmail): void {
                $contactMethods->where('type', 'email')
                    ->whereRaw('LOWER(TRIM(COALESCE(normalized_value, value))) = ?', [$normalizedEmail]);
            })
            ->first();
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
     * @param  array<string, mixed>  $billingData
     * @param  array<string, mixed>  $shippingData
     */
    private function resolveEmail(array $billingData, array $shippingData, ?Model $user, ?Customer $sessionCustomer): ?string
    {
        $email = $this->cleanString($billingData['email'] ?? $shippingData['email'] ?? $user?->getAttribute('email'));

        if ($email === null) {
            return $this->resolveCustomerEmail($sessionCustomer);
        }

        return Customer::normalizeEmail($email);
    }

    private function resolveCustomerEmail(?Customer $customer): ?string
    {
        if ($customer === null) {
            return null;
        }

        $emailContactMethod = $customer->contactMethods()
            ->where('type', 'email')
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->first();

        $email = $this->cleanString($emailContactMethod?->normalized_value ?? $emailContactMethod?->value);

        return Customer::normalizeEmail($email);
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

    private function customersShareOwnerContext(Customer $source, Customer $target): bool
    {
        if ($source->owner_type === null && $source->owner_id === null) {
            return $target->owner_type === null && $target->owner_id === null;
        }

        return $source->owner_type === $target->owner_type
            && $source->owner_id === $target->owner_id;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function runWithinOwnerContext(Model | string | null $owner, callable $callback): mixed
    {
        if ($owner === OwnerContext::CURRENT) {
            return $callback();
        }

        if ($owner !== null && ! $owner instanceof Model) {
            throw new InvalidArgumentException('Owner resolver argument must be a model instance, null, or OwnerContext::CURRENT.');
        }

        return OwnerContext::withOwner($owner, $callback);
    }
}
