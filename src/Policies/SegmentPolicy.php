<?php

declare(strict_types=1);

namespace AIArmada\Customers\Policies;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Customers\Models\Segment;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class SegmentPolicy
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

    private function isAccessible(Segment $segment): bool
    {
        if (! (bool) config('customers.features.owner.enabled', false)) {
            return true;
        }

        $owner = $this->resolveOwner();
        $includeGlobal = (bool) config('customers.features.owner.include_global', false);

        if ($owner === null) {
            return $segment->owner_type === null && $segment->owner_id === null;
        }

        if ($includeGlobal && $segment->isGlobal()) {
            return true;
        }

        return $segment->belongsToOwner($owner);
    }

    public function viewAny(mixed $user): bool
    {
        return $this->hasPermission($user, 'customers.segments.view');
    }

    public function view(mixed $user, Segment $segment): bool
    {
        return $this->hasPermission($user, 'customers.segments.view') && $this->isAccessible($segment);
    }

    public function create(mixed $user): bool
    {
        return $this->hasPermission($user, 'customers.segments.create');
    }

    public function update(mixed $user, Segment $segment): bool
    {
        return $this->hasPermission($user, 'customers.segments.update') && $this->isAccessible($segment);
    }

    public function delete(mixed $user, Segment $segment): bool
    {
        return $this->hasPermission($user, 'customers.segments.delete') && $this->isAccessible($segment);
    }

    /**
     * Determine if user can rebuild segment.
     */
    public function rebuild(mixed $user, Segment $segment): bool
    {
        return $this->hasPermission($user, 'customers.segments.rebuild') && $this->update($user, $segment) && $segment->is_automatic;
    }
}
