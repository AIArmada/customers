<?php

declare(strict_types=1);

namespace AIArmada\Customers\Actions;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\Addressable;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Contacting\Models\ContactMethod;
use AIArmada\Contacting\Models\SocialProfile;
use AIArmada\Customers\Events\CustomerUpdated;
use AIArmada\Customers\Models\Customer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MergeCustomers
{
    public function execute(Customer $target, Customer $source): Customer
    {
        if (! $this->customersShareOwnerContext($source, $target)) {
            throw new InvalidArgumentException('Cannot merge customers across different owner contexts.');
        }

        $owner = $source->owner ?? $target->owner ?? null;

        return OwnerContext::withOwner($owner, function () use ($source, $target): Customer {
            DB::transaction(function () use ($source, $target): void {
                $this->moveAddresses($source, $target);
                $this->moveContactMethods($source, $target);
                $this->moveSocialProfiles($source, $target);
                $this->mergeSegments($source, $target);
                $this->mergeGroups($source, $target);
                $this->moveNotes($source, $target);

                if (! empty($source->metadata) && empty($target->metadata)) {
                    $target->metadata = $source->metadata;
                    $target->save();
                }

                $source->delete();
            });

            $mergedCustomer = $target->refresh();
            event(new CustomerUpdated($mergedCustomer));

            return $mergedCustomer;
        });
    }

    private function moveAddresses(Customer $source, Customer $target): void
    {
        $targetHasPrimary = [
            'billing' => $target->primaryAddress('billing') !== null,
            'shipping' => $target->primaryAddress('shipping') !== null,
        ];

        $source->loadMissing('addresses');

        foreach ($source->addresses as $address) {
            $pivot = $address->pivot;

            if (! $pivot instanceof Addressable) {
                continue;
            }

            $type = (string) $pivot->type;
            $targetHasPrimary[$type] ??= $target->primaryAddress($type) !== null;

            $duplicate = $this->findDuplicateAddress($target, $address, $type);

            if ($duplicate instanceof Address) {
                if ((bool) $pivot->is_primary && ! $targetHasPrimary[$type]) {
                    $target->setPrimaryAddress($duplicate, type: $type);
                    $targetHasPrimary[$type] = true;
                }

                continue;
            }

            $isPrimary = (bool) $pivot->is_primary && ! $targetHasPrimary[$type];

            $target->attachAddress(
                address: $address,
                type: $type,
                isPrimary: $isPrimary,
                label: $pivot->label ?? $address->label,
            );

            if ($isPrimary) {
                $targetHasPrimary[$type] = true;
            }
        }
    }

    private function findDuplicateAddress(Customer $customer, Address $address, string $type): ?Address
    {
        $countryCode = $this->resolveAddressCountryCode($address);

        $query = $customer->addresses()
            ->wherePivot('type', $type)
            ->where('line1', $address->line1)
            ->where('city', $address->city)
            ->where('postcode', $address->postcode);

        if ($countryCode === null) {
            $query->whereNull('country_code');
        } else {
            $query->where('country_code', $countryCode);
        }

        if ($address->line2 === null) {
            $query->whereNull('line2');
        } else {
            $query->where('line2', $address->line2);
        }

        if ($address->state === null) {
            $query->whereNull('state');
        } else {
            $query->where('state', $address->state);
        }

        return $query->first();
    }

    private function moveContactMethods(Customer $source, Customer $target): void
    {
        $source->loadMissing('contactMethods');

        foreach ($source->contactMethods as $contactMethod) {
            if ($contactMethod->is_primary && $this->targetHasPrimaryContactMethod($target, $contactMethod)) {
                $contactMethod->is_primary = false;
            }

            $contactMethod->contactable()->associate($target);
            $contactMethod->save();
        }
    }

    private function moveSocialProfiles(Customer $source, Customer $target): void
    {
        $source->loadMissing('socialProfiles');

        foreach ($source->socialProfiles as $socialProfile) {
            if ($socialProfile->is_primary && $this->targetHasPrimarySocialProfile($target, $socialProfile)) {
                $socialProfile->is_primary = false;
            }

            $socialProfile->socialable()->associate($target);
            $socialProfile->save();
        }
    }

    private function targetHasPrimaryContactMethod(Customer $target, ContactMethod $contactMethod): bool
    {
        return $target->contactMethods()
            ->where('type', $contactMethod->type)
            ->where('purpose', $contactMethod->purpose)
            ->where('is_primary', true)
            ->exists();
    }

    private function targetHasPrimarySocialProfile(Customer $target, SocialProfile $socialProfile): bool
    {
        return $target->socialProfiles()
            ->where('platform', $socialProfile->platform)
            ->where('purpose', $socialProfile->purpose)
            ->where('is_primary', true)
            ->exists();
    }

    private function mergeSegments(Customer $source, Customer $target): void
    {
        $segmentsRelation = $source->segments();
        $segmentIds = $segmentsRelation
            ->pluck($segmentsRelation->getRelated()->qualifyColumn($segmentsRelation->getRelatedKeyName()))
            ->all();

        if ($segmentIds !== []) {
            $target->segments()->syncWithoutDetaching($segmentIds);
        }
    }

    private function mergeGroups(Customer $source, Customer $target): void
    {
        $groupsRelation = $source->groups();
        $groupIds = $groupsRelation
            ->pluck($groupsRelation->getRelated()->qualifyColumn($groupsRelation->getRelatedKeyName()))
            ->all();

        if ($groupIds !== []) {
            $target->groups()->syncWithoutDetaching($groupIds);
        }
    }

    private function moveNotes(Customer $source, Customer $target): void
    {
        $source->notes()->update(['customer_id' => $target->id]);
    }

    private function resolveAddressCountryCode(Address $address): ?string
    {
        $countryCode = $this->cleanString($address->country_code ?? null)
            ?? $this->cleanString($address->country ?? null);

        return $countryCode === null ? null : mb_strtoupper($countryCode);
    }

    private function cleanString(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $value = mb_trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function customersShareOwnerContext(Customer $source, Customer $target): bool
    {
        if ($source->owner_type === null && $source->owner_id === null) {
            return $target->owner_type === null && $target->owner_id === null;
        }

        return $source->owner_type === $target->owner_type
            && $source->owner_id === $target->owner_id;
    }
}
