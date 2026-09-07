<?php

declare(strict_types=1);

namespace AIArmada\Customers\Actions;

use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

final class LinkCustomerToPerson
{
    public function execute(Customer $customer, Model $person): Customer
    {
        $personClass = config('persons.models.person');

        if (! is_string($personClass) || ! class_exists($personClass)) {
            throw new LogicException('Configure persons.models.person before linking a customer.');
        }

        if (! $person instanceof $personClass || $person->getKey() === null) {
            throw new InvalidArgumentException('The linked model must be a persisted configured person model.');
        }

        /** @var Customer $ownedCustomer */
        $ownedCustomer = OwnerWriteGuard::findOrFailForOwner(Customer::class, $customer->getKey());
        $ownedCustomer->forceFill(['person_id' => $person->getKey()]);
        $ownedCustomer->save();

        return $ownedCustomer->refresh();
    }
}
