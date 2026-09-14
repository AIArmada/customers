<?php

declare(strict_types=1);

namespace AIArmada\Customers\Policies;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Customers\Models\CustomerNote;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class CustomerNotePolicy
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

        if ($includeGlobal && $note->isGlobal()) {
            return true;
        }

        return $note->belongsToOwner($owner);
    }

    public function viewAny(mixed $user): bool
    {
        return $this->hasPermission($user, 'customers.notes.view');
    }

    public function view(mixed $user, CustomerNote $note): bool
    {
        return $this->hasPermission($user, 'customers.notes.view') && $this->isAccessible($note);
    }

    public function create(mixed $user): bool
    {
        return $this->hasPermission($user, 'customers.notes.create');
    }

    public function update(mixed $user, CustomerNote $note): bool
    {
        return $this->hasPermission($user, 'customers.notes.update') && $this->isAccessible($note);
    }

    public function delete(mixed $user, CustomerNote $note): bool
    {
        return $this->hasPermission($user, 'customers.notes.delete') && $this->isAccessible($note);
    }
}
