<?php

declare(strict_types=1);

namespace AIArmada\Customers\Models;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Customers\Enums\SegmentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * @property string $id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property SegmentType $type
 * @property array<int, array{field: string, operator?: string, value: mixed}>|null $conditions
 * @property bool $is_automatic
 * @property int $priority
 * @property bool $is_active
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $owner
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Customer> $customers
 */
class Segment extends Model
{
    use HasFactory;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasUuids;

    protected static string $ownerScopeConfigKey = 'customers.features.owner';

    protected $guarded = ['id'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'type' => SegmentType::class,
        'conditions' => 'array',
        'is_active' => 'boolean',
        'is_automatic' => 'boolean',
        'priority' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'is_automatic' => true,
        'priority' => 0,
    ];

    public function getTable(): string
    {
        $tables = config('customers.database.tables', []);
        $prefix = config('customers.database.table_prefix', 'customer_');

        return $tables['segments'] ?? $prefix . 'segments';
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * Get the customers in this segment.
     *
     * @return BelongsToMany<Customer, $this>
     */
    public function customers(): BelongsToMany
    {
        $tables = config('customers.database.tables', []);
        $prefix = config('customers.database.table_prefix', 'customer_');

        return $this->belongsToMany(
            Customer::class,
            $tables['segment_customer'] ?? $prefix . 'segment_customer',
            'segment_id',
            'customer_id'
        )->withTimestamps();
    }

    // =========================================================================
    // CONDITION ENGINE
    // =========================================================================

    /**
     * Get customers matching the segment conditions.
     */
    public function getMatchingCustomers(): Collection
    {
        if (! $this->is_automatic || empty($this->conditions)) {
            return $this->customers;
        }

        $segmentOwner = OwnerContext::fromTypeAndId($this->owner_type, $this->owner_id);

        $query = Customer::query()
            ->active()
            ->forOwner($segmentOwner, includeGlobal: false);
        $this->applyConditions($query, $this->conditions);

        return $query->get();
    }

    /**
     * Rebuild the customer list for automatic segments.
     */
    public function rebuildCustomerList(): int
    {
        if (! $this->is_automatic) {
            return $this->customers()->count();
        }

        $matchingCustomers = $this->getMatchingCustomers();
        $this->customers()->sync($matchingCustomers->pluck('id'));

        return $matchingCustomers->count();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    public function isAutomatic(): bool
    {
        return $this->is_automatic;
    }

    public function isManual(): bool
    {
        return ! $this->is_automatic;
    }

    /**
     * Add a customer to this segment.
     */
    public function addCustomer(Customer $customer): void
    {
        if (! $this->isSameOwnerAsCustomer($customer)) {
            throw new InvalidArgumentException('Segment and customer must share the same owner context.');
        }

        $this->customers()->syncWithoutDetaching([$customer->id]);
    }

    /**
     * Remove a customer from this segment.
     */
    public function removeCustomer(Customer $customer): void
    {
        if (! $this->isSameOwnerAsCustomer($customer)) {
            throw new InvalidArgumentException('Segment and customer must share the same owner context.');
        }

        $this->customers()->detach($customer->id);
    }

    private function isSameOwnerAsCustomer(Customer $customer): bool
    {
        if ($this->owner_type === null && $this->owner_id === null) {
            return $customer->owner_type === null && $customer->owner_id === null;
        }

        return $customer->owner_type === $this->owner_type
            && $customer->owner_id === $this->owner_id;
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAutomatic(Builder $query): Builder
    {
        return $query->where('is_automatic', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeManual(Builder $query): Builder
    {
        return $query->where('is_automatic', false);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeByPriority(Builder $query): Builder
    {
        return $query->orderBy('priority', 'desc');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, SegmentType $type): Builder
    {
        return $query->where('type', $type);
    }

    // =========================================================================
    // BOOT
    // =========================================================================

    protected static function booted(): void
    {
        static::creating(function (Segment $segment): void {
            if (! (bool) config('customers.features.owner.enabled', false)) {
                return;
            }

            if (! (bool) config('customers.features.owner.auto_assign_on_create', true)) {
                return;
            }

            if ($segment->owner_type !== null || $segment->owner_id !== null) {
                return;
            }

            $owner = OwnerContext::resolve();

            if ($owner !== null) {
                $segment->assignOwner($owner);
            }
        });

        static::deleting(function (Segment $segment): void {
            $segment->customers()->detach();
        });
    }

    /**
     * Apply segment conditions to a query.
     *
     * Conditions can use either 'value_numeric' / 'value_boolean' keys (Filament form)
     * or a single 'value' key (legacy/API). We normalize before matching.
     *
     * @param  array<int, array{field?: string|null, operator?: string, value?: mixed, value_numeric?: mixed, value_boolean?: mixed}>  $conditions
     */
    protected function applyConditions(Builder $query, array $conditions): void
    {
        foreach ($conditions as $condition) {
            $field = $condition['field'] ?? null;
            $value = $condition['value_numeric'] ?? $condition['value_boolean'] ?? $condition['value'] ?? null;

            if (! $field || $value === null) {
                continue;
            }

            match ($field) {
                'accepts_marketing' => $query->where('accepts_marketing', (bool) $value),
                'status' => $query->where('status', (string) $value),
                'created_days_ago' => $query->where('created_at', '<=', CarbonImmutable::now()->subDays((int) $value)),
                default => null,
            };
        }
    }
}
