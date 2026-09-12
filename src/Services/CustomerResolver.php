<?php

declare(strict_types=1);

namespace AIArmada\Customers\Services;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Customers\Actions\CreateCustomer;
use AIArmada\Customers\Actions\MergeCustomers;
use AIArmada\Customers\Actions\UpdateCustomerProfile;
use AIArmada\Customers\Concerns\NormalizesCustomerPayload;
use AIArmada\Customers\Concerns\ResolvesCustomerIdentity;
use AIArmada\Customers\Concerns\SynchronizesCustomerAddresses;
use AIArmada\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CustomerResolver
{
    use NormalizesCustomerPayload;
    use ResolvesCustomerIdentity;
    use SynchronizesCustomerAddresses;

    public function __construct(
        private readonly CreateCustomer $createCustomer,
        private readonly UpdateCustomerProfile $updateCustomerProfile,
    ) {}

    /**
     * @param  array<string, mixed>  $billingData
     * @param  array<string, mixed>  $shippingData
     */
    public function resolveExisting(
        ?Model $user,
        ?Customer $sessionCustomer,
        array $billingData,
        array $shippingData,
        Model | string | null $owner = OwnerContext::CURRENT,
    ): ?Customer {
        return $this->runWithinOwnerContext($owner, function () use ($billingData, $sessionCustomer, $shippingData, $user): ?Customer {
            $email = $this->resolveEmail($billingData, $shippingData, $user, $sessionCustomer);

            if ($user !== null) {
                $userCustomer = $this->findUserCustomer($user);

                if (
                    $sessionCustomer !== null
                    && $userCustomer !== null
                    && $sessionCustomer->is_guest
                    && $sessionCustomer->id !== $userCustomer->id
                ) {
                    return $sessionCustomer;
                }

                if ($userCustomer !== null) {
                    return $userCustomer;
                }

                return $sessionCustomer;
            }

            if ($sessionCustomer !== null) {
                return $sessionCustomer;
            }

            if ($email === null) {
                return null;
            }

            return $this->findReusableGuestCustomerByEmail($email);
        });
    }

    /**
     * @param  array<string, mixed>  $billingData
     * @param  array<string, mixed>  $shippingData
     */
    public function resolve(
        ?Model $user,
        ?Customer $sessionCustomer,
        array $billingData,
        array $shippingData,
        Model | string | null $owner = OwnerContext::CURRENT,
    ): ?Customer {
        return $this->runWithinOwnerContext($owner, function () use ($billingData, $sessionCustomer, $shippingData, $user): ?Customer {
            return DB::transaction(function () use ($billingData, $sessionCustomer, $shippingData, $user): ?Customer {
                $email = $this->resolveEmail($billingData, $shippingData, $user, $sessionCustomer);

                if ($user !== null) {
                    $userCustomer = $this->findUserCustomer($user);

                    if ($userCustomer !== null) {
                        if ($sessionCustomer !== null && $sessionCustomer->is_guest && $sessionCustomer->id !== $userCustomer->id) {
                            if ($this->customersShareOwnerContext($sessionCustomer, $userCustomer)) {
                                $this->mergeCustomers($sessionCustomer, $userCustomer);
                            }
                        }

                        $this->updateCustomerProfile->execute($userCustomer, $billingData, $shippingData, $user);
                        $this->syncAddressesFromPayload($userCustomer, $billingData, $shippingData);

                        return $userCustomer;
                    }

                    if ($sessionCustomer !== null && $sessionCustomer->is_guest) {
                        $sessionCustomer->update([
                            'user_id' => $user->getKey(),
                            'is_guest' => false,
                        ]);

                        $this->updateCustomerProfile->execute($sessionCustomer, $billingData, $shippingData, $user);
                        $this->syncAddressesFromPayload($sessionCustomer, $billingData, $shippingData);

                        return $sessionCustomer;
                    }

                    if ($email === null) {
                        return null;
                    }

                    $emailCustomer = $this->findCustomerByEmail($email);

                    if ($emailCustomer !== null) {
                        if ($emailCustomer->user_id !== null && (string) $emailCustomer->user_id !== (string) $user->getKey()) {
                            return null;
                        }

                        if (
                            $sessionCustomer !== null
                            && $sessionCustomer->is_guest
                            && $sessionCustomer->id !== $emailCustomer->id
                            && $this->customersShareOwnerContext($sessionCustomer, $emailCustomer)
                        ) {
                            $this->mergeCustomers($sessionCustomer, $emailCustomer);
                        }

                        $emailCustomer->fill([
                            'user_id' => $user->getKey(),
                            'is_guest' => false,
                        ]);

                        if ($emailCustomer->isDirty()) {
                            $emailCustomer->save();
                        }

                        $this->updateCustomerProfile->execute($emailCustomer, $billingData, $shippingData, $user);
                        $this->syncAddressesFromPayload($emailCustomer, $billingData, $shippingData);

                        return $emailCustomer;
                    }

                    $customer = $this->createCustomer->execute($email, $billingData, $shippingData, $user, false);
                    $this->updateCustomerProfile->execute($customer, $billingData, $shippingData, $user);
                    $this->syncAddressesFromPayload($customer, $billingData, $shippingData);

                    return $customer;
                }

                if ($sessionCustomer !== null) {
                    $this->updateCustomerProfile->execute($sessionCustomer, $billingData, $shippingData, null);
                    $this->syncAddressesFromPayload($sessionCustomer, $billingData, $shippingData);

                    return $sessionCustomer;
                }

                if ($email === null) {
                    return null;
                }

                $existingCustomer = $this->findCustomerByEmail($email);

                if ($existingCustomer !== null) {
                    if (! $existingCustomer->is_guest || $existingCustomer->user_id !== null) {
                        return null;
                    }

                    $this->updateCustomerProfile->execute($existingCustomer, $billingData, $shippingData, null);
                    $this->syncAddressesFromPayload($existingCustomer, $billingData, $shippingData);

                    return $existingCustomer;
                }

                $customer = $this->createCustomer->execute($email, $billingData, $shippingData, null, true);
                $this->updateCustomerProfile->execute($customer, $billingData, $shippingData, null);
                $this->syncAddressesFromPayload($customer, $billingData, $shippingData);

                return $customer;
            });
        });
    }

    public function mergeCustomers(Customer $source, Customer $target): Customer
    {
        return app(MergeCustomers::class)->execute($target, $source);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function runWithinOwnerContext(Model | string | null $owner, callable $callback): mixed
    {
        if ($owner === OwnerContext::CURRENT) {
            return $callback();
        }

        if ($owner !== null && ! $owner instanceof Model) {
            throw new InvalidArgumentException('Owner resolver argument must be a model instance, null, or OwnerContext::CURRENT.');
        }

        return OwnerContext::withOwner($owner, $callback);
    }
}
