<?php

declare(strict_types=1);

namespace AIArmada\Customers;

use AIArmada\Customers\Console\Commands\RebuildSegmentsCommand;
use AIArmada\Customers\Models\Address;
use AIArmada\Customers\Models\Customer;
use AIArmada\Customers\Models\CustomerGroup;
use AIArmada\Customers\Models\CustomerNote;
use AIArmada\Customers\Models\Segment;
use AIArmada\Customers\Policies\AddressPolicy;
use AIArmada\Customers\Policies\CustomerGroupPolicy;
use AIArmada\Customers\Policies\CustomerNotePolicy;
use AIArmada\Customers\Policies\CustomerPolicy;
use AIArmada\Customers\Policies\SegmentPolicy;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class CustomersServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('customers')
            ->hasConfigFile('customers')
            ->discoversMigrations()
            ->hasTranslations()
            ->hasCommand(RebuildSegmentsCommand::class);
    }

    public function packageBooted(): void
    {
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Segment::class, SegmentPolicy::class);
        Gate::policy(Address::class, AddressPolicy::class);
        Gate::policy(CustomerNote::class, CustomerNotePolicy::class);
        Gate::policy(CustomerGroup::class, CustomerGroupPolicy::class);
    }
}
