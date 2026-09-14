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
use AIArmada\Contacting\Actions\NormalizeContactMethodAction;
use AIArmada\Contacting\Concerns\HasContactMethods;
use AIArmada\Contacting\Concerns\HasSocialProfiles;
use AIArmada\Contacting\Data\ContactMethodData;
use AIArmada\Contacting\Models\ContactMethod;
use AIArmada\Contacting\Models\SocialProfile;
use AIArmada\Customers\Concerns\HasCustomerLifecycle;
use AIArmada\Customers\Concerns\HasCustomerSegmentation;
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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Validation\ValidationException;
use LogicException;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\LaravelData\Optional;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\File;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
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
        'first_name',
        'last_name',
        'company',
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
        'accepts_marketing' => false,
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

            try {
                return app(CreateContactMethodAction::class)->execute($this, $data);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'email' => 'The email has already been taken within the current owner scope.',
                ]);
            }
        }

        if (in_array($data->type, ['phone', 'mobile', 'whatsapp'], true)) {
            $existingPhone = $this->findExistingContactPhone($data);

            if ($existingPhone !== null) {
                return $existingPhone;
            }
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
            ->where('type', 'email');

        $this->constrainContactEmailMatch($query, $normalizedEmail);
        $this->constrainContactQueryToCustomerOwner($query);

        return $query->first();
    }

    private function findExistingContactPhone(ContactMethodData $data): ?ContactMethod
    {
        if (! $this->exists) {
            return null;
        }

        $countryCode = $data->countryCode instanceof Optional ? null : $data->countryCode;

        $normalized = app(NormalizeContactMethodAction::class)->execute(
            $data->type,
            $data->value,
            $countryCode ?? config('contacting.defaults.country_code'),
        );

        $query = ContactMethod::query()
            ->withoutOwnerScope()
            ->where('contactable_type', $this->getMorphClass())
            ->where('contactable_id', $this->getKey())
            ->where('type', $data->type);

        $normalizedPhone = $normalized['normalized_value'];

        if ($normalizedPhone !== null && $normalizedPhone !== '') {
            $query->where('normalized_value', $normalizedPhone);
        } else {
            $query->where('value', $data->value);
        }

        $this->constrainContactQueryToCustomerOwner($query);

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
            ->where('type', 'email');

        $this->constrainContactEmailMatch($query, $normalizedEmail);
        $this->constrainContactQueryToCustomerOwner($query);

        if ($this->exists) {
            $query->where('contactable_id', '!=', $this->getKey());
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'email' => 'The email has already been taken within the current owner scope.',
            ]);
        }
    }

    /**
     * Match a normalized email sargably: exact match on the stored normalized
     * value, with a legacy fallback for rows whose normalized value is missing.
     *
     * @param  Builder<ContactMethod>  $query
     */
    private function constrainContactEmailMatch(Builder $query, string $normalizedEmail): void
    {
        $query->where(function (Builder $query) use ($normalizedEmail): void {
            $query->where('normalized_value', $normalizedEmail)
                ->orWhere(function (Builder $query) use ($normalizedEmail): void {
                    $query->where(function (Builder $query): void {
                        $query->whereNull('normalized_value')
                            ->orWhere('normalized_value', '');
                    })->whereRaw('LOWER(TRIM(COALESCE(value, \'\'))) = ?', [$normalizedEmail]);
                });
        });
    }

    /**
     * @param  Builder<ContactMethod>  $query
     */
    private function constrainContactQueryToCustomerOwner(Builder $query): void
    {
        if ($this->owner_type === null && $this->owner_id === null) {
            $query->whereNull('owner_type')->whereNull('owner_id');
        } else {
            $query->where('owner_type', $this->owner_type)
                ->where('owner_id', $this->owner_id);
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

        $this->addMediaCollection('documents')
            ->acceptsMimeTypes([
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/csv',
                'text/plain',
                'image/jpeg',
                'image/png',
                'image/webp',
            ])
            ->acceptsFile(fn (File $file): bool => $file->size <= 10 * 1024 * 1024);
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
            $customer->contactMethods()->get()->each(fn (ContactMethod $contactMethod): bool => $contactMethod->delete());
            $customer->socialProfiles()->get()->each(fn (SocialProfile $socialProfile): bool => $socialProfile->delete());
            $customer->media()->get()->each(fn (Media $medium): bool => (bool) $medium->delete());
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
