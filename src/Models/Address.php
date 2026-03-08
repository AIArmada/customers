<?php

declare(strict_types=1);

namespace AIArmada\Customers\Models;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Customers\Enums\AddressType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * @property string $id
 * @property string $customer_id
 * @property AddressType $type
 * @property string|null $label
 * @property string|null $recipient_name
 * @property string|null $company
 * @property string|null $phone
 * @property string $line1
 * @property string|null $line2
 * @property string $city
 * @property string|null $state
 * @property string $postcode
 * @property string $country
 * @property bool $is_default_billing
 * @property bool $is_default_shipping
 * @property bool $is_verified
 * @property array{lat?: float, lng?: float}|null $coordinates
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $full_address
 * @property-read Customer $customer
 */
class Address extends Model
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
        'type' => AddressType::class,
        'is_default_billing' => 'boolean',
        'is_default_shipping' => 'boolean',
        'is_verified' => 'boolean',
        'coordinates' => 'array', // ['lat' => x, 'lng' => y]
        'metadata' => 'array',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_default_billing' => false,
        'is_default_shipping' => false,
        'is_verified' => false,
    ];

    public function getTable(): string
    {
        $tables = config('customers.database.tables', []);
        $prefix = config('customers.database.table_prefix', 'customer_');

        return $tables['addresses'] ?? $prefix . 'addresses';
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * Get the customer who owns this address.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    // =========================================================================
    // TYPE HELPERS
    // =========================================================================

    public function isBillingAddress(): bool
    {
        return $this->type->isBilling();
    }

    public function isShippingAddress(): bool
    {
        return $this->type->isShipping();
    }

    // =========================================================================
    // DEFAULT MANAGEMENT
    // =========================================================================

    /**
     * Set this as the default billing address.
     */
    public function setAsDefaultBilling(): void
    {
        // Remove default from other addresses
        $this->customer->addresses()
            ->where('id', '!=', $this->id)
            ->update(['is_default_billing' => false]);

        $this->update(['is_default_billing' => true]);
    }

    /**
     * Set this as the default shipping address.
     */
    public function setAsDefaultShipping(): void
    {
        $this->customer->addresses()
            ->where('id', '!=', $this->id)
            ->update(['is_default_shipping' => false]);

        $this->update(['is_default_shipping' => true]);
    }

    // =========================================================================
    // FORMATTING HELPERS
    // =========================================================================

    /**
     * Get the full address as a single line.
     */
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->line1,
            $this->line2,
            $this->city,
            $this->state,
            $this->postcode,
            $this->country,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Get the address formatted for display (multi-line).
     */
    public function getFormattedAddress(): string
    {
        $lines = [];

        if ($this->label) {
            $lines[] = $this->label;
        }

        if ($this->recipient_name) {
            $lines[] = $this->recipient_name;
        }

        if ($this->company) {
            $lines[] = $this->company;
        }

        $lines[] = $this->line1;

        if ($this->line2) {
            $lines[] = $this->line2;
        }

        $lines[] = implode(' ', array_filter([
            $this->city,
            $this->state,
            $this->postcode,
        ]));

        $lines[] = $this->country;

        if ($this->phone) {
            $lines[] = $this->phone;
        }

        return implode("\n", $lines);
    }

    /**
     * Get the address formatted for a shipping label.
     */
    public function toShippingLabel(): array
    {
        return [
            'name' => $this->recipient_name ?? $this->customer->full_name,
            'company' => $this->company,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'state' => $this->state,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'phone' => $this->phone,
        ];
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBilling(Builder $query): Builder
    {
        return $query->whereIn('type', [AddressType::Billing, AddressType::Both]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeShipping(Builder $query): Builder
    {
        return $query->whereIn('type', [AddressType::Shipping, AddressType::Both]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDefaultBilling(Builder $query): Builder
    {
        return $query->where('is_default_billing', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDefaultShipping(Builder $query): Builder
    {
        return $query->where('is_default_shipping', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('is_verified', true);
    }

    protected static function booted(): void
    {
        static::creating(function (Address $address): void {
            if (! (bool) config('customers.features.owner.enabled', false)) {
                return;
            }

            if ($address->owner_type !== null || $address->owner_id !== null) {
                return;
            }

            $owner = OwnerContext::resolve();

            $customer = Customer::query()
                ->forOwner($owner, includeGlobal: false)
                ->whereKey($address->customer_id)
                ->first();

            if ($customer === null) {
                throw new InvalidArgumentException('Address customer must belong to the current owner context.');
            }

            if ($customer->owner_type !== null && $customer->owner_id !== null) {
                $address->owner_type = $customer->owner_type;
                $address->owner_id = $customer->owner_id;
            }
        });

        static::updating(function (Address $address): void {
            if (! (bool) config('customers.features.owner.enabled', false)) {
                return;
            }

            if (! $address->isDirty('customer_id')) {
                return;
            }

            $owner = OwnerContext::resolve();

            $customer = Customer::query()
                ->forOwner($owner, includeGlobal: false)
                ->whereKey($address->customer_id)
                ->first();

            if ($customer === null) {
                throw new InvalidArgumentException('Address customer must belong to the current owner context.');
            }

            $address->owner_type = $customer->owner_type;
            $address->owner_id = $customer->owner_id;
        });
    }
}
