<?php

declare(strict_types=1);

namespace AIArmada\Customers\Concerns;

use AIArmada\Customers\Models\Address;
use AIArmada\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;

/**
 * Trait to be used on User models to provide customer profile functionality.
 *
 * @mixin Model
 */
trait HasCustomerProfile
{
    /**
     * Get the customer profile for this user.
     */
    public function customerProfile(): HasOne
    {
        return $this->hasOne(Customer::class, 'user_id');
    }

    /**
     * Get or create the customer profile for this user.
     */
    public function getOrCreateCustomerProfile(): Customer
    {
        $customer = $this->customerProfile;

        if ($customer) {
            return $customer;
        }

        $email = $this->email;

        if (! is_string($email) || mb_trim($email) === '') {
            throw new InvalidArgumentException('User email is required to create a customer profile.');
        }

        [$firstName, $lastName] = $this->splitName($this->name);

        return $this->customerProfile()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $this->phone ?? null,
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(?string $name): array
    {
        $name = mb_trim((string) $name);

        if ($name === '') {
            return ['User', ''];
        }

        $parts = preg_split('/\s+/', $name) ?: [];

        $firstName = $parts[0] ?? $name;
        $lastName = count($parts) > 1 ? (string) end($parts) : '';

        return [$firstName, $lastName];
    }

    /**
     * Check if user has a customer profile.
     */
    public function hasCustomerProfile(): bool
    {
        return $this->customerProfile()->exists();
    }

    /**
     * Check if customer accepts marketing.
     */
    public function acceptsMarketing(): bool
    {
        return $this->customerProfile?->accepts_marketing ?? false;
    }

    /**
     * Get the customer's default shipping address.
     */
    public function getDefaultShippingAddress(): ?Address
    {
        return $this->customerProfile?->getDefaultShippingAddress();
    }

    /**
     * Get the customer's default billing address.
     */
    public function getDefaultBillingAddress(): ?Address
    {
        return $this->customerProfile?->getDefaultBillingAddress();
    }
}
