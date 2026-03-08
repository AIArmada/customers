<?php

declare(strict_types=1);

namespace AIArmada\Customers\Models;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * @property string $id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $name
 * @property string|null $description
 * @property int|null $spending_limit
 * @property bool $is_active
 * @property bool $requires_approval
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $owner
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Customer> $members
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Customer> $admins
 */
class CustomerGroup extends Model
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
        'spending_limit' => 'integer',
        'is_active' => 'boolean',
        'requires_approval' => 'boolean',
        'settings' => 'array',
        'metadata' => 'array',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'requires_approval' => true,
    ];

    public function getTable(): string
    {
        $tables = config('customers.database.tables', []);
        $prefix = config('customers.database.table_prefix', 'customer_');

        return $tables['groups'] ?? $prefix . 'groups';
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * Get the group members.
     *
     * @return BelongsToMany<Customer, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(
            Customer::class,
            config('customers.database.tables.group_members', 'customer_group_members'),
            'group_id',
            'customer_id'
        )->withPivot(['role', 'joined_at'])->withTimestamps();
    }

    /**
     * Get group admins.
     *
     * @return BelongsToMany<Customer, $this>
     */
    public function admins(): BelongsToMany
    {
        return $this->members()->wherePivot('role', 'admin');
    }

    // =========================================================================
    // MEMBER MANAGEMENT
    // =========================================================================

    /**
     * Add a member to this group.
     */
    public function addMember(Customer $customer, string $role = 'member'): void
    {
        if (! $this->isSameOwnerAsCustomer($customer)) {
            throw new InvalidArgumentException('Group and customer must share the same owner context.');
        }

        $this->members()->syncWithoutDetaching([
            $customer->id => [
                'role' => $role,
                'joined_at' => CarbonImmutable::now(),
            ],
        ]);
    }

    /**
     * Remove a member from this group.
     */
    public function removeMember(Customer $customer): void
    {
        if (! $this->isSameOwnerAsCustomer($customer)) {
            throw new InvalidArgumentException('Group and customer must share the same owner context.');
        }

        $this->members()->detach($customer->id);
    }

    /**
     * Promote a member to admin.
     */
    public function promoteToAdmin(Customer $customer): void
    {
        if (! $this->isSameOwnerAsCustomer($customer)) {
            throw new InvalidArgumentException('Group and customer must share the same owner context.');
        }

        $this->members()->updateExistingPivot($customer->id, ['role' => 'admin']);
    }

    /**
     * Demote an admin to member.
     */
    public function demoteToMember(Customer $customer): void
    {
        if (! $this->isSameOwnerAsCustomer($customer)) {
            throw new InvalidArgumentException('Group and customer must share the same owner context.');
        }

        $this->members()->updateExistingPivot($customer->id, ['role' => 'member']);
    }

    private function isSameOwnerAsCustomer(Customer $customer): bool
    {
        if ($this->owner_type === null && $this->owner_id === null) {
            return $customer->owner_type === null && $customer->owner_id === null;
        }

        return $customer->owner_type === $this->owner_type
            && $customer->owner_id === $this->owner_id;
    }

    /**
     * Check if customer is a member.
     */
    public function hasMember(Customer $customer): bool
    {
        return $this->members()->where('customer_id', $customer->id)->exists();
    }

    /**
     * Check if customer is an admin.
     */
    public function isAdmin(Customer $customer): bool
    {
        return $this->admins()->where('customer_id', $customer->id)->exists();
    }

    // =========================================================================
    // SPENDING LIMIT
    // =========================================================================

    /**
     * Get the remaining spending limit for this group.
     * (Would need to integrate with orders package)
     */
    public function getRemainingSpendingLimit(): ?int
    {
        if ($this->spending_limit === null) {
            return null; // Unlimited
        }

        // This would calculate based on current month spending
        // For now, return the full limit
        return $this->spending_limit;
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

    // =========================================================================
    // BOOT
    // =========================================================================

    protected static function booted(): void
    {
        static::creating(function (CustomerGroup $group): void {
            if (! (bool) config('customers.features.owner.enabled', false)) {
                return;
            }

            if (! (bool) config('customers.features.owner.auto_assign_on_create', true)) {
                return;
            }

            if ($group->owner_type !== null || $group->owner_id !== null) {
                return;
            }

            $owner = OwnerContext::resolve();

            if ($owner !== null) {
                $group->assignOwner($owner);
            }
        });

        static::deleting(function (CustomerGroup $group): void {
            $group->members()->detach();
        });
    }
}
