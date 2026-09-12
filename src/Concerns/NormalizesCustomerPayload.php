<?php

declare(strict_types=1);

namespace AIArmada\Customers\Concerns;

trait NormalizesCustomerPayload
{
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
