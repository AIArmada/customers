<?php

declare(strict_types=1);

namespace AIArmada\Customers\Policies;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Customers\Models\CustomerNote;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Model;

final class CustomerNotePolicy
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

    private function isAccessible(CustomerNote $note): bool
    {
        if (! (bool) config('customers.features.owner.enabled', false)) {
            return true;
        }

        $owner = $this->resolveOwner();
        $includeGlobal = (bool) config('customers.features.owner.include_global', false);

        if ($owner === null) {
            return $note->owner_type === null && $note->owner_id === null;
        }

        if ($includeGlobal && method_exists($note, 'isGlobal') && $note->isGlobal()) {
            return true;
        }

        if (method_exists($note, 'belongsToOwner')) {
            return $note->belongsToOwner($owner);
        }

        return $note->owner_type === $owner->getMorphClass()
            && $note->owner_id === $owner->getKey();
    }

    public function viewAny(mixed $user): bool
    {
        return $this->isAuthenticated($user);
    }

    public function view(mixed $user, CustomerNote $note): bool
    {
        return $this->isAuthenticated($user) && $this->isAccessible($note);
    }

    public function create(mixed $user): bool
    {
        return $this->isAuthenticated($user);
    }

    public function update(mixed $user, CustomerNote $note): bool
    {
        return $this->isAuthenticated($user) && $this->isAccessible($note);
    }

    public function delete(mixed $user, CustomerNote $note): bool
    {
        return $this->isAuthenticated($user) && $this->isAccessible($note);
    }
}
