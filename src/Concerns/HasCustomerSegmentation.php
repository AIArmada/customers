<?php

declare(strict_types=1);

namespace AIArmada\Customers\Concerns;

use AIArmada\Customers\Models\CustomerGroup;
use AIArmada\Customers\Models\Segment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasCustomerSegmentation
{
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
            'segment_id',
        )->withTimestamps();
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
            'group_id',
        )->withPivot(['role', 'joined_at'])->withTimestamps();
    }

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
}
