<?php

declare(strict_types=1);

namespace AIArmada\Customers\Actions;

use AIArmada\Customers\Events\CustomerSegmentChanged;
use AIArmada\Customers\Models\Customer;
use AIArmada\Customers\Models\Segment;
use InvalidArgumentException;

final class AssignCustomerToSegment
{
    public function execute(Customer $customer, Segment $segment): void
    {
        if (! $this->shareOwner($customer, $segment)) {
            throw new InvalidArgumentException('Customer and segment must share the same owner context.');
        }

        if ($customer->segments()->whereKey($segment->id)->exists()) {
            return;
        }

        $customer->segments()->attach($segment->id);
        event(new CustomerSegmentChanged($customer, $segment, 'added'));
    }

    private function shareOwner(Customer $customer, Segment $segment): bool
    {
        if ($segment->owner_type === null && $segment->owner_id === null) {
            return $customer->owner_type === null && $customer->owner_id === null;
        }

        return $customer->owner_type === $segment->owner_type
            && $customer->owner_id === $segment->owner_id;
    }
}
