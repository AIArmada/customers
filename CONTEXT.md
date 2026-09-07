---
title: Customers Context
package: customers
status: current
surface: domain
family: catalog-and-identity
keywords:
  - customer
  - crm
  - segment
  - profile
---

# Customers Context

## Snapshot
- Composer: `aiarmada/customers`
- Role: Owner-scoped commercial customer profile linked optionally to the shared persons identity, plus addresses, segments, groups, and notes.
- Triggers: customer, crm, segment, profile
- Search first: `src/Models, src/Actions, src/Services, config, docs`
- Related: `filament-customers`, `persons`, `addressing`, `pricing`, `checkout`
- Paired: `filament-customers` (Filament admin adapter)

## Read next
1. `docs/01-overview.md`
2. `docs/03-configuration.md`
3. `docs/04-usage.md`
4. `docs/99-troubleshooting.md`
5. `../filament-customers/CONTEXT.md` when the change crosses UI/domain
6. `docs/02-installation.md` when setup or publishing changes are involved

## Guardrails
- Owns models, actions, services, events, calculations, and persistence rules.
- `Customer` is the owner-scoped commercial profile. Its nullable `person_id` is a loose, indexed link to the shared `persons.Person`; use `LinkCustomerToPerson` for owner-safe writes and do not backfill automatically.
- New reusable customer address attachments use `addressing.HasAddresses`; `customers.Address` and its `customer_addresses` table remain frozen legacy storage for checkout/default-address behavior until a later migration phase.
- If admin UI changes too, audit `filament-customers`.
- Update `docs/*.md` in the same pass when public behavior or config changes.

## Decide fast
- Use when: Customer records, commercial identity linkage, reusable saved addresses, or segmentation.
- Skip when: Shared person identity (titles/credentials) — see persons; tenant identity — see organizations.
- Owner/security: Owner-scoped (all models; customers.features.owner, default off).

## Key surfaces
- Models: `Address`, `Customer`, `CustomerGroup`, `CustomerNote`, `Segment`
- Actions/Services: `Actions/AssignCustomerToSegment`, `Actions/CreateCustomer`, `Actions/LinkCustomerToPerson`, `Actions/RebuildAllSegments`, `Actions/RemoveCustomerFromSegment`, `Actions/UpdateCustomerProfile`, `Services/CustomerResolver`, `Services/SegmentationService`
- Config `customers.php`: `database`, `table_prefix`, `json_column_type`, `tables`, `customers`, `addresses`, `segments`, `segment_customer`, `groups`, `group_members`

## Docs map
- Start: `01-overview` → `03-configuration` → `04-usage` → `99-troubleshooting`
- Deep dives: none — the five canonical docs cover this package
