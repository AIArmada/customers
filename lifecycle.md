# Customers Package — Lifecycle Field Audit & Refactoring Plan

## 1. Executive Summary

The `customers` package uses a mix of `is_*` booleans and a `status` string column to represent lifecycle state. The `customers.status` column drives four lifecycle states but missing transition timestamps for each value (`activated_at`, `suspended_at`, etc.). Boolean flags like `is_verified` and consent state `accepts_marketing` lose *when* a transition occurred, making audit, reporting, and event sourcing impractical.

Config entities (`customer_segments`, `customer_groups`) use `is_active` booleans — keep these as-is with an optional `deactivated_at` timestamp for audit. Designation booleans (`is_default_billing`, `is_default_shipping`, `is_pinned`, `is_internal`) remain as booleans — they are not lifecycle transitions.

**Goal**: Add transition timestamps to `customers.status` states and `is_verified` on addresses. Add `deactivated_at` to segments/groups alongside their existing `is_active` booleans. Keep designation booleans unchanged.

---

## 2. Full Inventory by Table

### 2.1 `customers`

| Column | Type | Default | Classification | Problem |
|--------|------|---------|----------------|---------|
| `status` | `string` | `'active'` | Lifecycle driver (CustomerStatus enum) | Correct pattern but no transition timestamps |
| `is_guest` | `boolean` | `false` | Lifecycle flag | Boolean; should be inferred from `user_id IS NULL` or have `registered_at` |
| `accepts_marketing` | `boolean` | `true` | Consent state | Boolean; missing `marketing_consented_at` / `marketing_revoked_at` |
| `created_at` | `timestampTz` | auto | Lifecycle | OK |
| `updated_at` | `timestampTz` | auto | Lifecycle | OK |

**Status enum values**: `Active`, `Inactive`, `Suspended`, `PendingVerification`

**Missing transition timestamps**:
- `activated_at` — when status→active
- `deactivated_at` — when status→inactive
- `suspended_at` — when status→suspended
- `verified_at` — when status→active (from pending_verification)

### 2.2 `customer_addresses`

| Column | Type | Default | Classification | Problem |
|--------|------|---------|----------------|---------|
| `is_default_billing` | `boolean` | `false` | **Designation** — not lifecycle | **Kept as boolean** — config designation |
| `is_default_shipping` | `boolean` | `false` | **Designation** — not lifecycle | **Kept as boolean** — config designation |
| `is_verified` | `boolean` | `false` | Lifecycle event | Boolean; should be `verified_at` |
| `created_at` | `timestampTz` | auto | Lifecycle | OK |
| `updated_at` | `timestampTz` | auto | Lifecycle | OK |

### 2.3 `customer_segments`

| Column | Type | Default | Classification | Problem |
|--------|------|---------|----------------|---------|
| `is_active` | `boolean` | `true` | Config toggle — not lifecycle | **Kept as boolean** — missing `deactivated_at` for audit |
| `is_automatic` | `boolean` | `true` | Structural/type property | NOT lifecycle — keeps boolean (segment type) |
| `created_at` | `timestampTz` | auto | Lifecycle | OK |
| `updated_at` | `timestampTz` | auto | Lifecycle | OK |

### 2.4 `customer_segment_customer` (pivot)

| Column | Type | Default | Classification | Problem |
|--------|------|---------|----------------|---------|
| `created_at` | `timestampTz` | auto | OK | Timestamps when customer joined segment |
| `updated_at` | `timestampTz` | auto | OK | |

No lifecycle issues.

### 2.5 `customer_groups`

| Column | Type | Default | Classification | Problem |
|--------|------|---------|----------------|---------|
| `is_active` | `boolean` | `true` | Config toggle — not lifecycle | **Kept as boolean** — missing `deactivated_at` for audit |
| `requires_approval` | `boolean` | `true` | Configuration/behavior toggle | NOT lifecycle — keeps boolean |
| `created_at` | `timestampTz` | auto | Lifecycle | OK |
| `updated_at` | `timestampTz` | auto | Lifecycle | OK |

### 2.6 `customer_group_members` (pivot)

| Column | Type | Default | Classification | Problem |
|--------|------|---------|----------------|---------|
| `joined_at` | `timestampTz` | nullable | Lifecycle | Already correct |
| `created_at` | `timestampTz` | auto | OK | |
| `updated_at` | `timestampTz` | auto | OK | |

No lifecycle issues. This is the **canonical example** of the target pattern.

### 2.7 `customer_notes`

| Column | Type | Default | Classification | Problem |
|--------|------|---------|----------------|---------|
| `is_internal` | `boolean` | `true` | **Designation** — not lifecycle | **Kept as boolean** — simple binary display flag |
| `is_pinned` | `boolean` | `false` | **Designation** — not lifecycle | **Kept as boolean** — structural flag |
| `created_at` | `timestampTz` | auto | Lifecycle | OK |
| `updated_at` | `timestampTz` | auto | Lifecycle | OK |

---

## 3. Problems Summary

### 3.1 Status transitions not timestamped

The `customers.status` column drives four lifecycle states but none of the transitions are recorded. An `activated_at` column must be populated for every currently-active customer; without it, you cannot answer:
- "How long was this customer suspended?"
- "When was this customer last activated?"
- "What was the customer's status on date X?"

### 3.2 `is_verified` — verification event without timestamp

On `customer_addresses`, `is_verified` records whether verification happened but not when. A `verified_at` timestampTz records the one-time event.

### 3.3 `accepts_marketing` — consent without timestamp

GDPR/CCPA require knowing *when* consent was given or revoked. A boolean is insufficient.

### 3.4 Config entities lack deactivation audit

`customer_segments` and `customer_groups` use `is_active` booleans (correct for config toggles) but have no `deactivated_at` timestamp to record when they were turned off.

---

## 4. Recommended Structure

### 4.1 `customers`

```
status                   string       (keep — lifecycle driver enum)
registered_at            timestampTz  nullable   (set when user_id first assigned)
activated_at             timestampTz  nullable   (set when status→active)
deactivated_at           timestampTz  nullable   (set when status→inactive)
suspended_at             timestampTz  nullable   (set when status→suspended)
verified_at              timestampTz  nullable   (set when status active from pending_verification)
accepts_marketing        boolean      (keep — used for filtering; non-lifecycle consent toggle)
marketing_consented_at   timestampTz  nullable   (set when accepts_marketing→true)
marketing_revoked_at     timestampTz  nullable   (set when accepts_marketing→false)
```

**Rationale**: `accepts_marketing` boolean stays because it is the authoritative consent toggle that drives UI filtering and email suppression lists. Adding `marketing_consented_at`/`marketing_revoked_at` gives auditability.

### 4.2 `customer_addresses`

```
is_default_billing   boolean  (keep — config designation)
is_default_shipping  boolean  (keep — config designation)
is_verified          → verified_at  timestampTz nullable
```

### 4.3 `customer_segments`

```
is_active            boolean  (keep — config toggle)
deactivated_at       timestampTz nullable   (set when is_active→false)
is_automatic         boolean  (keep — structural property, not lifecycle)
```

**Active check**: `is_active = true` (existing pattern, unchanged)

### 4.4 `customer_groups`

```
is_active            boolean  (keep — config toggle)
deactivated_at       timestampTz nullable   (set when is_active→false)
requires_approval    boolean  (keep — configuration, not lifecycle)
```

**Active check**: `is_active = true` (existing pattern, unchanged)

### 4.5 `customer_notes`

```
is_internal          boolean  (keep — display designation)
is_pinned            boolean  (keep — structural flag)
```

No changes required.

---

## 5. Refactoring Plan

### Phase A — New migrations (add columns)

**Migration 1**: `add_lifecycle_timestamps_to_customers_table`
- Add: `registered_at`, `activated_at`, `deactivated_at`, `suspended_at`, `verified_at`
- Add: `marketing_consented_at`, `marketing_revoked_at`
- Data backfill: set `activated_at = created_at` WHERE `status = 'active'`
- Data backfill: set `marketing_consented_at = created_at` WHERE `accepts_marketing = true`
- Data backfill: set `verified_at = created_at` WHERE `status != 'pending_verification'`
- Index: `activated_at`, `suspended_at`

**Migration 2**: `add_verified_at_to_customer_addresses_table`
- Add: `verified_at` (timestampTz nullable)
- Data backfill: set `verified_at = updated_at` WHERE `is_verified = true`

**Migration 3**: `add_deactivated_at_to_customer_segments_table`
- Add: `deactivated_at` (timestampTz nullable)
- (No backfill needed — existing `is_active = true` records remain active)

**Migration 4**: `add_deactivated_at_to_customer_groups_table`
- Add: `deactivated_at` (timestampTz nullable)
- (No backfill needed — existing `is_active = true` records remain active)

### Phase B — Model updates

**Checklist — Models**:
- [x] `Customer`: add casts for new timestampTz columns (CarbonImmutable)
- [x] `Customer`: add `registered_at`, `activated_at`, `deactivated_at`, `suspended_at`, `verified_at`, `marketing_consented_at`, `marketing_revoked_at` to $casts and PHPDoc
- [x] `Customer`: update status helpers (`isActive()`, `isSuspended()`) to keep using `status` enum
- [x] `Customer`: update `isGuest()` to check `registered_at IS NULL` instead of `is_guest`
- [x] `Address`: add `verified_at` timestampTz cast
- [x] `Address`: update `markAsVerified()` to set `verified_at`
- [x] `Address`: keep `is_default_billing`/`is_default_shipping` booleans as-is
- [x] `Segment`: add `deactivated_at` timestampTz cast
- [x] `Segment`: `scopeActive()` — already uses `is_active = true`, unchanged
- [x] `Segment`: add `booted()` listener to set `deactivated_at` when `is_active` becomes false
- [x] `CustomerGroup`: add `deactivated_at` timestampTz cast
- [x] `CustomerGroup`: `scopeActive()` — already uses `is_active = true`, unchanged
- [x] `CustomerGroup`: add `booted()` listener to set `deactivated_at` when `is_active` becomes false

**Checklist — Actions** (any status-mutating code):
- [x] Status change actions must set the appropriate `*_at` timestamp
- [x] `marketing_consented_at` / `marketing_revoked_at` set on opt-in/opt-out
- [x] `registered_at` set when `user_id` is first assigned
- [x] `activated_at` set on activation, `deactivated_at` on deactivation, `suspended_at` on suspension

### Phase C — Drop old columns (after code deploys)

**Migration 5**: `drop_is_guest_from_customers_table`
- Drop: `is_guest` (derivable from `registered_at IS NULL`)

**Migration 6**: `drop_is_verified_from_customer_addresses_table`
- Drop: `is_verified` (replaced by `verified_at`)

No other columns to drop — `is_active` on segments/groups, `is_default_*` on addresses, and `is_internal`/`is_pinned` on notes are retained.

---

## 6. Migration Strategy

### Strategy: Add-then-drop (safe two-deploy cycle)

**Deploy 1** (Phase A + Phase B): Run add-column migrations, deploy updated models/actions. All writes use new timestamp columns. Reads use new columns for lifecycle, existing booleans as fallback.

**Deploy 2** (Phase C): Run drop-column migrations after confirming no code reads `is_guest` or `is_verified`.

### Backfill rationale

| Old value | New column | Backfill source |
|-----------|-----------|-----------------|
| `status = 'active'` | `activated_at` | `created_at` (was always active) |
| `status != 'pending_verification'` | `verified_at` | `created_at` |
| `accepts_marketing = true` | `marketing_consented_at` | `created_at` |
| `is_verified = true` | `verified_at` (addresses) | `updated_at` |

---

## 7. Verification Commands

### After Phase A (add migrations)

```bash
# Verify columns exist
php artisan tinker --execute 'print_r(Schema::getColumnListing("customers"));'

# Verify backfill
php artisan tinker --execute 'echo \AIArmada\Customers\Models\Customer::where("status", "active")->whereNull("activated_at")->count();'
# Expected: 0

php artisan tinker --execute 'echo \AIArmada\Customers\Models\Customer::where("accepts_marketing", true)->whereNull("marketing_consented_at")->count();'
# Expected: 0
```

### After Phase B (model updates)

```bash
# PHPStan on modified package
./vendor/bin/phpstan analyse packages/customers/src --level=6

# Run customer package tests
./vendor/bin/pest --parallel packages/customers/tests

# Grep for old is_guest column reads (should be migrated to registered_at)
rg -n "is_guest" packages/customers/src

# Grep for address is_verified references
rg -n "is_verified" packages/customers/src
```

### After Phase C (drop columns)

```bash
# Verify old columns are gone
php artisan tinker --execute 'echo Schema::hasColumn("customers", "is_guest") ? "STILL EXISTS" : "GONE";'
php artisan tinker --execute 'echo Schema::hasColumn("customer_addresses", "is_verified") ? "STILL EXISTS" : "GONE";'

# Final grep sweep — zero matches expected
rg -n "is_guest" packages/customers/src packages/customers/database
rg -n "customer_addresses.*is_verified" packages/customers/src packages/customers/database
```

### Cross-package impact check

```bash
# Check if other packages reference customers' changed columns
rg -n "is_guest|customers.*is_verified" packages/
```
