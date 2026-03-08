<?php

declare(strict_types=1);

namespace AIArmada\Customers\Policies;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Customers\Models\CustomerGroup;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Model;

final class CustomerGroupPolicy
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

    private function isAccessible(CustomerGroup $group): bool
    {
        if (! (bool) config('customers.features.owner.enabled', false)) {
            return true;
        }

        $owner = $this->resolveOwner();
        $includeGlobal = (bool) config('customers.features.owner.include_global', false);

        if ($owner === null) {
            return $group->owner_type === null && $group->owner_id === null;
        }

        if ($includeGlobal && method_exists($group, 'isGlobal') && $group->isGlobal()) {
            return true;
        }

        if (method_exists($group, 'belongsToOwner')) {
            return $group->belongsToOwner($owner);
        }

        return $group->owner_type === $owner->getMorphClass()
            && $group->owner_id === $owner->getKey();
    }

    public function viewAny(mixed $user): bool
    {
        return $this->isAuthenticated($user);
    }

    public function view(mixed $user, CustomerGroup $group): bool
    {
        return $this->isAuthenticated($user) && $this->isAccessible($group);
    }

    public function create(mixed $user): bool
    {
        return $this->isAuthenticated($user);
    }

    public function update(mixed $user, CustomerGroup $group): bool
    {
        return $this->isAuthenticated($user) && $this->isAccessible($group);
    }

    public function delete(mixed $user, CustomerGroup $group): bool
    {
        return $this->isAuthenticated($user) && $this->isAccessible($group);
    }

    /**
     * Determine if user can manage members of the group.
     */
    public function manageMembers(mixed $user, CustomerGroup $group): bool
    {
        return $this->isAuthenticated($user) && $this->isAccessible($group);
    }
}
