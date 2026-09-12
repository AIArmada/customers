<?php

declare(strict_types=1);

namespace AIArmada\Customers\Concerns;

use AIArmada\Customers\Models\Address;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasCustomerAddresses
{
    /**
     * Get the customer's frozen legacy package-local addresses.
     *
     * New reusable addresses belong to the addressing package and are exposed
     * through the HasAddresses trait.
     *
     * @return HasMany<Address, $this>
     */
    public function legacyAddresses(): HasMany
    {
        return $this->hasMany(Address::class, 'customer_id');
    }

    /**
     * Get the default billing address from the frozen legacy storage.
     */
    public function getDefaultBillingAddress(): ?Address
    {
        return $this->legacyAddresses()
            ->where('is_default_billing', true)
            ->first();
    }

    /**
     * Get the default shipping address from the frozen legacy storage.
     */
    public function getDefaultShippingAddress(): ?Address
    {
        return $this->legacyAddresses()
            ->where('is_default_shipping', true)
            ->first();
    }
}
