<?php

declare(strict_types=1);

namespace AIArmada\Customers\Support;

use AIArmada\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Model;

final class CustomerProfileNormalizer
{
    /**
     * @param  array<string, mixed>  $billingData
     * @param  array<string, mixed>  $shippingData
     * @return array{0: string, 1: string}|null
     */
    public function resolveNameParts(array $billingData, array $shippingData, ?Model $user): ?array
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
                ?? $user?->getAttribute('name'),
        );

        return $name === null ? null : $this->splitName($name);
    }

    /**
     * @param  array<string, mixed>  $billingData
     * @param  array<string, mixed>  $shippingData
     * @return array{0: string, 1: string}
     */
    public function resolveNamePartsOrDefault(
        array $billingData,
        array $shippingData,
        ?Model $user,
        string $fallbackFirstName = 'Guest',
    ): array {
        return $this->resolveNameParts($billingData, $shippingData, $user)
            ?? [$fallbackFirstName, ''];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function splitName(?string $name, string $fallbackFirstName = 'Guest'): array
    {
        $name = $this->cleanString($name);

        if ($name === null) {
            return [$fallbackFirstName, ''];
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $firstName = $parts[0] ?? $name;
        $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

        return [$firstName, $lastName];
    }

    public function normalizeEmail(mixed $value): ?string
    {
        $value = $this->cleanString($value);

        return $value === null ? null : Customer::normalizeEmail($value);
    }

    public function normalizePhone(mixed $value): ?string
    {
        return $this->cleanString($value);
    }

    public function cleanString(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $value = mb_trim((string) $value);

        return $value === '' ? null : $value;
    }
}
