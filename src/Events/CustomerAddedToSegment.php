<?php

declare(strict_types=1);

namespace AIArmada\Customers\Events;

use AIArmada\Customers\Models\Customer;
use AIArmada\Customers\Models\Segment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CustomerAddedToSegment
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Customer $customer,
        public Segment $segment
    ) {}
}
