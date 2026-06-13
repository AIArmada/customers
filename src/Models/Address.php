<?php

declare(strict_types=1);

namespace AIArmada\Customers\Models;

use AIArmada\CommerceSupport\Concerns\HasCommerceAudit;
use AIArmada\CommerceSupport\Concerns\LogsCommerceActivity;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Contacting\Concerns\HasContactMethods;
use AIArmada\Customers\Concerns\IsCustomerOwned;
use AIArmada\Customers\Enums\AddressType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

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
 * @property string $country_code
 * @property string|null $country
 * @property bool $is_default_billing
 * @property bool $is_default_shipping
 * @property CarbonImmutable|null $verified_at
 * @property array{lat?: float, lng?: float}|null $coordinates
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $full_address
 * @property-read Customer $customer
 */
class Address extends Model implements Auditable
{
    use HasCommerceAudit;
    use HasContactMethods;
    use HasFactory;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasUuids;
    use IsCustomerOwned;
    use LogsCommerceActivity;

    protected static string $ownerScopeConfigKey = 'customers.features.owner';

    protected $guarded = ['id'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'type' => AddressType::class,
        'is_default_billing' => 'boolean',
        'is_default_shipping' => 'boolean',
        'verified_at' => 'immutable_datetime',
        'coordinates' => 'array', // ['lat' => x, 'lng' => y]
        'metadata' => 'array',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_default_billing' => false,
        'is_default_shipping' => false,
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
            $this->country_code,
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

        $lines[] = $this->country_code;

        $phone = $this->phone;

        if ($this->relationLoaded('contactMethods')) {
            $phone = $this->contactMethods->firstWhere('type', 'phone')?->value ?? $phone;
        }

        if ($phone !== null && $phone !== '') {
            $lines[] = $phone;
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
            'country_code' => $this->country_code,
            'phone' => $this->relationLoaded('contactMethods')
                ? ($this->contactMethods->firstWhere('type', 'phone')?->value ?? $this->phone)
                : $this->phone,
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
        return $query->whereNotNull('verified_at');
    }

    protected static function booted(): void {}
}
