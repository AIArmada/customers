<?php

declare(strict_types=1);

namespace AIArmada\Customers\Concerns;

use AIArmada\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

trait ResolvesCustomerIdentity
{
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
    private function resolveEmail(
        array $billingData,
        array $shippingData,
        ?Model $user,
        ?Customer $sessionCustomer,
    ): ?string {
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

    private function customersShareOwnerContext(Customer $source, Customer $target): bool
    {
        if ($source->owner_type === null && $source->owner_id === null) {
            return $target->owner_type === null && $target->owner_id === null;
        }

        return $source->owner_type === $target->owner_type
            && $source->owner_id === $target->owner_id;
    }
}
