<?php

declare(strict_types=1);

namespace AIArmada\Customers\Concerns;

use AIArmada\Addressing\Models\Address;
use AIArmada\Contacting\Data\ContactMethodData;
use AIArmada\Customers\Models\Customer;
use AIArmada\Customers\Support\CustomerProfileNormalizer;
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

        $normalizer = app(CustomerProfileNormalizer::class);
        $email = $normalizer->normalizeEmail($this->email) ?? '';

        if ($email === '') {
            throw new InvalidArgumentException('User email is required to create a customer profile.');
        }

        [$firstName, $lastName] = $normalizer->splitName($this->name, 'User');
        $phone = $normalizer->normalizePhone($this->phone);
        $userId = $this->getKey();

        return DB::transaction(function () use ($email, $firstName, $lastName, $phone, $userId): Customer {
            $customer = Customer::create([
                'user_id' => $userId,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);

            $customer->addContactMethod(new ContactMethodData(
                type: 'email',
                purpose: 'general',
                value: $email,
                isPrimary: true,
            ));

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

        return $customerProfile->primaryAddress('shipping');
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

        return $customerProfile->primaryAddress('billing');
    }
}
