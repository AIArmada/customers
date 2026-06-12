<?php

declare(strict_types=1);

namespace AIArmada\Customers\Actions;

use AIArmada\Contacting\Data\ContactMethodData;
use AIArmada\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Model;

final class UpdateCustomerProfile
{
    /**
     * @param  array<string, mixed>  $billingData
     * @param  array<string, mixed>  $shippingData
     */
    public function execute(
        Customer $customer,
        array $billingData,
        array $shippingData,
        ?Model $user,
    ): void {
        $updates = [];

        $nameParts = $this->resolveProvidedNameParts($billingData, $shippingData, $user);

        if ($nameParts !== null) {
            [$firstName, $lastName] = $nameParts;
            $updates['first_name'] = $firstName;
            $updates['last_name'] = $lastName;
        }

        $company = $this->cleanString($billingData['company'] ?? null)
            ?? $this->cleanString($shippingData['company'] ?? null);

        if ($company !== null) {
            $updates['company'] = $company;
        }

        if ($updates !== []) {
            $customer->fill($updates);

            if ($customer->isDirty()) {
                $customer->save();
            }
        }

        $email = $this->cleanString($billingData['email'] ?? $shippingData['email'] ?? null);
        if ($email !== null) {
            $customer->addContactMethod(ContactMethodData::email($email, 'general'));
        }

        $phone = $this->cleanString($billingData['phone'] ?? null)
            ?? $this->cleanString($shippingData['phone'] ?? null)
            ?? $this->cleanString($user?->getAttribute('phone'));

        if ($phone !== null) {
            $customer->addContactMethod(ContactMethodData::phone($phone, countryCode: 'MY', purpose: 'general'));
        }
    }

    /**
     * @param  array<string, mixed>  $billingData
     * @param  array<string, mixed>  $shippingData
     * @return array{0: string, 1: string}|null
     */
    private function resolveProvidedNameParts(array $billingData, array $shippingData, ?Model $user): ?array
    {
        $firstName = $this->cleanString($billingData['first_name'] ?? $shippingData['first_name'] ?? null);
        $lastName = $this->cleanString($billingData['last_name'] ?? $shippingData['last_name'] ?? null);

        if ($firstName !== null || $lastName !== null) {
            return [$firstName ?? 'Guest', $lastName ?? ''];
        }

        $name = $this->cleanString(
            $billingData['name']
                ?? $billingData['full_name']
                ?? $shippingData['name']
                ?? $shippingData['full_name']
                ?? $user?->getAttribute('name')
        );

        return $name !== null ? $this->splitName($name) : null;
    }

    private function splitName(?string $name): array
    {
        $name = $this->cleanString($name) ?? '';

        if ($name === '') {
            return ['Guest', ''];
        }

        $parts = preg_split('/\s+/', $name) ?: [];

        $firstName = $parts[0] ?? $name;
        $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

        return [$firstName, $lastName];
    }

    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = mb_trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
