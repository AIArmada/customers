<?php

declare(strict_types=1);

namespace AIArmada\Customers\Policies;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Customers\Models\Segment;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Model;

final class SegmentPolicy
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

        if ($includeGlobal && method_exists($segment, 'isGlobal') && $segment->isGlobal()) {
            return true;
        }

        if (method_exists($segment, 'belongsToOwner')) {
            return $segment->belongsToOwner($owner);
        }

        return $segment->owner_type === $owner->getMorphClass()
            && $segment->owner_id === $owner->getKey();
    }

    public function viewAny(mixed $user): bool
    {
        return $this->isAuthenticated($user);
    }

    public function view(mixed $user, Segment $segment): bool
    {
        return $this->isAuthenticated($user) && $this->isAccessible($segment);
    }

    public function create(mixed $user): bool
    {
        return $this->isAuthenticated($user);
    }

    public function update(mixed $user, Segment $segment): bool
    {
        return $this->isAuthenticated($user) && $this->isAccessible($segment);
    }

    public function delete(mixed $user, Segment $segment): bool
    {
        return $this->isAuthenticated($user) && $this->isAccessible($segment);
    }

    /**
     * Determine if user can rebuild segment.
     */
    public function rebuild(mixed $user, Segment $segment): bool
    {
        return $this->isAuthenticated($user) && $this->update($user, $segment) && $segment->is_automatic;
    }
}
