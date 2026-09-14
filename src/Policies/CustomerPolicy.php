<?php

declare(strict_types=1);

namespace AIArmada\Customers\Policies;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Customers\Models\Customer;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class CustomerPolicy
{
    use HandlesAuthorization;

    private function hasPermission(mixed $user, string $permission): bool
    {
        if ($user === null) {
            return false;
        }

        try {
            // Gate-mediated check first: Spatie permissions resolve through
            // Gate::before, which also preserves Super Admin overrides.
            if ($user instanceof Authorizable) {
                return $user->can($permission);
            }

            if (is_object($user) && method_exists($user, 'hasPermissionTo')) {
                return (bool) $user->hasPermissionTo($permission);
            }

            if (is_object($user) && method_exists($user, 'can')) {
                return (bool) $user->can($permission);
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private function resolveOwner(): ?Model
    {
        if (! (bool) config('customers.features.owner.enabled', false)) {
            return null;
        }

        return OwnerContext::resolve();
    }

    private function isAccessible(Customer $customer): bool
    {
        if (! (bool) config('customers.features.owner.enabled', false)) {
            return true;
        }

        $owner = $this->resolveOwner();
        $includeGlobal = (bool) config('customers.features.owner.include_global', false);

        if ($owner === null) {
            return $customer->owner_type === null && $customer->owner_id === null;
        }

        if ($includeGlobal && $customer->isGlobal()) {
            return true;
        }

        return $customer->belongsToOwner($owner);
    }

    public function viewAny(mixed $user): bool
    {
        return $this->hasPermission($user, 'customers.customers.view');
    }

    public function view(mixed $user, Customer $customer): bool
    {
        return $this->hasPermission($user, 'customers.customers.view') && $this->isAccessible($customer);
    }

    public function create(mixed $user): bool
    {
        return $this->hasPermission($user, 'customers.customers.create');
    }

    public function update(mixed $user, Customer $customer): bool
    {
        return $this->hasPermission($user, 'customers.customers.update') && $this->isAccessible($customer);
    }

    public function delete(mixed $user, Customer $customer): bool
    {
        return $this->hasPermission($user, 'customers.customers.delete') && $this->isAccessible($customer);
    }

    /**
     * Determine if user can add credit to customer wallet.
     */
    public function addCredit(mixed $user, Customer $customer): bool
    {
        return $this->hasPermission($user, 'customers.customers.add-credit') && $this->isAccessible($customer);
    }

    /**
     * Determine if user can deduct credit from customer wallet.
     */
    public function deductCredit(mixed $user, Customer $customer): bool
    {
        return $this->hasPermission($user, 'customers.customers.deduct-credit') && $this->isAccessible($customer);
    }
}
