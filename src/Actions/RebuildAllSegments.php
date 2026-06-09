<?php

declare(strict_types=1);

namespace AIArmada\Customers\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerTuple\OwnerTupleColumns;
use AIArmada\CommerceSupport\Support\OwnerTuple\OwnerTupleParser;
use AIArmada\Customers\Events\CustomerSegmentChanged;
use AIArmada\Customers\Models\Customer;
use AIArmada\Customers\Models\Segment;
use Illuminate\Database\Eloquent\Model;

final class RebuildAllSegments
{
    /**
     * Rebuild automatic segments for a specific owner.
     *
     * @return array<string, int> Map of segment names to customer counts
     */
    public function forOwner(?Model $owner = null): array
    {
        $segments = Segment::query()
            ->active()
            ->automatic()
            ->forOwner($owner, includeGlobal: false)
            ->get();

        $results = [];

        foreach ($segments as $segment) {
            $results[$segment->name] = $this->rebuildSegment($segment);
        }

        return $results;
    }

    /**
     * Rebuild automatic segments for every distinct owner (and global).
     *
     * @return array<string, array<string, int>> Map of owner labels to segment counts
     */
    public function forAllOwners(): array
    {
        $columns = OwnerTupleColumns::forModelClass(Segment::class);

        $owners = Segment::query()
            ->withoutOwnerScope()
            ->active()
            ->automatic()
            ->select([
                $columns->ownerTypeColumn . ' as owner_type',
                $columns->ownerIdColumn . ' as owner_id',
            ])
            ->distinct()
            ->get();

        $results = [];

        foreach ($owners as $row) {
            $tuple = OwnerTupleParser::fromRow($row, new OwnerTupleColumns);
            $owner = $tuple->toOwnerModel();

            $label = $this->ownerLabel($tuple->owner_type, $tuple->owner_id);

            $results[$label] = OwnerContext::withOwner($owner, fn (): array => $this->forOwner($owner));
        }

        return $results;
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

            $addedIds = array_diff($newCustomerIds, $currentCustomerIds);
            $removedIds = array_diff($currentCustomerIds, $newCustomerIds);

            $segment->customers()->sync($newCustomerIds);

            $changedIds = array_values(array_unique(array_merge($addedIds, $removedIds)));
            if ($changedIds === []) {
                return count($newCustomerIds);
            }

            $customersById = Customer::query()
                ->forOwner($segmentOwner, includeGlobal: false)
                ->whereIn('id', $changedIds)
                ->get()
                ->keyBy('id');

            foreach ($addedIds as $customerId) {
                $customer = $customersById->get($customerId);
                if ($customer !== null) {
                    event(new CustomerSegmentChanged($customer, $segment, 'added'));
                }
            }

            foreach ($removedIds as $customerId) {
                $customer = $customersById->get($customerId);
                if ($customer !== null) {
                    event(new CustomerSegmentChanged($customer, $segment, 'removed'));
                }
            }

            return count($newCustomerIds);
        });
    }

    private function ownerLabel(?string $ownerType, string | int | null $ownerId): string
    {
        if ($ownerType === null || $ownerId === null) {
            return 'global';
        }

        return "{$ownerType}:{$ownerId}";
    }
}
