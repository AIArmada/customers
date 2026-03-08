<?php

declare(strict_types=1);

namespace AIArmada\Customers\Enums;

enum CustomerStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
    case PendingVerification = 'pending_verification';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('customers::enums.status.active'),
            self::Inactive => __('customers::enums.status.inactive'),
            self::Suspended => __('customers::enums.status.suspended'),
            self::PendingVerification => __('customers::enums.status.pending_verification'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'gray',
            self::Suspended => 'danger',
            self::PendingVerification => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Active => 'heroicon-o-check-circle',
            self::Inactive => 'heroicon-o-minus-circle',
            self::Suspended => 'heroicon-o-no-symbol',
            self::PendingVerification => 'heroicon-o-clock',
        };
    }

    public function canPlaceOrders(): bool
    {
        return $this === self::Active;
    }
}
