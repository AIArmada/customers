<?php

declare(strict_types=1);

namespace AIArmada\Customers\Database\Factories;

use AIArmada\Customers\Enums\CustomerStatus;
use AIArmada\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->optional()->phoneNumber(),
            'company' => $this->faker->optional()->company(),
            'status' => CustomerStatus::Active,
            'accepts_marketing' => $this->faker->boolean(70),
        ];
    }

    /**
     * Active customer.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CustomerStatus::Active,
        ]);
    }

    /**
     * Suspended customer.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CustomerStatus::Suspended,
        ]);
    }

    /**
     * Customer who accepts marketing.
     */
    public function acceptsMarketing(): static
    {
        return $this->state(fn (array $attributes) => [
            'accepts_marketing' => true,
        ]);
    }

    /**
     * Customer who declined marketing.
     */
    public function declinedMarketing(): static
    {
        return $this->state(fn (array $attributes) => [
            'accepts_marketing' => false,
        ]);
    }
}
