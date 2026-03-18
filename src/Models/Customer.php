<?php

declare(strict_types=1);

namespace AIArmada\Customers\Models;

use AIArmada\CommerceSupport\Concerns\LogsCommerceActivity;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Customers\Enums\CustomerStatus;
use AIArmada\Customers\Events\CustomerCreated;
use AIArmada\Customers\Events\CustomerUpdated;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Tags\HasTags;

/**
 * @property string $id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string|null $user_id
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string|null $phone
 * @property string|null $company
 * @property bool $is_guest
 * @property CustomerStatus $status
 * @property bool $accepts_marketing
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read string $full_name
 * @property-read Model|null $user
 * @property-read Model|null $owner
 * @property-read Collection<int, Address> $addresses
 * @property-read Collection<int, Segment> $segments
 * @property-read Collection<int, CustomerNote> $notes
 * @property-read Collection<int, CustomerGroup> $groups
 */
class Customer extends Model implements HasMedia
{
    use HasFactory;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasTags;
    use HasUuids;
    use InteractsWithMedia;
    use LogsCommerceActivity;

    protected static string $ownerScopeConfigKey = 'customers.features.owner';

    protected $guarded = ['id'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => CustomerStatus::class,
        'accepts_marketing' => 'boolean',
        'is_guest' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'accepts_marketing' => true,
        'is_guest' => false,
    ];

    /**
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => CustomerCreated::class,
        'updated' => CustomerUpdated::class,
    ];

    public function getTable(): string
    {
        $tables = config('customers.database.tables', []);
        $prefix = config('customers.database.table_prefix', 'customer_');

        return $tables['customers'] ?? $prefix . 'customers';
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * Get the associated user.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model>|null $userModel */
        $userModel = config('customers.integrations.user_model');

        /** @var class-string<Model>|null $fallbackUserModel */
        $fallbackUserModel = config('auth.providers.users.model');

        return $this->belongsTo($userModel ?? $fallbackUserModel ?? User::class, 'user_id');
    }

    /**
     * Get the customer's addresses.
     *
     * @return HasMany<Address, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'customer_id');
    }

    /**
     * Get the customer's segments.
     *
     * @return BelongsToMany<Segment, $this>
     */
    public function segments(): BelongsToMany
    {
        $tables = config('customers.database.tables', []);
        $prefix = config('customers.database.table_prefix', 'customer_');

        return $this->belongsToMany(
            Segment::class,
            $tables['segment_customer'] ?? $prefix . 'segment_customer',
            'customer_id',
            'segment_id'
        )->withTimestamps();
    }

    /**
     * Get the customer's notes.
     *
     * @return HasMany<CustomerNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class, 'customer_id')->latest();
    }

    /**
     * Get the customer's group memberships.
     *
     * @return BelongsToMany<CustomerGroup, $this>
     */
    public function groups(): BelongsToMany
    {
        $tables = config('customers.database.tables', []);
        $prefix = config('customers.database.table_prefix', 'customer_');

        return $this->belongsToMany(
            CustomerGroup::class,
            $tables['group_members'] ?? $prefix . 'group_members',
            'customer_id',
            'group_id'
        )->withPivot(['role', 'joined_at'])->withTimestamps();
    }

    // =========================================================================
    // ADDRESS HELPERS
    // =========================================================================

    /**
     * Get the default billing address.
     */
    public function getDefaultBillingAddress(): ?Address
    {
        return $this->addresses()
            ->where('is_default_billing', true)
            ->first();
    }

    /**
     * Get the default shipping address.
     */
    public function getDefaultShippingAddress(): ?Address
    {
        return $this->addresses()
            ->where('is_default_shipping', true)
            ->first();
    }

    // =========================================================================
    // STATUS HELPERS
    // =========================================================================

    public function isActive(): bool
    {
        return $this->status === CustomerStatus::Active;
    }

    public function isGuest(): bool
    {
        return $this->is_guest;
    }

    public function isSuspended(): bool
    {
        return $this->status === CustomerStatus::Suspended;
    }

    public function canPlaceOrders(): bool
    {
        return $this->status->canPlaceOrders();
    }

    // =========================================================================
    // MARKETING HELPERS
    // =========================================================================

    public function acceptsMarketing(): bool
    {
        return $this->accepts_marketing;
    }

    public function optInMarketing(): void
    {
        $this->update(['accepts_marketing' => true]);
    }

    public function optOutMarketing(): void
    {
        $this->update(['accepts_marketing' => false]);
    }

    // =========================================================================
    // FULL NAME
    // =========================================================================

    public function getFullNameAttribute(): string
    {
        return mb_trim("{$this->first_name} {$this->last_name}");
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
        return $query->where('status', CustomerStatus::Active);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAcceptsMarketing(Builder $query): Builder
    {
        return $query->where('accepts_marketing', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInSegment(Builder $query, string | Segment $segment): Builder
    {
        $segmentId = $segment instanceof Segment ? $segment->id : $segment;

        return $query->whereHas('segments', fn ($q) => $q->where('segment_id', $segmentId));
    }

    // =========================================================================
    // MEDIA COLLECTIONS
    // =========================================================================

    /**
     * Register media collections for customer files.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);

        $this->addMediaCollection('documents');
    }

    /**
     * Get the customer's avatar URL.
     */
    public function getAvatarUrl(?string $conversion = ''): ?string
    {
        $media = $this->getFirstMedia('avatar');

        return $media?->getUrl($conversion);
    }

    // =========================================================================
    // TAG HELPERS
    // =========================================================================

    /**
     * Tag the customer for segmentation.
     *
     * @param  array<int, string>|string  $tags
     */
    public function tagForSegment(array | string $tags): static
    {
        $this->attachTags($tags, 'segments');

        return $this;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithSegmentTag(Builder $query, string $tag): Builder
    {
        return $query->withAnyTags([$tag], 'segments');
    }

    // =========================================================================
    // BOOT
    // =========================================================================

    protected static function booted(): void
    {
        static::creating(function (Customer $customer): void {
            if (! (bool) config('customers.features.owner.enabled', false)) {
                return;
            }

            if ($customer->owner_id !== null) {
                return;
            }

            if (! (bool) config('customers.features.owner.auto_assign_on_create', true)) {
                return;
            }

            $owner = OwnerContext::resolve();

            if ($owner !== null) {
                $customer->assignOwner($owner);
            }
        });

        static::deleting(function (Customer $customer): void {
            $customer->addresses()->delete();
            $customer->notes()->delete();
            $customer->segments()->detach();
            $customer->groups()->detach();
        });
    }

    // =========================================================================
    // ACTIVITY LOGGING
    // =========================================================================

    /**
     * Get the attributes to log for activity tracking.
     *
     * @return array<int, string>
     */
    protected function getLoggableAttributes(): array
    {
        return [
            'first_name',
            'last_name',
            'email',
            'phone',
            'status',
            'accepts_marketing',
        ];
    }

    /**
     * Get the activity log name for categorization.
     */
    protected function getActivityLogName(): string
    {
        return 'customers';
    }
}
