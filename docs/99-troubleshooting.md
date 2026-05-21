---
title: Troubleshooting
---

# Troubleshooting

## Common Issues

### Owner Scoping Issues

**Problem**: Customers not appearing in queries

**Solution**: Ensure owner context is set:

```php
use AIArmada\CommerceSupport\Support\OwnerContext;

// Use callback-scoped owner context
OwnerContext::withOwner($tenant, function() {
    $customers = Customer::all(); // Scoped to tenant
});
```

**Problem**: Cross-tenant access blocked

**Solution**: Verify owner relationships match:

```php
// Both must have same owner
if ($customer->belongsToOwner($order->owner)) {
    // Safe to proceed
}
```

### Segment Issues

**Problem**: Automatic segments not updating

**Solution**: Run the rebuild command:

```bash
php artisan customers:rebuild-segments
```

Or rebuild specific segment:

```bash
php artisan customers:rebuild-segments --segment=segment-uuid
```

**Problem**: Customer not matching segment conditions

**Solution**: Check condition logic:

```php
$segment = Segment::find('segment-id');
$conditions = $segment->conditions;

// Manually test condition
$service = app(SegmentationService::class);
$matches = $service->customerMatchesSegment($customer, $segment);

if (!$matches) {
    // Debug which condition failed
    foreach ($conditions as $condition) {
        // Check each condition
    }
}
```

### Address Validation

**Problem**: Cannot create address for customer

**Solution**: Ensure owner context matches:

```php
// In multi-tenant mode, customer must belong to current owner
if (config('customers.features.owner.enabled')) {
    $owner = OwnerContext::resolve();
    
    // Verify customer is accessible
    $customer = Customer::query()
        ->forOwner($owner, includeGlobal: false)
        ->findOrFail($customerId);
    
    // Now safe to create address
    $customer->addresses()->create([...]);
}
```

### Migration Issues

**Problem**: JSON column errors on PostgreSQL

**Solution**: Set correct column type in config:

```php
// config/customers.php
'database' => [
    'json_column_type' => 'jsonb', // For PostgreSQL
],
```

Then refresh migrations:

```bash
php artisan migrate:refresh
```

**Problem**: Unique constraint violations

**Solution**: Ensure unique fields are truly unique:

```php
// Check for existing customer
$existing = Customer::where('email', $email)->first();

if ($existing) {
    // Update existing or return error
}
```

### Performance Issues

**Problem**: Slow customer queries with segments

**Solution**: Eager load relationships:

```php
$customers = Customer::with(['segments', 'addresses'])->get();
```

**Problem**: Slow segment rebuilds

**Solution**: Use chunking when evaluating large customer datasets:

```php
Customer::active()->chunk(1000, function($customers) use ($service) {
    foreach ($customers as $customer) {
        $service->evaluateCustomer($customer);
    }
});
```

### Authorization Issues

**Problem**: Policy denying access incorrectly

**Solution**: Check policy logic:

```php
// Debug policy
$user = auth()->user();
$policy = new CustomerPolicy();

if (!$policy->view($user, $customer)) {
    // Check owner context
    dump(OwnerContext::resolve());
    dump($customer->owner);
}
```

**Problem**: Cannot delete customer with relationships

**Solution**: Cascades are handled automatically, but check for external references:

```php
// Ensure no orders exist (if orders package is installed)
if ($customer->orders()->exists()) {
    throw new \Exception('Cannot delete customer with orders');
}

// Then delete (cascades will handle addresses, notes, etc.)
$customer->delete();
```

## Debug Mode

Enable detailed logging:

```php
// config/logging.php
'channels' => [
    'customers' => [
        'driver' => 'daily',
        'path' => storage_path('logs/customers.log'),
        'level' => 'debug',
    ],
],
```

Log customer operations:

```php
use Illuminate\Support\Facades\Log;

Log::channel('customers')->debug('Creating customer', [
    'email' => $email,
    'owner' => OwnerContext::resolve()?->getKey(),
]);
```

## Getting Help

1. **Check Configuration**: Review `config/customers.php`
2. **Enable Debug Mode**: Set `APP_DEBUG=true` in `.env`
3. **Check Logs**: Review `storage/logs/laravel.log`
4. **Test in Isolation**: Create minimal reproduction case
5. **Review Guidelines**: Check `.github/copilot-instructions.md`

## Reporting Issues

When reporting issues, include:

- Laravel version
- PHP version  
- Package version
- Configuration (sanitized)
- Error message with stack trace
- Steps to reproduce
- Expected vs actual behavior

## Common Gotchas

### Owner Scoping
- **Always** validate owner context in multi-tenant mode
- **Never** trust Filament form options without server-side validation
- **Use** `forOwner()` explicitly when owner context is ambiguous

### Segments
- **Automatic** segments override manual assignments
- **Priority** matters for pricing (higher = more important)
- **Conditions** use AND logic (all must match)

### Addresses
- **Unique** default addresses per type (only one default billing, one default shipping)
- **Country** codes must be ISO 3166-1 alpha-2 (e.g., 'MY', 'SG')
- **Verification** is manual - integrate with address validation service

## Next Steps

- [Configuration](03-configuration.md) - Review configuration options
- [Usage](04-usage.md) - Review usage patterns
