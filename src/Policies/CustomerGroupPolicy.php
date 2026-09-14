<?php

declare(strict_types=1);

namespace AIArmada\Customers\Policies;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Customers\Models\CustomerGroup;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class CustomerGroupPolicy
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

        if ($includeGlobal && $group->isGlobal()) {
            return true;
        }

        return $group->belongsToOwner($owner);
    }

    public function viewAny(mixed $user): bool
    {
        return $this->hasPermission($user, 'customers.groups.view');
    }

    public function view(mixed $user, CustomerGroup $group): bool
    {
        return $this->hasPermission($user, 'customers.groups.view') && $this->isAccessible($group);
    }

    public function create(mixed $user): bool
    {
        return $this->hasPermission($user, 'customers.groups.create');
    }

    public function update(mixed $user, CustomerGroup $group): bool
    {
        return $this->hasPermission($user, 'customers.groups.update') && $this->isAccessible($group);
    }

    public function delete(mixed $user, CustomerGroup $group): bool
    {
        return $this->hasPermission($user, 'customers.groups.delete') && $this->isAccessible($group);
    }

    /**
     * Determine if user can manage members of the group.
     */
    public function manageMembers(mixed $user, CustomerGroup $group): bool
    {
        return $this->hasPermission($user, 'customers.groups.manage-members') && $this->update($user, $group);
    }
}
