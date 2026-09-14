<?php

declare(strict_types=1);

namespace AIArmada\Customers\Policies;

use AIArmada\Addressing\Models\Address;
use AIArmada\CommerceSupport\Support\OwnerContext;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class AddressPolicy
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
        if (! Address::ownerScopeConfig()->enabled) {
            return null;
        }

        return OwnerContext::resolve();
    }

    private function isAccessible(Address $address): bool
    {
        $ownerScopeConfig = Address::ownerScopeConfig();

        if (! $ownerScopeConfig->enabled) {
            return true;
        }

        $owner = $this->resolveOwner();
        $includeGlobal = $ownerScopeConfig->includeGlobal;

        if ($owner === null) {
            return $address->owner_type === null && $address->owner_id === null;
        }

        if ($includeGlobal && $address->isGlobal()) {
            return true;
        }

        return $address->belongsToOwner($owner);
    }

    public function viewAny(mixed $user): bool
    {
        return $this->hasPermission($user, 'customers.addresses.view');
    }

    public function view(mixed $user, Address $address): bool
    {
        return $this->hasPermission($user, 'customers.addresses.view') && $this->isAccessible($address);
    }

    public function create(mixed $user): bool
    {
        return $this->hasPermission($user, 'customers.addresses.create');
    }

    public function update(mixed $user, Address $address): bool
    {
        return $this->hasPermission($user, 'customers.addresses.update') && $this->isAccessible($address);
    }

    public function delete(mixed $user, Address $address): bool
    {
        return $this->hasPermission($user, 'customers.addresses.delete') && $this->isAccessible($address);
    }
}
