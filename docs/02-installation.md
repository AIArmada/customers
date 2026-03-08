---
title: Installation
---

# Installation

## Requirements

- PHP 8.4+
- Laravel 11+
- aiarmada/commerce-support package
- Spatie Media Library 11+
- Spatie Tags 4.2+

## Composer Installation

Install the package via Composer:

```bash
composer require aiarmada/customers
```

The package will auto-register via Laravel's package discovery.

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag="customers-config"
```

This creates `config/customers.php` with all available options.

## Migrations

Run the migrations to create the required database tables:

```bash
php artisan migrate
```

This creates the following tables:
- `customers` - Customer profiles
- `customer_addresses` - Customer addresses
- `customer_segments` - Customer segments
- `customer_segment_customer` - Segment membership pivot
- `customer_groups` - Customer buying groups
- `customer_group_members` - Group membership pivot
- `wishlists` - Customer wishlists
- `wishlist_items` - Wishlist products
- `customer_notes` - Customer notes

## Translations

Publish translations (optional):

```bash
php artisan vendor:publish --tag="customers-translations"
```

Supported languages:
- English (en)
- Malay (ms)

## Media Collections

The package uses Spatie Media Library for customer avatars and documents. Ensure you've set up Media Library according to its documentation.

## User Integration

To link customers to your User model, add the `HasCustomerProfile` trait:

```php
use AIArmada\Customers\Concerns\HasCustomerProfile;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasCustomerProfile;
    
    // ... rest of your User model
}
```

This provides convenient methods:
- `$user->customerProfile()` - Relationship to customer
- `$user->getOrCreateCustomerProfile()` - Get or create profile
- `$user->hasCustomerProfile()` - Check if profile exists
- `$user->getWalletBalance()` - Get wallet balance
- `$user->acceptsMarketing()` - Check marketing preferences

## Configuration Recommendations

### Production

For production environments, consider:

```php
// config/customers.php
return [
    'defaults' => [
        'wallet' => [
            'currency' => 'MYR',
            'max_balance' => 100000_00, // RM 100,000 in cents
            'min_topup' => 10_00, // RM 10 minimum topup
        ],
    ],
    'features' => [
        'owner' => [
            'enabled' => true, // Enable multi-tenancy
            'include_global' => false, // Don't include global by default
            'auto_assign_on_create' => true, // Auto-assign owner
        ],
    ],
];
```

### Development

For development/testing:

```php
return [
    'features' => [
        'owner' => [
            'enabled' => false, // Disable for simple testing
        ],
    ],
];
```

## Verification

Verify installation by creating a test customer:

```php
use AIArmada\Customers\Models\Customer;

$customer = Customer::create([
    'first_name' => 'John',
    'last_name' => 'Doe',
    'email' => 'john@example.com',
    'phone' => '+60123456789',
]);

echo "Customer created: {$customer->full_name}";
```

## Scheduled Tasks

If using automatic segmentation, add to your `routes/console.php` or `app/Console/Kernel.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('customers:rebuild-segments')
    ->daily()
    ->at('03:00');
```

This rebuilds automatic segments nightly to keep them up-to-date.

## Next Steps

- [Configuration](03-configuration.md) - Configure the package
- [Usage](04-usage.md) - Start using the package
