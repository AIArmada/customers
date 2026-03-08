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
            'wallet_balance' => 0,
            'lifetime_value' => $this->faker->numberBetween(0, 100000_00),
            'total_orders' => $this->faker->numberBetween(0, 50),
            'accepts_marketing' => $this->faker->boolean(70),
            'last_order_at' => $this->faker->optional()->dateTimeThisMonth(),
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
     * Customer with wallet balance.
     */
    public function withWalletBalance(int $balanceInCents): static
    {
        return $this->state(fn (array $attributes) => [
            'wallet_balance' => $balanceInCents,
        ]);
    }

    /**
     * High-value customer.
     */
    public function highValue(): static
    {
        return $this->state(fn (array $attributes) => [
            'lifetime_value' => $this->faker->numberBetween(10000_00, 500000_00),
            'total_orders' => $this->faker->numberBetween(20, 100),
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
