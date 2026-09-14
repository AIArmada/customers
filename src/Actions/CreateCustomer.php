<?php

declare(strict_types=1);

namespace AIArmada\Customers\Actions;

use AIArmada\Contacting\Data\ContactMethodData;
use AIArmada\Customers\Models\Customer;
use AIArmada\Customers\Support\CustomerProfileNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class CreateCustomer
{
    /**
     * @param  array<string, mixed>  $billingData
     * @param  array<string, mixed>  $shippingData
     */
    public function execute(
        string $email,
        array $billingData,
        array $shippingData,
        ?Model $user,
        bool $isGuest,
        ?string $personId = null,
    ): Customer {
        $normalizer = app(CustomerProfileNormalizer::class);
        [$firstName, $lastName] = $normalizer->resolveNamePartsOrDefault($billingData, $shippingData, $user);

        $company = $normalizer->cleanString($billingData['company'] ?? null)
            ?? $normalizer->cleanString($shippingData['company'] ?? null);

        $phone = $normalizer->normalizePhone($billingData['phone'] ?? null)
            ?? $normalizer->normalizePhone($shippingData['phone'] ?? null)
            ?? $normalizer->normalizePhone($user?->getAttribute('phone'));
        $normalizedEmail = $normalizer->normalizeEmail($email);

        return DB::transaction(function () use ($company, $firstName, $isGuest, $lastName, $normalizedEmail, $personId, $phone, $user): Customer {
            $customer = Customer::query()->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'company' => $company,
            ]);

            $customer->forceFill([
                'user_id' => $user?->getKey(),
                'is_guest' => $isGuest,
            ])->save();

            if ($normalizedEmail !== null) {
                $customer->addContactMethod(new ContactMethodData(
                    type: 'email',
                    purpose: 'general',
                    value: $normalizedEmail,
                    isPrimary: true,
                ));
            }

            if ($phone !== null) {
                $customer->addContactMethod(ContactMethodData::phone(
                    $phone,
                    countryCode: config('contacting.defaults.country_code', 'MY'),
                    purpose: 'general',
                ));
            }

            if ($personId !== null) {
                return app(LinkCustomerToPerson::class)->executeByKey($customer, $personId);
            }

            return $customer;
        });
    }
}
