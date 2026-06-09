<?php

declare(strict_types=1);

namespace AIArmada\Customers\Contracts;

use AIArmada\Customers\Models\Address;
use AIArmada\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Public contract for models that expose a customer profile.
 *
 * Implement this on User models to integrate with the customers package.
 * Use the {@see \AIArmada\Customers\Concerns\HasCustomerProfile} trait
 * for the default implementation.
 */
interface HasCustomerProfile
{
    public function customerProfile(): HasOne;

    public function getOrCreateCustomerProfile(): Customer;

    public function hasCustomerProfile(): bool;

    public function acceptsMarketing(): bool;

    public function getDefaultShippingAddress(): ?Address;

    public function getDefaultBillingAddress(): ?Address;
}
