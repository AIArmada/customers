<?php

declare(strict_types=1);

namespace AIArmada\Customers\Console\Commands;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Customers\Models\Segment;
use AIArmada\Customers\Services\SegmentationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Artisan command to rebuild automatic customer segments.
 *
 * Usage:
 *   php artisan customers:rebuild-segments          # Rebuild all segments
 *   php artisan customers:rebuild-segments --segment=uuid  # Rebuild specific segment
 */
class RebuildSegmentsCommand extends Command
{
    protected $signature = 'customers:rebuild-segments
                            {--segment= : Specific segment UUID to rebuild}
                            {--owner-type= : Owner morph type (from morph map) for scoping}
                            {--owner-id= : Owner ID for scoping}
                            {--all-owners : Rebuild segments for every distinct owner (and global)}
                            {--dry-run : Show what would be done without making changes}';

    protected $description = 'Rebuild automatic customer segment memberships';

    public function handle(SegmentationService $service): int
    {
        $segmentId = $this->option('segment');
        $dryRun = $this->option('dry-run');
        $allOwners = (bool) $this->option('all-owners');

        $owner = $this->resolveOwnerFromContextOrOptions();

        if ($dryRun) {
            $this->components->warn('Running in dry-run mode. No changes will be made.');
        }

        return OwnerContext::withOwner($owner, function () use ($segmentId, $service, $dryRun, $owner, $allOwners): int {
            if ($segmentId) {
                return $this->rebuildSingleSegment($service, $segmentId, $dryRun, $owner);
            }

            return $this->rebuildAllSegments($service, $dryRun, $owner, $allOwners);
        });
    }

    protected function rebuildSingleSegment(SegmentationService $service, string $segmentId, bool $dryRun, ?Model $owner): int
    {
        $segment = Segment::find($segmentId);

        if (! $segment) {
            $this->components->error("Segment with ID '{$segmentId}' not found.");

            return self::FAILURE;
        }

        if (! $segment->is_automatic) {
            $this->components->warn("Segment '{$segment->name}' is manual and cannot be rebuilt automatically.");

            return self::SUCCESS;
        }

        if ($owner === null && $segment->owner_type !== null && $segment->owner_id !== null) {
            $this->components->error('Refusing to rebuild an owner-scoped segment without an owner context.');
            $this->components->warn('Pass --owner-type and --owner-id, or use --all-owners.');

            return self::FAILURE;
        }

        if ($owner !== null && ! $segment->belongsToOwner($owner)) {
            $this->components->error('Refusing to rebuild a segment outside the resolved owner context.');

            return self::FAILURE;
        }

        $this->components->info("Rebuilding segment: {$segment->name}");

        if ($dryRun) {
            $matchCount = $segment->getMatchingCustomers()->count();
            $currentCount = $segment->customers()->count();
            $this->components->twoColumnDetail($segment->name, "{$matchCount} matching (currently {$currentCount})");

            return self::SUCCESS;
        }

        $count = $service->rebuildSegment($segment);
        $this->components->twoColumnDetail($segment->name, "{$count} customers");
        $this->components->success('Segment rebuilt successfully.');

        return self::SUCCESS;
    }

    protected function rebuildAllSegments(SegmentationService $service, bool $dryRun, ?Model $owner, bool $allOwners): int
    {
        if ($allOwners) {
            return $this->rebuildAllOwners($service, $dryRun);
        }

        $segments = Segment::query()
            ->active()
            ->automatic()
            ->forOwner($owner, includeGlobal: false)
            ->get();

        if ($segments->isEmpty()) {
            $this->components->info('No automatic segments found.');

            return self::SUCCESS;
        }

        $this->components->info("Rebuilding {$segments->count()} automatic segments...");

        $results = [];

        foreach ($segments as $segment) {
            if ($dryRun) {
                $matchCount = $segment->getMatchingCustomers()->count();
                $currentCount = $segment->customers()->count();
                $results[$segment->name] = "{$matchCount} matching (currently {$currentCount})";
            } else {
                $count = $service->rebuildSegment($segment);
                $results[$segment->name] = "{$count} customers";
            }
        }

        $this->newLine();
        $this->components->bulletList(
            collect($results)->map(fn ($count, $name) => "{$name}: {$count}")->toArray()
        );
        $this->newLine();

        if (! $dryRun) {
            $this->components->success('All segments rebuilt successfully.');
        }

        return self::SUCCESS;
    }

    private function rebuildAllOwners(SegmentationService $service, bool $dryRun): int
    {
        $owners = Segment::query()
            ->withoutOwnerScope()
            ->active()
            ->automatic()
            ->select(['owner_type', 'owner_id'])
            ->distinct()
            ->get();

        if ($owners->isEmpty()) {
            $this->components->info('No automatic segments found.');

            return self::SUCCESS;
        }

        $results = [];

        foreach ($owners as $row) {
            /** @var string|null $ownerType */
            $ownerType = $row->getAttribute('owner_type');
            /** @var string|int|null $ownerId */
            $ownerId = $row->getAttribute('owner_id');

            $owner = null;

            if ($ownerType !== null && $ownerId !== null) {
                $owner = $this->resolveOwnerFromTypeAndId($ownerType, (string) $ownerId);
            }

            $label = $this->ownerLabel($ownerType, $ownerId);

            $result = OwnerContext::withOwner($owner, function () use ($service, $dryRun, $owner): int {
                if ($dryRun) {
                    return Segment::query()
                        ->active()
                        ->automatic()
                        ->forOwner($owner, includeGlobal: false)
                        ->count();
                }

                $rebuilt = $service->rebuildAllSegments($owner);

                return array_sum($rebuilt);
            });

            $results[$label] = $dryRun ? "{$result} segment(s)" : (string) $result;
        }

        $this->newLine();
        $this->components->bulletList(
            collect($results)->map(fn ($count, $name) => "{$name}: {$count}")->toArray()
        );
        $this->newLine();

        if (! $dryRun) {
            $this->components->success('All segments rebuilt successfully.');
        }

        return self::SUCCESS;
    }

    private function resolveOwnerFromContextOrOptions(): ?Model
    {
        $owner = OwnerContext::resolve();
        if ($owner !== null) {
            return $owner;
        }

        $ownerType = $this->option('owner-type');
        $ownerId = $this->option('owner-id');

        if (! $ownerType || ! $ownerId) {
            return null;
        }

        return $this->resolveOwnerFromTypeAndId((string) $ownerType, (string) $ownerId);
    }

    private function resolveOwnerFromTypeAndId(string $ownerType, string $ownerId): ?Model
    {
        $class = Relation::getMorphedModel($ownerType) ?? (class_exists($ownerType) ? $ownerType : null);

        if ($class === null || ! is_subclass_of($class, Model::class)) {
            $this->components->warn("Unknown owner type: {$ownerType}");

            return null;
        }

        /** @var Model|null $owner */
        $owner = $class::query()->find($ownerId);

        if ($owner === null) {
            $this->components->warn("Owner not found: {$ownerType}:{$ownerId}");
        }

        return $owner;
    }

    private function ownerLabel(?string $ownerType, string | int | null $ownerId): string
    {
        if ($ownerType === null || $ownerId === null) {
            return 'global';
        }

        return "{$ownerType}:{$ownerId}";
    }
}
