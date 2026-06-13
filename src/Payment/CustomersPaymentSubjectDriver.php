<?php

declare(strict_types=1);

namespace AIArmada\Customers\Payment;

use AIArmada\CommerceSupport\Contracts\Payment\PaymentCustomerData;
use AIArmada\CommerceSupport\Contracts\Payment\PaymentSubjectContext;
use AIArmada\CommerceSupport\Contracts\Payment\PaymentSubjectDriverInterface;
use AIArmada\CommerceSupport\Contracts\Payment\ResolvedPaymentSubject;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Customers\Models\Address;
use AIArmada\Customers\Models\Customer;
use AIArmada\Customers\Services\CustomerResolver;

final class CustomersPaymentSubjectDriver implements PaymentSubjectDriverInterface
{
    public function __construct(
        private readonly CustomerResolver $customerResolver,
    ) {}

    public function getIdentifier(): string
    {
        return 'customers';
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function supports(PaymentSubjectContext $context): bool
    {
        return $context->subject instanceof Customer
            || $context->sessionCustomer instanceof Customer
            || $context->actor !== null
            || $this->resolveEmail($context) !== null;
    }

    public function resolve(PaymentSubjectContext $context): ?ResolvedPaymentSubject
    {
        $sessionCustomer = $context->subject instanceof Customer
            ? $context->subject
            : ($context->sessionCustomer instanceof Customer ? $context->sessionCustomer : null);
        $owner = $context->owner;

        $customer = $this->shouldResolveWithoutPersistence($context)
            ? $this->customerResolver->resolveExisting(
                user: $context->actor,
                sessionCustomer: $sessionCustomer,
                billingData: $context->billingData,
                shippingData: $context->shippingData,
                owner: $owner,
            )
            : $this->customerResolver->resolve(
                user: $context->actor,
                sessionCustomer: $sessionCustomer,
                billingData: $context->billingData,
                shippingData: $context->shippingData,
                owner: $owner,
            );

        if ($customer === null) {
            return null;
        }

        return new ResolvedPaymentSubject(
            subject: $customer,
            paymentCustomer: OwnerContext::withOwner($owner, fn (): PaymentCustomerData => $this->toPaymentCustomer($customer, $context)),
            isGuest: $customer->isGuest(),
            resolvedBy: $this->getIdentifier(),
            metadata: $context->metadata,
        );
    }

    private function shouldResolveWithoutPersistence(PaymentSubjectContext $context): bool
    {
        if ($context->source !== 'checkout.resolve_customer') {
            return false;
        }

        return ! $this->requiresPersistedCustomerBeforePayment($context);
    }

    private function requiresPersistedCustomerBeforePayment(PaymentSubjectContext $context): bool
    {
        return $context->gateway === 'cashier';
    }

    private function toPaymentCustomer(Customer $customer, PaymentSubjectContext $context): PaymentCustomerData
    {
        $billingAddress = $this->resolveAddress(
            $context->billingData,
            $customer->getDefaultBillingAddress(),
        );
        $shippingAddress = $this->resolveAddress(
            $context->shippingData,
            $customer->getDefaultShippingAddress(),
        );
        $billingCountry = $billingAddress['country_code'] ?? $billingAddress['country'] ?? null;
        $shippingCountry = $shippingAddress['country_code'] ?? $shippingAddress['country'] ?? null;

        return new PaymentCustomerData(
            email: $this->resolveEmail($context) ?? $this->resolveCustomerEmail($customer),
            name: $customer->full_name,
            phone: $this->cleanString($context->billingData['phone'] ?? null)
                ?? $this->cleanString($context->shippingData['phone'] ?? null)
                ?? $this->resolveCustomerPhone($customer),
            country: $billingCountry ?? $shippingCountry ?? 'MY',
            billingStreetAddress: $billingAddress['line1'] ?? null,
            billingCity: $billingAddress['city'] ?? null,
            billingState: $billingAddress['state'] ?? null,
            billingPostalCode: $billingAddress['postcode'] ?? null,
            billingCountry: $billingCountry,
            shippingStreetAddress: $shippingAddress['line1'] ?? null,
            shippingCity: $shippingAddress['city'] ?? null,
            shippingState: $shippingAddress['state'] ?? null,
            shippingPostalCode: $shippingAddress['postcode'] ?? null,
            shippingCountry: $shippingCountry,
            metadata: array_filter([
                ...$context->metadata,
                'customer_id' => $customer->id,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string|null>
     */
    private function resolveAddress(array $payload, ?Address $defaultAddress): array
    {
        return [
            'line1' => $this->cleanString($payload['line1'] ?? null) ?? $defaultAddress?->line1,
            'city' => $this->cleanString($payload['city'] ?? null) ?? $defaultAddress?->city,
            'state' => $this->cleanString($payload['state'] ?? null) ?? $defaultAddress?->state,
            'postcode' => $this->cleanString($payload['postcode'] ?? null) ?? $defaultAddress?->postcode,
            'country_code' => $this->cleanString($payload['country_code'] ?? $payload['country'] ?? null)
                ?? $defaultAddress?->country_code
                ?? $defaultAddress?->country,
        ];
    }

    private function resolveEmail(PaymentSubjectContext $context): ?string
    {
        return $this->cleanString($context->billingData['email'] ?? null)
            ?? $this->cleanString($context->shippingData['email'] ?? null)
            ?? $this->cleanString($context->actor?->getAttribute('email'));
    }

    private function resolveCustomerEmail(Customer $customer): ?string
    {
        $email = $this->cleanString($customer->getAttribute('email'));

        if ($email !== null) {
            return mb_strtolower($email);
        }

        $emailContactMethod = $customer->contactMethods()
            ->where('type', 'email')
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->first();

        return $this->cleanString($emailContactMethod?->normalized_value ?? $emailContactMethod?->value);
    }

    private function resolveCustomerPhone(Customer $customer): ?string
    {
        $phone = $this->cleanString($customer->getAttribute('phone'));

        if ($phone !== null) {
            return $phone;
        }

        $phoneContactMethod = $customer->contactMethods()
            ->where('type', 'phone')
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->first();

        return $this->cleanString($phoneContactMethod?->normalized_value ?? $phoneContactMethod?->value);
    }

    private function cleanString(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $cleaned = mb_trim((string) $value);

        return $cleaned === '' ? null : $cleaned;
    }
}
