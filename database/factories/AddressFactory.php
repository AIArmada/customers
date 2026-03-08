<?php

declare(strict_types=1);

namespace AIArmada\Customers\Database\Factories;

use AIArmada\Customers\Enums\AddressType;
use AIArmada\Customers\Models\Address;
use AIArmada\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    protected $model = Address::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'type' => AddressType::Both,
            'label' => $this->faker->randomElement(['Home', 'Office', 'Other']),
            'recipient_name' => $this->faker->name(),
            'company' => $this->faker->optional()->company(),
            'phone' => $this->faker->phoneNumber(),
            'line1' => $this->faker->streetAddress(),
            'line2' => $this->faker->optional()->secondaryAddress(),
            'city' => $this->faker->city(),
            'state' => $this->faker->state(),
            'postcode' => $this->faker->postcode(),
            'country' => 'MY',
            'is_default_billing' => false,
            'is_default_shipping' => false,
            'is_verified' => false,
        ];
    }

    /**
     * Billing address.
     */
    public function billing(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => AddressType::Billing,
            'is_default_billing' => true,
        ]);
    }

    /**
     * Shipping address.
     */
    public function shipping(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => AddressType::Shipping,
            'is_default_shipping' => true,
        ]);
    }

    /**
     * Default billing address.
     */
    public function defaultBilling(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default_billing' => true,
        ]);
    }

    /**
     * Default shipping address.
     */
    public function defaultShipping(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default_shipping' => true,
        ]);
    }

    /**
     * Verified address.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => true,
            'coordinates' => [
                'lat' => $this->faker->latitude(),
                'lng' => $this->faker->longitude(),
            ],
        ]);
    }
}
