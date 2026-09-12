<?php

declare(strict_types=1);

namespace AIArmada\Customers\Models;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Traits\HasAddresses;
use AIArmada\CommerceSupport\Concerns\HasCommerceAudit;
use AIArmada\CommerceSupport\Concerns\LogsCommerceActivity;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Contacting\Actions\CreateContactMethodAction;
use AIArmada\Contacting\Concerns\HasContactMethods;
use AIArmada\Contacting\Concerns\HasSocialProfiles;
use AIArmada\Contacting\Data\ContactMethodData;
use AIArmada\Contacting\Models\ContactMethod;
use AIArmada\Customers\Concerns\HasCustomerLifecycle;
use AIArmada\Customers\Concerns\HasCustomerSegmentation;
use AIArmada\Customers\Enums\CustomerStatus;
use AIArmada\Customers\Events\CustomerCreated;
use AIArmada\Customers\Events\CustomerUpdated;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User;
use Illuminate\Validation\ValidationException;
use LogicException;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Tags\HasTags;

/**
 * @property string $id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string|null $user_id
 * @property string|null $person_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $company
 * @property bool $is_guest
 * @property CustomerStatus $status
 * @property bool $accepts_marketing
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $registered_at
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $deactivated_at
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $marketing_consented_at
 * @property CarbonImmutable|null $marketing_revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read string $full_name
 * @property-read Model|null $user
 * @property-read Model|null $person
 * @property-read Model|null $owner
 * @property-read Collection<int, Address> $addresses
 * @property-read Collection<int, Segment> $segments
 * @property-read Collection<int, CustomerNote> $notes
 * @property-read Collection<int, CustomerGroup> $groups
 */
class Customer extends Model implements Auditable, HasMedia
{
    use HasAddresses;
    use HasCommerceAudit;
    use HasContactMethods;
    use HasCustomerLifecycle;
    use HasCustomerSegmentation;
    use HasFactory;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasSocialProfiles;
    use HasTags;
    use HasUuids;
    use InteractsWithMedia;
    use LogsCommerceActivity;

    protected static string $ownerScopeConfigKey = 'customers.features.owner';

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'company',
        'status',
        'is_guest',
        'accepts_marketing',
        'registered_at',
        'activated_at',
        'deactivated_at',
        'suspended_at',
        'verified_at',
        'marketing_consented_at',
        'marketing_revoked_at',
        'metadata',
        'created_at',
        'updated_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => CustomerStatus::class,
        'accepts_marketing' => 'boolean',
        'is_guest' => 'boolean',
        'metadata' => 'array',
        'registered_at' => 'immutable_datetime',
        'activated_at' => 'immutable_datetime',
        'deactivated_at' => 'immutable_datetime',
        'suspended_at' => 'immutable_datetime',
        'verified_at' => 'immutable_datetime',
        'marketing_consented_at' => 'immutable_datetime',
        'marketing_revoked_at' => 'immutable_datetime',
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

    public static function normalizeEmail(mixed $email): ?string
    {
        if ($email === null || ! is_scalar($email)) {
            return null;
        }

        return mb_strtolower(mb_trim((string) $email));
    }

    public function addContactMethod(ContactMethodData | array $data): ContactMethod
    {
        if (is_array($data)) {
            $data = ContactMethodData::from($data);
        }

        if ($data->type === 'email') {
            $existingContact = $this->findExistingContactEmail($data->value);

            if ($existingContact !== null) {
                return $existingContact;
            }

            $this->assertContactEmailIsUnique($data->value);
        }

        return app(CreateContactMethodAction::class)->execute($this, $data);
    }

    private function findExistingContactEmail(string $email): ?ContactMethod
    {
        if (! $this->exists) {
            return null;
        }

        $normalizedEmail = static::normalizeEmail($email);

        if ($normalizedEmail === null) {
            return null;
        }

        $query = ContactMethod::query()
            ->withoutOwnerScope()
            ->where('contactable_type', $this->getMorphClass())
            ->where('contactable_id', $this->getKey())
            ->where('type', 'email')
            ->whereRaw('LOWER(TRIM(COALESCE(normalized_value, value))) = ?', [$normalizedEmail]);

        if ($this->owner_type === null && $this->owner_id === null) {
            $query->whereNull('owner_type')->whereNull('owner_id');
        } else {
            $query->where('owner_type', $this->owner_type)
                ->where('owner_id', $this->owner_id);
        }

        return $query->first();
    }

    private function assertContactEmailIsUnique(string $email): void
    {
        $normalizedEmail = static::normalizeEmail($email);

        if ($normalizedEmail === null) {
            return;
        }

        $query = ContactMethod::query()
            ->withoutOwnerScope()
            ->where('contactable_type', $this->getMorphClass())
            ->where('type', 'email')
            ->whereRaw('LOWER(TRIM(COALESCE(normalized_value, value))) = ?', [$normalizedEmail]);

        if ($this->owner_type === null && $this->owner_id === null) {
            $query->whereNull('owner_type')->whereNull('owner_id');
        } else {
            $query->where('owner_type', $this->owner_type)
                ->where('owner_id', $this->owner_id);
        }

        if ($this->exists) {
            $query->where('contactable_id', '!=', $this->getKey());
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'email' => 'The email has already been taken within the current owner scope.',
            ]);
        }
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
     * Resolve the shared human identity linked to this owner-scoped profile.
     * The persons package remains optional until the relation is used.
     *
     * @return BelongsTo<Model, $this>
     */
    public function person(): BelongsTo
    {
        $personClass = config('persons.models.person');

        if (
            ! is_string($personClass)
            || ! class_exists($personClass)
            || ! is_a($personClass, Model::class, true)
        ) {
            throw new LogicException('Configure persons.models.person before resolving a customer person.');
        }

        /** @var class-string<Model> $personClass */
        return $this->belongsTo($personClass, 'person_id');
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
    // BOOT
    // =========================================================================

    protected static function booted(): void
    {
        static::deleting(function (Customer $customer): void {
            $customer->addresses()->detach();
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
