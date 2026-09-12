<?php

declare(strict_types=1);

namespace AIArmada\Customers\Policies;

use AIArmada\Addressing\Models\Address;
use AIArmada\CommerceSupport\Support\OwnerContext;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Model;

final class AddressPolicy
{
    use HandlesAuthorization;

    private function isAuthenticated(mixed $user): bool
    {
        return $user !== null;
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
        return $this->isAuthenticated($user);
    }

    public function view(mixed $user, Address $address): bool
    {
        return $this->isAuthenticated($user) && $this->isAccessible($address);
    }

    public function create(mixed $user): bool
    {
        return $this->isAuthenticated($user);
    }

    public function update(mixed $user, Address $address): bool
    {
        return $this->isAuthenticated($user) && $this->isAccessible($address);
    }

    public function delete(mixed $user, Address $address): bool
    {
        return $this->isAuthenticated($user) && $this->isAccessible($address);
    }
}
