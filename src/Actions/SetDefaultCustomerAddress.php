<?php

declare(strict_types=1);

namespace AIArmada\Customers\Actions;

use AIArmada\Customers\Models\Address;
use AIArmada\Customers\Models\Customer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class SetDefaultCustomerAddress
{
    public function execute(Address $address, string $kind): void
    {
        $column = match ($kind) {
            'billing' => 'is_default_billing',
            'shipping' => 'is_default_shipping',
            default => throw new InvalidArgumentException('Customer address default kind must be billing or shipping.'),
        };

        if (! $address->exists) {
            throw new LogicException('Only persisted addresses can be made default.');
        }

        DB::transaction(function () use ($address, $column): void {
            $customer = Customer::query()
                ->whereKey($address->customer_id)
                ->lockForUpdate()
                ->firstOrFail();

            $persistedAddress = $customer->legacyAddresses()
                ->whereKey($address->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $customer->legacyAddresses()
                ->where('id', '!=', $persistedAddress->getKey())
                ->update([$column => false]);

            $persistedAddress->forceFill([$column => true])->save();
        });

        $address->setAttribute($column, true);
    }
}
