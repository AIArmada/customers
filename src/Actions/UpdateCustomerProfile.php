<?php

declare(strict_types=1);

namespace AIArmada\Customers\Actions;

use AIArmada\Contacting\Data\ContactMethodData;
use AIArmada\Customers\Models\Customer;
use AIArmada\Customers\Support\CustomerProfileNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
        ?string $personId = null,
    ): void {
        $normalizer = app(CustomerProfileNormalizer::class);

        DB::transaction(function () use ($billingData, $customer, $normalizer, $shippingData, $user): void {
            $updates = [];

            $nameParts = $normalizer->resolveNameParts($billingData, $shippingData, $user);

            if ($nameParts !== null) {
                [$firstName, $lastName] = $nameParts;
                $updates['first_name'] = $firstName;
                $updates['last_name'] = $lastName;
            }

            $email = $normalizer->cleanString($billingData['email'] ?? $shippingData['email'] ?? null);

            $phone = $normalizer->normalizePhone($billingData['phone'] ?? null)
                ?? $normalizer->normalizePhone($shippingData['phone'] ?? null)
                ?? $normalizer->normalizePhone($user?->getAttribute('phone'));

            $company = $normalizer->cleanString($billingData['company'] ?? null)
                ?? $normalizer->cleanString($shippingData['company'] ?? null);

            if ($company !== null) {
                $updates['company'] = $company;
            }

            if ($updates !== []) {
                $customer->fill($updates);

                if ($customer->isDirty()) {
                    $customer->save();
                }
            }

            if ($email !== null) {
                $normalizedEmail = $normalizer->normalizeEmail($email);

                if ($normalizedEmail !== null) {
                    $customer->addContactMethod(new ContactMethodData(
                        type: 'email',
                        purpose: 'general',
                        value: $normalizedEmail,
                        isPrimary: true,
                    ));
                }
            }

            if ($phone !== null) {
                $customer->addContactMethod(ContactMethodData::phone(
                    $phone,
                    countryCode: config('contacting.defaults.country_code', 'MY'),
                    purpose: 'general',
                ));
            }
        });

        if ($personId !== null) {
            app(LinkCustomerToPerson::class)->executeByKey($customer, $personId);
        }
    }
}
