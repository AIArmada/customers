<?php

declare(strict_types=1);

namespace AIArmada\Customers\Concerns;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * For sibling models that are owned by a Customer (via customer_id FK).
 * Automatically validates and syncs owner tuple with the parent Customer
 * on create and when customer_id changes.
 *
 * @mixin Model
 *
 * @property string|null $owner_type
 * @property string|int|null $owner_id
 */
trait IsCustomerOwned
{
    protected static function bootIsCustomerOwned(): void
    {
        static::creating(function (Model $model): void {
            if (! (bool) config('customers.features.owner.enabled', false)) {
                return;
            }

            $owner = OwnerContext::resolve();

            $customer = Customer::query()
                ->withoutOwnerScope()
                ->whereKey($model->getAttribute('customer_id'))
                ->first();

            if ($customer === null) {
                throw new InvalidArgumentException(class_basename($model) . ' customer must belong to the current owner context.');
            }

            self::validateOwnerMatch($model, $customer, $owner);

            self::syncOwnerFromCustomer($model, $customer);
        });

        static::updating(function (Model $model): void {
            if (! (bool) config('customers.features.owner.enabled', false)) {
                return;
            }

            if (! $model->isDirty('customer_id')) {
                return;
            }

            $owner = OwnerContext::resolve();

            $customer = Customer::query()
                ->withoutOwnerScope()
                ->whereKey($model->getAttribute('customer_id'))
                ->first();

            if ($customer === null) {
                throw new InvalidArgumentException(class_basename($model) . ' customer must belong to the current owner context.');
            }

            self::validateOwnerMatch($model, $customer, $owner);

            $model->setAttribute('owner_type', $customer->getAttribute('owner_type'));
            $model->setAttribute('owner_id', $customer->getAttribute('owner_id'));
        });
    }

    private static function validateOwnerMatch(Model $model, Customer $customer, mixed $owner): void
    {
        if ($owner === null) {
            if ($customer->owner_type !== null || $customer->owner_id !== null) {
                throw new InvalidArgumentException(class_basename($model) . ' customer must belong to the current owner context.');
            }
        } elseif (
            $customer->owner_type !== $owner->getMorphClass()
            || (string) $customer->owner_id !== (string) $owner->getKey()
        ) {
            throw new InvalidArgumentException(class_basename($model) . ' customer must belong to the current owner context.');
        }

        $modelOwnerType = $model->getAttribute('owner_type');
        $modelOwnerId = $model->getAttribute('owner_id');

        if (
            ($modelOwnerType !== null || $modelOwnerId !== null)
            && (
                $modelOwnerType !== $customer->owner_type
                || (string) $modelOwnerId !== (string) $customer->owner_id
            )
        ) {
            throw new InvalidArgumentException(class_basename($model) . ' owner tuple must match the related customer owner tuple.');
        }
    }

    private static function syncOwnerFromCustomer(Model $model, Customer $customer): void
    {
        if ($customer->owner_type !== null && $customer->owner_id !== null) {
            $model->setAttribute('owner_type', $customer->owner_type);
            $model->setAttribute('owner_id', $customer->owner_id);
        } else {
            $model->setAttribute('owner_type', null);
            $model->setAttribute('owner_id', null);
        }
    }
}
