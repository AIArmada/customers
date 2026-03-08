<?php

declare(strict_types=1);

namespace AIArmada\Customers\Enums;

enum SegmentType: string
{
    case Loyalty = 'loyalty';
    case Behavior = 'behavior';
    case Demographic = 'demographic';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Loyalty => __('customers::enums.segment_type.loyalty'),
            self::Behavior => __('customers::enums.segment_type.behavior'),
            self::Demographic => __('customers::enums.segment_type.demographic'),
            self::Custom => __('customers::enums.segment_type.custom'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Loyalty => 'warning',
            self::Behavior => 'info',
            self::Demographic => 'gray',
            self::Custom => 'primary',
        };
    }
}
