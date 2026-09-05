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
- Role: Customer identity/CRM: profiles, addresses, segments, groups, notes.
- Triggers: customer, crm, segment, profile
- Search first: `src/Models, src/Actions, src/Services, config, docs`
- Related: `filament-customers`, `pricing`, `checkout`
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
- If admin UI changes too, audit `filament-customers`.
- Update `docs/*.md` in the same pass when public behavior or config changes.

## Decide fast
- Use when: Customer records or segmentation.
- Skip when: Person identity (titles/credentials) — see persons.
- Owner/security: Owner-scoped (all models; customers.features.owner, default off).

## Key surfaces
- Models: `Address`, `Customer`, `CustomerGroup`, `CustomerNote`, `Segment`
- Actions/Services: `Actions/AssignCustomerToSegment`, `Actions/CreateCustomer`, `Actions/RebuildAllSegments`, `Actions/RemoveCustomerFromSegment`, `Actions/UpdateCustomerProfile`, `Services/CustomerResolver`, `Services/SegmentationService`
- Config `customers.php`: `database`, `table_prefix`, `json_column_type`, `tables`, `customers`, `addresses`, `segments`, `segment_customer`, `groups`, `group_members`

## Docs map
- Start: `01-overview` → `03-configuration` → `04-usage` → `99-troubleshooting`
- Deep dives: none — the five canonical docs cover this package
