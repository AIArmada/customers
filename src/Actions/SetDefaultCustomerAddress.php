<?php

declare(strict_types=1);

namespace AIArmada\Customers\Actions;

use AIArmada\Addressing\Models\Address;
use AIArmada\Customers\Models\Customer;
use InvalidArgumentException;
use LogicException;

final class SetDefaultCustomerAddress
{
    public function execute(Customer $customer, Address $address, string $kind): void
    {
        $type = match ($kind) {
            'billing', 'shipping' => $kind,
            default => throw new InvalidArgumentException('Customer address default kind must be billing or shipping.'),
        };

        if (! $address->exists) {
            throw new LogicException('Only persisted addresses can be made default.');
        }

        if (! $this->shareOwner($customer, $address)) {
            throw new InvalidArgumentException('Customer and address must share the same owner context.');
        }

        $isAttached = $customer->addresses()
            ->whereKey($address->getKey())
            ->wherePivot('type', $type)
            ->exists();

        if (! $isAttached) {
            $customer->attachAddress(
                address: $address,
                type: $type,
                isPrimary: true,
                label: $address->label,
            );

            return;
        }

        $customer->setPrimaryAddress($address, type: $type);
    }

    private function shareOwner(Customer $customer, Address $address): bool
    {
        if ($customer->owner_type === null && $customer->owner_id === null) {
            return $address->owner_type === null && $address->owner_id === null;
        }

        return $address->owner_type === $customer->owner_type
            && (string) $address->owner_id === (string) $customer->owner_id;
    }
}
