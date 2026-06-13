<?php

declare(strict_types=1);

namespace AIArmada\Customers\Concerns;

use AIArmada\Contacting\Data\ContactMethodData;
use AIArmada\Customers\Models\Address;
use AIArmada\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Trait to be used on User models to provide customer profile functionality.
 *
 * @mixin Model
 *
 * @property string $email
 * @property string|null $name
 * @property string|null $phone
 * @property-read Customer|null $customerProfile
 */
trait HasCustomerProfile
{
    /**
     * Get the customer profile for this user.
     *
     * @return HasOne<Customer, $this>
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

        if ($customer instanceof Customer) {
            return $customer;
        }

        $email = is_string($this->email)
            ? mb_strtolower(mb_trim($this->email))
            : '';

        if ($email === '') {
            throw new InvalidArgumentException('User email is required to create a customer profile.');
        }

        [$firstName, $lastName] = $this->splitName($this->name);
        $phone = is_string($this->phone) ? mb_trim($this->phone) : null;
        $phone = $phone === '' ? null : $phone;
        $userId = $this->getKey();

        return DB::transaction(function () use ($email, $firstName, $lastName, $phone, $userId): Customer {
            $customer = Customer::create([
                'user_id' => $userId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
            ]);

            $customer->addContactMethod(ContactMethodData::email($email, 'general'));

            if ($phone !== null) {
                $customer->addContactMethod(ContactMethodData::phone(
                    $phone,
                    countryCode: config('contacting.defaults.country_code', 'MY'),
                    purpose: 'general',
                ));
            }

            return $customer;
        });
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
        $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

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
        $customerProfile = $this->customerProfile;

        if (! $customerProfile instanceof Customer) {
            return false;
        }

        return $customerProfile->accepts_marketing;
    }

    /**
     * Get the customer's default shipping address.
     */
    public function getDefaultShippingAddress(): ?Address
    {
        $customerProfile = $this->customerProfile;

        if (! $customerProfile instanceof Customer) {
            return null;
        }

        return $customerProfile->getDefaultShippingAddress();
    }

    /**
     * Get the customer's default billing address.
     */
    public function getDefaultBillingAddress(): ?Address
    {
        $customerProfile = $this->customerProfile;

        if (! $customerProfile instanceof Customer) {
            return null;
        }

        return $customerProfile->getDefaultBillingAddress();
    }
}
