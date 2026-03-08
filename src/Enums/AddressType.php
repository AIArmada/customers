<?php

declare(strict_types=1);

namespace AIArmada\Customers\Enums;

enum AddressType: string
{
    case Billing = 'billing';
    case Shipping = 'shipping';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Billing => __('customers::enums.address_type.billing'),
            self::Shipping => __('customers::enums.address_type.shipping'),
            self::Both => __('customers::enums.address_type.both'),
        };
    }

    public function isBilling(): bool
    {
        return $this === self::Billing || $this === self::Both;
    }

    public function isShipping(): bool
    {
        return $this === self::Shipping || $this === self::Both;
    }
}
