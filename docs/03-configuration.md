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
    'table_prefix' => 'customer_',
    'tables' => [
        'customers' => 'customers',
        'addresses' => 'customer_addresses',
        'segments' => 'customer_segments',
        'segment_customer' => 'customer_segment_customer',
        'groups' => 'customer_groups',
        'group_members' => 'customer_group_members',
        'notes' => 'customer_notes',
    ],
],
```

### JSON Column Type

The JSON column type is controlled by the `commerce_json_column_type('customers', 'jsonb')` helper, which reads the `COMMERCE_JSON_COLUMN_TYPE` env variable or the database default.

## Person linkage

The customers package does not configure or own the shared person model. The
`customers.person_id` column is a nullable, indexed UUID link with no database
foreign-key constraint. Use `LinkCustomerToPerson` so the customer is resolved
through the current owner context before the link is saved. The migration is
guarded and does not backfill existing customers.

## Contact methods

Customer email and phone values are stored only in Contacting's
`contact_methods` table. The customers tables have no native `email` or
`phone` columns. Use `Customer::addContactMethod()`,
`CreateContactMethodAction`, or the Contacting Filament relation manager.
Existing native customer contact columns are removed by the cutover migration
without a backfill.

## Features

### Owner (Multi-Tenancy)

The owner config lives under `customers.features.owner`.

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
        'auto_assign' => env('CUSTOMERS_SEGMENTS_AUTO_ASSIGN', true),
    ],
],
```

When enabled, customers are automatically added/removed from automatic segments based on their attributes.

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
CUSTOMERS_SEGMENTS_AUTO_ASSIGN=true
```

## Usage Examples

### Accessing Configuration

```php
// Check if auto segmentation is enabled
$autoAssignSegments = config('customers.features.segments.auto_assign'); // true

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
$table->index('is_guest');

// Addresses table
$table->index(['customer_id', 'type']);
$table->index(['customer_id', 'is_default_billing']);
$table->index(['customer_id', 'is_default_shipping']);

// Segments table: owner_scope is retained as a legacy database guard.
// Segment model code enforces the authoritative owner tuple plus slug.
$table->unique(['owner_scope', 'slug']);
$table->index(['is_active', 'priority']);
$table->index('type');
```

### JSON Column Optimization

For PostgreSQL, `jsonb` is used by default. Add GIN indexes on metadata columns (manual migration):

```php
Schema::table('customers', function (Blueprint $table) {
    $table->index('metadata')->algorithm('gin');
});
```

## Security Considerations

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
- [Troubleshooting](99-troubleshooting.md) - Debug common issues
