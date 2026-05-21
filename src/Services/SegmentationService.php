<?php

declare(strict_types=1);

namespace AIArmada\Customers\Services;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Customers\Events\CustomerSegmentChanged;
use AIArmada\Customers\Models\Customer;
use AIArmada\Customers\Models\Segment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Service for managing customer segmentation.
 *
 * Provides methods for:
 * - Automatically segmenting customers based on rules
 * - Manually managing segment membership
 * - Bulk rebuilding segment memberships
 */
final class SegmentationService
{
    /**
     * Rebuild all automatic segments.
     *
     * @return array<string, int> Map of segment names to customer counts
     */
    public function rebuildAllSegments(?Model $owner = null): array
    {
        return OwnerContext::withOwner($owner, function () use ($owner): array {
            $query = Segment::query()->active()->automatic();

            $query->forOwner($owner, includeGlobal: false);

            $results = [];

            $query->each(function (Segment $segment) use (&$results): void {
                $count = $this->rebuildSegment($segment);
                $results[$segment->name] = $count;
            });

            return $results;
        });
    }

    /**
     * Rebuild a single segment's customer list.
     */
    public function rebuildSegment(Segment $segment): int
    {
        $segmentOwner = OwnerContext::fromTypeAndId($segment->owner_type, $segment->owner_id);

        return OwnerContext::withOwner($segmentOwner, function () use ($segment, $segmentOwner): int {
            if (! $segment->is_automatic) {
                return $segment->customers()->count();
            }

            $matchingCustomers = $segment->getMatchingCustomers();
            $currentCustomerIds = $segment->customers()->pluck('id')->toArray();
            $newCustomerIds = $matchingCustomers->pluck('id')->toArray();

            // Find customers added and removed
            $addedIds = array_diff($newCustomerIds, $currentCustomerIds);
            $removedIds = array_diff($currentCustomerIds, $newCustomerIds);

            // Sync the segment
            $segment->customers()->sync($newCustomerIds);

            $changedIds = array_values(array_unique(array_merge($addedIds, $removedIds)));
            if ($changedIds === []) {
                return count($newCustomerIds);
            }

            // Fire events for changes (batch-loaded)
            $customersById = Customer::query()
                ->forOwner($segmentOwner, includeGlobal: false)
                ->whereIn('id', $changedIds)
                ->get()
                ->keyBy('id');

            foreach ($addedIds as $customerId) {
                /** @var Customer|null $customer */
                $customer = $customersById->get($customerId);
                if ($customer !== null) {
                    event(new CustomerSegmentChanged($customer, $segment, 'added'));
                }
            }

            foreach ($removedIds as $customerId) {
                /** @var Customer|null $customer */
                $customer = $customersById->get($customerId);
                if ($customer !== null) {
                    event(new CustomerSegmentChanged($customer, $segment, 'removed'));
                }
            }

            return count($newCustomerIds);
        });
    }

    /**
     * Evaluate and update segment memberships for a single customer.
     *
     * @return Collection<int, Segment> Segments the customer now belongs to
     */
    public function evaluateCustomer(Customer $customer): Collection
    {
        if (! config('customers.features.segments.auto_assign', true)) {
            return collect();
        }

        $customerOwner = OwnerContext::fromTypeAndId($customer->owner_type, $customer->owner_id);

        $automaticSegments = Segment::query()
            ->active()
            ->automatic()
            ->forOwner($customerOwner, includeGlobal: false)
            ->get();

        $matchingSegments = collect();

        foreach ($automaticSegments as $segment) {
            if ($this->customerMatchesSegment($customer, $segment)) {
                $matchingSegments->push($segment);
            }
        }

        // Get current automatic segments
        $currentAutoSegmentIds = $customer->segments()
            ->where('is_automatic', true)
            ->pluck('id')
            ->toArray();

        $newAutoSegmentIds = $matchingSegments->pluck('id')->toArray();

        // Sync only automatic segments (preserve manual ones)
        $manualSegmentIds = $customer->segments()
            ->where('is_automatic', false)
            ->pluck('id')
            ->toArray();

        $allSegmentIds = array_merge($manualSegmentIds, $newAutoSegmentIds);
        $customer->segments()->sync($allSegmentIds);

        // Fire events for changes
        $addedIds = array_diff($newAutoSegmentIds, $currentAutoSegmentIds);
        $removedIds = array_diff($currentAutoSegmentIds, $newAutoSegmentIds);

        $segmentsById = Segment::query()
            ->forOwner($customerOwner, includeGlobal: false)
            ->whereIn('id', array_values(array_unique(array_merge($addedIds, $removedIds))))
            ->get()
            ->keyBy('id');

        foreach ($addedIds as $segmentId) {
            /** @var Segment|null $segment */
            $segment = $segmentsById->get($segmentId);
            if ($segment !== null) {
                event(new CustomerSegmentChanged($customer, $segment, 'added'));
            }
        }

        foreach ($removedIds as $segmentId) {
            /** @var Segment|null $segment */
            $segment = $segmentsById->get($segmentId);
            if ($segment !== null) {
                event(new CustomerSegmentChanged($customer, $segment, 'removed'));
            }
        }

        return $matchingSegments;
    }

    /**
     * Check if a customer matches a segment's conditions.
     */
    public function customerMatchesSegment(Customer $customer, Segment $segment): bool
    {
        $conditions = $segment->conditions ?? [];

        if (empty($conditions)) {
            return false;
        }

        foreach ($conditions as $condition) {
            if (! $this->evaluateCondition($customer, $condition)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Add a customer to a manual segment.
     */
    public function addToSegment(Customer $customer, Segment $segment): bool
    {
        if (! $this->customerAndSegmentShareOwner($customer, $segment)) {
            return false;
        }

        if ($customer->segments()->whereKey($segment->id)->exists()) {
            return false;
        }

        $customer->segments()->attach($segment->id);
        event(new CustomerSegmentChanged($customer, $segment, 'added'));

        return true;
    }

    /**
     * Remove a customer from a segment.
     */
    public function removeFromSegment(Customer $customer, Segment $segment): bool
    {
        if (! $this->customerAndSegmentShareOwner($customer, $segment)) {
            return false;
        }

        if (! $customer->segments()->whereKey($segment->id)->exists()) {
            return false;
        }

        $customer->segments()->detach($segment->id);
        event(new CustomerSegmentChanged($customer, $segment, 'removed'));

        return true;
    }

    /**
     * Get segment statistics.
     *
     * @return array{customer_count: int, active_count: int, marketing_opted_in: int, marketing_opted_in_percentage: float}
     */
    public function getSegmentStats(Segment $segment): array
    {
        $customers = $segment->customers;

        if ($customers->isEmpty()) {
            return [
                'customer_count' => 0,
                'active_count' => 0,
                'marketing_opted_in' => 0,
                'marketing_opted_in_percentage' => 0.0,
            ];
        }

        $activeCount = $customers->where('status', 'active')->count();
        $marketingOptedIn = $customers->where('accepts_marketing', true)->count();

        return [
            'customer_count' => $customers->count(),
            'active_count' => $activeCount,
            'marketing_opted_in' => $marketingOptedIn,
            'marketing_opted_in_percentage' => round(($marketingOptedIn / $customers->count()) * 100, 1),
        ];
    }

    /**
     * Evaluate a single condition against a customer.
     *
     * Conditions can use 'value_numeric', 'value_boolean', 'value_status' keys (Filament form)
     * or a single 'value' key (legacy/API). We normalize before matching.
     *
     * @param  array<string, mixed>  $condition
     */
    protected function evaluateCondition(Customer $customer, array $condition): bool
    {
        $field = $condition['field'] ?? null;

        if ($field === null) {
            return true;
        }

        $value = $condition['value_numeric']
            ?? $condition['value_boolean']
            ?? $condition['value_status']
            ?? $condition['value']
            ?? null;

        if ($value === null) {
            return true;
        }

        return match ($field) {
            'accepts_marketing' => $customer->accepts_marketing === (bool) $value,
            'status' => $customer->status->value === $value,
            'created_days_ago' => $customer->created_at && $customer->created_at->lte(CarbonImmutable::now()->subDays((int) $value)),
            default => false,
        };
    }

    private function customerAndSegmentShareOwner(Customer $customer, Segment $segment): bool
    {
        if ($segment->owner_type === null && $segment->owner_id === null) {
            return $customer->owner_type === null && $customer->owner_id === null;
        }

        return $customer->owner_type === $segment->owner_type
            && $customer->owner_id === $segment->owner_id;
    }
}
