<?php

declare(strict_types=1);

namespace AIArmada\Customers\Concerns;

use AIArmada\CommerceSupport\Support\OwnerContext;
use Illuminate\Database\Eloquent\Model;

/**
 * For sibling models that are related to customers but not directly
 * owned by a single customer (e.g., CustomerGroup, Segment).
 * Auto-assigns owner from context on create.
 *
 * @mixin Model
 */
trait IsCustomerRelated
{
    protected static function bootIsCustomerRelated(): void
    {
        static::creating(function (Model $model): void {
            if (! (bool) config('customers.features.owner.enabled', false)) {
                return;
            }

            if (! (bool) config('customers.features.owner.auto_assign_on_create', true)) {
                return;
            }

            if ($model->getAttribute('owner_type') !== null || $model->getAttribute('owner_id') !== null) {
                return;
            }

            $owner = OwnerContext::resolve();

            if ($owner !== null && method_exists($model, 'assignOwner')) {
                $model->assignOwner($owner);
            }
        });
    }
}
