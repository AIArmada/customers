<?php

declare(strict_types=1);

namespace AIArmada\Customers\Concerns;

use AIArmada\Customers\Enums\CustomerStatus;
use AIArmada\Customers\Models\Segment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

trait HasCustomerLifecycle
{
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

    public function acceptsMarketing(): bool
    {
        return $this->accepts_marketing;
    }

    public function optInMarketing(): void
    {
        $this->update([
            'accepts_marketing' => true,
            'marketing_consented_at' => CarbonImmutable::now(),
        ]);
    }

    public function optOutMarketing(): void
    {
        $this->update([
            'accepts_marketing' => false,
            'marketing_revoked_at' => CarbonImmutable::now(),
        ]);
    }

    public function getFullNameAttribute(): string
    {
        return mb_trim("{$this->first_name} {$this->last_name}");
    }

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

        return $query->whereHas('segments', fn (Builder $segmentQuery): Builder => $segmentQuery->whereKey($segmentId));
    }
}
