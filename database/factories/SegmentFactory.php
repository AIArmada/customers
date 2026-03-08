<?php

declare(strict_types=1);

namespace AIArmada\Customers\Database\Factories;

use AIArmada\Customers\Enums\SegmentType;
use AIArmada\Customers\Models\Segment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Segment>
 */
class SegmentFactory extends Factory
{
    protected $model = Segment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'name' => ucwords($name),
            'slug' => Str::slug($name),
            'description' => $this->faker->sentence(),
            'type' => $this->faker->randomElement(SegmentType::cases()),
            'conditions' => [],
            'is_automatic' => false,
            'priority' => $this->faker->numberBetween(0, 100),
            'is_active' => true,
        ];
    }

    /**
     * Automatic segment.
     */
    public function automatic(array $conditions = []): static
    {
        return $this->state(fn (array $attributes) => [
            'is_automatic' => true,
            'conditions' => $conditions ?: [
                ['field' => 'lifetime_value_min', 'operator' => '>=', 'value' => 1000_00],
            ],
        ]);
    }

    /**
     * Manual segment.
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_automatic' => false,
            'conditions' => [],
        ]);
    }

    /**
     * Loyalty segment.
     */
    public function loyalty(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => SegmentType::Loyalty,
            'is_automatic' => true,
            'conditions' => [
                ['field' => 'total_orders_min', 'operator' => '>=', 'value' => 10],
            ],
        ]);
    }

    /**
     * Inactive segment.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
