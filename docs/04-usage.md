---
title: Usage Guide
---

# Usage Guide

## Actions

The package provides action classes for common customer workflows:

```php
use AIArmada\Customers\Actions\CreateCustomer;
use AIArmada\Customers\Actions\UpdateCustomerProfile;
use AIArmada\Customers\Actions\AssignCustomerToSegment;
use AIArmada\Customers\Actions\RemoveCustomerFromSegment;
use AIArmada\Customers\Actions\RebuildAllSegments;

// Create a new customer
$customer = CreateCustomer::run(
    email: 'john@example.com',
    billingData: ['first_name' => 'John', 'last_name' => 'Doe'],
    shippingData: [],
    user: auth()->user(),
    isGuest: true,
);

// Update an existing customer's profile from checkout payloads
UpdateCustomerProfile::run(
    customer: $customer,
    billingData: ['phone' => '+60123456789'],
    shippingData: [],
    user: auth()->user(),
);

// Assign a customer to a segment (validates owner context)
AssignCustomerToSegment::run(customer: $customer, segment: $segment);

// Remove a customer from a segment (validates owner context)
RemoveCustomerFromSegment::run(customer: $customer, segment: $segment);

// Rebuild automatic segments for a specific owner
RebuildAllSegments::run()->forOwner($owner);

// Rebuild automatic segments across all owners
RebuildAllSegments::run()->forAllOwners();
```

## Creating Customers

### Basic Customer Creation

```php
use AIArmada\Customers\Models\Customer;
use AIArmada\Customers\Enums\CustomerStatus;

$customer = Customer::create([
    'first_name' => 'John',
    'last_name' => 'Doe',
    'email' => 'john@example.com',
    'phone' => '+60123456789',
    'company' => 'Acme Corp',
    'status' => CustomerStatus::Active,
    'accepts_marketing' => true,
]);

echo "Created: {$customer->full_name}"; // "John Doe"
```

### Link to User

```php
$customer = Customer::create([
    'user_id' => $user->id,
    'first_name' => $user->name,
    'last_name' => '',
    'email' => $user->email,
]);
```

Or use the trait on your User model:

```php
$customer = $user->getOrCreateCustomerProfile();
```

### Customer with Address

```php
$customer = Customer::create([
    'first_name' => 'Jane',
    'last_name' => 'Smith',
    'email' => 'jane@example.com',
]);

$customer->addresses()->create([
    'type' => AddressType::Both,
    'line1' => '123 Main Street',
    'city' => 'Kuala Lumpur',
    'postcode' => '50000',
    'country' => 'MY',
    'is_default_billing' => true,
    'is_default_shipping' => true,
]);
```

## Checkout and Payment Subject Resolution

When `aiarmada/checkout` and `aiarmada/commerce-support` are installed, this package helps resolve the customer and payment subject before payment is created and materialize the customer after payment when checkout asks for persistence.

### Resolving a Customer from Checkout Payloads

Use `CustomerResolver` when you need to turn an authenticated user, an existing session customer, and billing/shipping payloads into the best available `Customer` record.

```php
use AIArmada\Customers\Services\CustomerResolver;

$customer = app(CustomerResolver::class)->resolve(
    user: auth()->user(),
    sessionCustomer: $checkoutSession->customer,
    billingData: $checkoutSession->billing_data ?? [],
    shippingData: $checkoutSession->shipping_data ?? [],
);
```

`CustomerResolver` handles the cases that matter during checkout:

- authenticated users reuse their existing customer profile when possible
- guest checkout sessions can be promoted to a linked customer once a user is present
- guest customers can be merged into the authenticated user's customer when both records share the same owner context
- billing and shipping payloads are used to keep customer profile fields and default addresses fresh

For direct-capable checkout flows, the package also exposes a read-only lookup path so checkout can reuse existing customers before payment without creating new guest records prematurely. Checkout then calls back into the full resolver after payment succeeds to create or sync the customer record.

If owner-scoping is enabled, pass the checkout session owner into the resolver so both customer creation and guest-customer reuse happen within the correct tenant boundary instead of relying purely on ambient context.

### Payment Subject Driver Integration

`CustomersServiceProvider` automatically registers `CustomersPaymentSubjectDriver` with Commerce Support's `PaymentSubjectResolverInterface` after the container boots.

That driver runs before the guest fallback driver and returns a `ResolvedPaymentSubject` with:

- the resolved `Customer` model as the payment subject
- a normalized `PaymentCustomerData` object for the gateway payload
- guest-vs-authenticated context via `isGuest`

When checkout calls the driver from `checkout.resolve_customer`, the driver stays read-only for direct-capable guest flows and falls through to the guest payment-subject driver if no persisted customer already exists. When checkout calls it from its post-payment persistence phase, the driver uses the full `CustomerResolver` workflow and can create, promote, or merge the `Customer`.

```php
use AIArmada\CommerceSupport\Contracts\Payment\PaymentSubjectContext;
use AIArmada\CommerceSupport\Contracts\Payment\PaymentSubjectResolverInterface;

$resolved = app(PaymentSubjectResolverInterface::class)->resolve(
    new PaymentSubjectContext(
        gateway: 'cashier-chip',
        actor: auth()->user(),
        sessionCustomer: $checkoutSession->customer,
        sessionBillable: $checkoutSession->billable,
        billingData: $checkoutSession->billing_data ?? [],
        shippingData: $checkoutSession->shipping_data ?? [],
        metadata: ['checkout_session_id' => $checkoutSession->id],
        owner: $checkoutSession->hasOwner() ? $checkoutSession->owner : null,
        source: 'checkout.resolve_customer',
    )
);

$subject = $resolved?->subject;
$paymentCustomer = $resolved?->paymentCustomer;
```

The driver prefers customer defaults when the payload is incomplete. For example, it falls back to default billing and shipping addresses when the checkout payload does not supply `line1`, `city`, `postcode`, or `country`.

## Managing Customer Status

### Update Status

```php
use AIArmada\Customers\Enums\CustomerStatus;

$customer->update(['status' => CustomerStatus::Suspended]);
```

### Status Checks

```php
if ($customer->isActive()) {
    // Customer can place orders
}

if ($customer->isSuspended()) {
    // Customer is suspended
}

if ($customer->canPlaceOrders()) {
    // Status allows orders
}
```

### Query by Status

```php
// Get all active customers
$activeCustomers = Customer::active()->get();

// Get customers accepting marketing
$marketingList = Customer::acceptsMarketing()->get();
```

## Address Management

### Add Address

```php
use AIArmada\Customers\Enums\AddressType;

$address = $customer->addresses()->create([
    'type' => AddressType::Shipping,
    'label' => 'Home',
    'recipient_name' => 'John Doe',
    'line1' => '456 Oak Avenue',
    'line2' => 'Apt 3B',
    'city' => 'Petaling Jaya',
    'state' => 'Selangor',
    'postcode' => '46000',
    'country' => 'MY',
    'phone' => '+60123456789',
]);
```

### Set Default Addresses

```php
// Set as default billing
$address->setAsDefaultBilling();

// Set as default shipping
$address->setAsDefaultShipping();
```

### Get Default Addresses

```php
$billingAddress = $customer->getDefaultBillingAddress();
$shippingAddress = $customer->getDefaultShippingAddress();
```

### Format Address

```php
// Single line
echo $address->full_address;
// "456 Oak Avenue, Apt 3B, Petaling Jaya, Selangor, 46000, MY"

// Multi-line formatted
echo $address->getFormattedAddress();
/*
Home
John Doe
456 Oak Avenue
Apt 3B
Petaling Jaya Selangor 46000
MY
+60123456789
*/

// For shipping labels
$labelData = $address->toShippingLabel();
// ['name' => 'John Doe', 'line1' => '456 Oak Avenue', ...]
```

## Marketing Preferences

### Opt In/Out

```php
// Opt in to marketing
$customer->optInMarketing();

// Opt out of marketing
$customer->optOutMarketing();

// Check preference
if ($customer->acceptsMarketing()) {
    // Send marketing email
}
```

### Query Marketing List

```php
// Get all customers who accept marketing
$subscribers = Customer::acceptsMarketing()->get();

// Active customers who accept marketing
$activeSubscribers = Customer::active()
    ->acceptsMarketing()
    ->get();
```

## Customer Notes

### Add Note

```php
$note = $customer->notes()->create([
    'content' => 'Customer called about delivery',
    'created_by' => auth()->id(),
    'is_internal' => true, // Staff only
    'is_pinned' => false,
]);
```

### Pin Important Notes

```php
$note->pin();   // Pin to top
$note->unpin(); // Unpin
```

### Query Notes

```php
// Get all notes
$allNotes = $customer->notes; // Already ordered by latest

// Internal notes only
$internalNotes = $customer->notes()->internal()->get();

// Customer-visible notes
$publicNotes = $customer->notes()->visibleToCustomer()->get();

// Pinned notes
$pinnedNotes = $customer->notes()->pinned()->get();
```

## Customer Segments

### Manual Segment Assignment

```php
use AIArmada\Customers\Models\Segment;

$segment = Segment::where('slug', 'vip')->first();
$customer->segments()->attach($segment->id);

// Or use sync to replace all segments
$customer->segments()->sync([
    $vipSegment->id,
    $loyaltySegment->id,
]);
```

### Query by Segment

```php
// Get all VIP customers
$vipCustomers = Customer::inSegment('vip-segment-id')->get();

// Or by segment model
$vipCustomers = Customer::inSegment($vipSegment)->get();
```

### Using Segmentation Service

```php
use AIArmada\Customers\Services\SegmentationService;

$service = app(SegmentationService::class);

// Evaluate a single customer
$matchingSegments = $service->evaluateCustomer($customer);

// Add to manual segment
$service->addToSegment($customer, $segment);

// Remove from segment
$service->removeFromSegment($customer, $segment);

// Get segment statistics
$stats = $service->getSegmentStats($segment);
// Returns: ['customer_count' => 150]
```

### Available Segment Conditions

Automatic segments can use these conditions:

| Field | Operators | Description |
|-------|-----------|-------------|
| `accepts_marketing` | `=` | Boolean: customer accepts marketing |
| `status` | `=`, `!=` | Customer status enum value |
| `created_days_ago` | `>=`, `<=`, `>`, `<` | Days since customer creation |

### Rebuild Segments Command

```bash
# Rebuild all automatic segments
php artisan customers:rebuild-segments

# Rebuild a specific segment
php artisan customers:rebuild-segments --segment=vip-customers
```

## Customer Groups

### Create Group

```php
use AIArmada\Customers\Models\CustomerGroup;

$group = CustomerGroup::create([
    'name' => 'Corporate Buyers',
    'description' => 'B2B corporate purchasing group',
    'spending_limit' => 500000_00, // RM 5,000 monthly limit
    'is_active' => true,
    'requires_approval' => true,
]);
```

### Manage Members

```php
// Add member
$group->addMember($customer, 'member');

// Add admin
$group->addMember($customer, 'admin');

// Promote to admin
$group->promoteToAdmin($customer);

// Demote to member
$group->demoteToMember($customer);

// Remove member
$group->removeMember($customer);
```

### Check Membership

```php
if ($group->hasMember($customer)) {
    echo "Customer is a member";
}

if ($group->isAdmin($customer)) {
    echo "Customer is an admin";
}
```

## Tags

### Add Tags

```php
// Add single tag
$customer->attachTag('premium');

// Add multiple tags
$customer->attachTags(['premium', 'early-adopter', 'referrer']);

// Add tags for segmentation
$customer->tagForSegment(['high-value', 'frequent-buyer']);
```

### Query by Tag

```php
// Get customers with tag
$premiumCustomers = Customer::withSegmentTag('premium')->get();

// Get tags for customer
$tags = $customer->tags;
```

## Media & Files

### Upload Avatar

```php
$customer->addMedia($request->file('avatar'))
    ->toMediaCollection('avatar');

// Get avatar URL
$avatarUrl = $customer->getAvatarUrl();

// Get with conversion
$thumbUrl = $customer->getAvatarUrl('thumb');
```

### Upload Documents

```php
$customer->addMedia($request->file('document'))
    ->toMediaCollection('documents');

// Get all documents
$documents = $customer->getMedia('documents');
```

## Activity Logging

The package automatically logs customer activities:

```php
// Get activity logs
$activities = $customer->activities;

// Get specific activity
$latestActivity = $customer->activities()->latest()->first();

echo $latestActivity->description; // "updated"
echo $latestActivity->causer->name; // Who made the change
```

## Multi-Tenancy

### Owner Scoping

All models use the `HasOwner` trait for automatic owner scoping:

```php
// Customers are automatically scoped to current owner
$customers = Customer::all(); // Only current owner's customers

// Query specific owner
$customers = Customer::forOwner($owner)->get();

// Include global records
$customers = Customer::forOwner($owner, includeGlobal: true)->get();

// Global records only
$customers = Customer::globalOnly()->get();

// Bypass owner scoping (use with caution)
$allCustomers = Customer::withoutOwnerScope()->get();
```
