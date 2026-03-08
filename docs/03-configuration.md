---
title: Configuration
---

# Configuration

The customers package configuration is located in `config/customers.php`.

## Database Configuration

### Tables

Configure custom table names:

```php
'database' => [
    'table_prefix' => '', // Optional prefix for all tables
    'tables' => [
        'customers' => 'customers',
        'addresses' => 'customer_addresses',
        'segments' => 'customer_segments',
        'segment_customer' => 'customer_segment_customer',
        'groups' => 'customer_groups',
        'group_members' => 'customer_group_members',
        'wishlists' => 'wishlists',
        'wishlist_items' => 'wishlist_items',
        'notes' => 'customer_notes',
    ],
],
```

### JSON Column Type

Configure JSON column type for PostgreSQL or MySQL:

```php
'database' => [
    'json_column_type' => env('CUSTOMERS_JSON_COLUMN_TYPE', 'json'),
    // Use 'jsonb' for PostgreSQL for better performance
],
```

## Defaults

### Wallet Configuration

```php
'defaults' => [
    'wallet' => [
        'currency' => 'MYR', // ISO 4217 currency code
        'max_balance' => 100000_00, // Maximum balance in cents
        'min_topup' => 10_00, // Minimum topup amount in cents
    ],
],
```

**Important**: All amounts are stored in cents (e.g., RM 100.00 = 10000 cents).

### Wishlist Limits

```php
'defaults' => [
    'wishlists' => [
        'max_items_per_wishlist' => 100, // Maximum items per wishlist
    ],
],
```

## Features

### Owner (Multi-Tenancy)

```php
'features' => [
    'owner' => [
        'enabled' => env('CUSTOMERS_OWNER_ENABLED', true),
        'include_global' => env('CUSTOMERS_OWNER_INCLUDE_GLOBAL', false),
        'auto_assign_on_create' => env('CUSTOMERS_OWNER_AUTO_ASSIGN', true),
    ],
],
```

**Settings:**
- `enabled`: Enable multi-tenancy support
- `include_global`: Include global records in queries (owner_id = null)
- `auto_assign_on_create`: Automatically assign current owner on model creation

### Automatic Segmentation

```php
'features' => [
    'segments' => [
        'auto_assign' => true, // Automatically assign customers to matching segments
    ],
],
```

When enabled, customers are automatically added/removed from automatic segments based on their attributes.

### Wallet Feature

```php
'features' => [
    'wallet' => [
        'enabled' => true, // Enable store credit functionality
    ],
],
```

### Wishlist Feature

```php
'features' => [
    'wishlists' => [
        'enabled' => true, // Enable wishlist functionality
        'allow_public' => true, // Allow public wishlist sharing
    ],
],
```

## Integrations

### User Model

Link to your application's user model:

```php
'integrations' => [
    'user_model' => null, // Defaults to config('auth.providers.users.model')
],
```

If not set, the package uses Laravel's default user model from auth config.

## Environment Variables

You can override configuration via environment variables:

```bash
# .env
CUSTOMERS_OWNER_ENABLED=true
CUSTOMERS_OWNER_INCLUDE_GLOBAL=false
CUSTOMERS_OWNER_AUTO_ASSIGN=true
CUSTOMERS_JSON_COLUMN_TYPE=json
```

## Usage Examples

### Accessing Configuration

```php
// Get wallet currency
$currency = config('customers.defaults.wallet.currency'); // 'MYR'

// Get max balance
$maxBalance = config('customers.defaults.wallet.max_balance'); // 100000_00

// Check if owner mode is enabled
$ownerEnabled = config('customers.features.owner.enabled'); // true

// Get table name
$tableName = config('customers.database.tables.customers'); // 'customers'
```

### Dynamic Table Resolution

Models automatically resolve table names from config:

```php
use AIArmada\Customers\Models\Customer;

$table = (new Customer)->getTable(); // Uses config value
```

## Performance Optimization

### Database Indexes

The migrations include optimized indexes for common queries:

```php
// Customers table
$table->index(['status', 'accepts_marketing']);
$table->index('lifetime_value');
$table->index('last_order_at');

// Addresses table
$table->index(['customer_id', 'type']);
$table->index(['customer_id', 'is_default_billing']);
$table->index(['customer_id', 'is_default_shipping']);

// Segments table
$table->index(['is_active', 'priority']);
$table->index('type');
```

### JSON Column Optimization

For PostgreSQL, use `jsonb` for better performance:

```php
'database' => [
    'json_column_type' => 'jsonb',
],
```

Then add GIN indexes on metadata columns (manual migration):

```php
Schema::table('customers', function (Blueprint $table) {
    $table->index('metadata')->algorithm('gin');
});
```

## Security Considerations

### Wallet Limits

Set appropriate wallet limits to prevent abuse:

```php
'defaults' => [
    'wallet' => [
        'max_balance' => 50000_00, // RM 500 max for standard customers
        'min_topup' => 10_00, // RM 10 minimum to prevent spam
    ],
],
```

### Owner Scoping

Always enable owner scoping in multi-tenant applications:

```php
'features' => [
    'owner' => [
        'enabled' => true,
        'include_global' => false, // Strict isolation
    ],
],
```

## Next Steps

- [Usage](04-usage.md) - Learn how to use the package
- [Models](05-models.md) - Detailed model documentation
