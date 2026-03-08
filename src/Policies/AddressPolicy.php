<?php

declare(strict_types=1);

namespace AIArmada\Customers\Policies;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Customers\Models\Address;
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
        if (! (bool) config('customers.features.owner.enabled', false)) {
            return null;
        }

        return OwnerContext::resolve();
    }

    private function isAccessible(Address $address): bool
    {
        if (! (bool) config('customers.features.owner.enabled', false)) {
            return true;
        }

        $owner = $this->resolveOwner();
        $includeGlobal = (bool) config('customers.features.owner.include_global', false);

        if ($owner === null) {
            return $address->owner_type === null && $address->owner_id === null;
        }

        if ($includeGlobal && method_exists($address, 'isGlobal') && $address->isGlobal()) {
            return true;
        }

        if (method_exists($address, 'belongsToOwner')) {
            return $address->belongsToOwner($owner);
        }

        return $address->owner_type === $owner->getMorphClass()
            && $address->owner_id === $owner->getKey();
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
