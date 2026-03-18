<?php

declare(strict_types=1);

namespace AIArmada\Customers\Models;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * @property string $id
 * @property string $customer_id
 * @property string|null $created_by
 * @property string $content
 * @property bool $is_internal
 * @property bool $is_pinned
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer $customer
 * @property-read Model|null $createdBy
 */
class CustomerNote extends Model
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
        'is_internal' => 'boolean',
        'is_pinned' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_internal' => true,
        'is_pinned' => false,
    ];

    public function getTable(): string
    {
        $tables = config('customers.database.tables', []);
        $prefix = config('customers.database.table_prefix', 'customer_');

        return $tables['notes'] ?? $prefix . 'notes';
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * Get the customer this note belongs to.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * Get the user who created this note.
     *
     * @return BelongsTo<Model, $this>
     */
    public function createdBy(): BelongsTo
    {
        /** @var class-string<Model>|null $userModel */
        $userModel = config('customers.integrations.user_model');

        /** @var class-string<Model>|null $fallbackUserModel */
        $fallbackUserModel = config('auth.providers.users.model');

        return $this->belongsTo($userModel ?? $fallbackUserModel ?? User::class, 'created_by');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Check if this is an internal note.
     */
    public function isInternal(): bool
    {
        return $this->is_internal;
    }

    /**
     * Check if this note is visible to customer.
     */
    public function isVisibleToCustomer(): bool
    {
        return ! $this->is_internal;
    }

    /**
     * Pin this note.
     */
    public function pin(): void
    {
        $this->update(['is_pinned' => true]);
    }

    /**
     * Unpin this note.
     */
    public function unpin(): void
    {
        $this->update(['is_pinned' => false]);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('is_internal', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleToCustomer(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePinned(Builder $query): Builder
    {
        return $query->where('is_pinned', true);
    }

    protected static function booted(): void
    {
        static::creating(function (CustomerNote $note): void {
            if (! (bool) config('customers.features.owner.enabled', false)) {
                return;
            }

            if ($note->owner_type !== null || $note->owner_id !== null) {
                return;
            }

            $owner = OwnerContext::resolve();

            $customer = Customer::query()
                ->forOwner($owner, includeGlobal: false)
                ->whereKey($note->customer_id)
                ->first();

            if ($customer === null) {
                throw new InvalidArgumentException('Customer note customer must belong to the current owner context.');
            }

            if ($customer->owner_type !== null && $customer->owner_id !== null) {
                $note->owner_type = $customer->owner_type;
                $note->owner_id = $customer->owner_id;
            }
        });

        static::updating(function (CustomerNote $note): void {
            if (! (bool) config('customers.features.owner.enabled', false)) {
                return;
            }

            if (! $note->isDirty('customer_id')) {
                return;
            }

            $owner = OwnerContext::resolve();

            $customer = Customer::query()
                ->forOwner($owner, includeGlobal: false)
                ->whereKey($note->customer_id)
                ->first();

            if ($customer === null) {
                throw new InvalidArgumentException('Customer note customer must belong to the current owner context.');
            }

            $note->owner_type = $customer->owner_type;
            $note->owner_id = $customer->owner_id;
        });
    }
}
