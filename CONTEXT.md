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
- `customers` hard-requires `aiarmada/addressing` as intentional policy (canonical-addressing doctrine, see addressing CONTEXT) because `Customer` unconditionally uses `addressing.HasAddresses`; future unconditional pilots, including orders, must make the same dependency decision explicitly.
- Customer address records live in `addressing.Address` and attach through `HasAddresses`; billing and shipping defaults use typed pivots and `primaryAddress()`. The former package-local storage guardrail is retired.
- Customer email and phone values are owned exclusively by Contacting rows; the customer tables do not duplicate those contact columns.
- If admin UI changes too, audit `filament-customers`.
- Update `docs/*.md` in the same pass when public behavior or config changes.

## Decide fast
- Use when: Customer records, commercial identity linkage, reusable saved addresses, or segmentation.
- Skip when: Shared person identity (titles/credentials) — see persons; tenant identity — see organizations.
- Owner/security: Owner-scoped (all models; customers.features.owner, default on).

## Key surfaces
- Models: `Customer`, `CustomerGroup`, `CustomerNote`, `Segment` (address records come from `addressing.Address`)
- Actions/Services: `Actions/AssignCustomerToSegment`, `Actions/CreateCustomer`, `Actions/LinkCustomerToPerson`, `Actions/MergeCustomers`, `Actions/RebuildAllSegments`, `Actions/RemoveCustomerFromSegment`, `Actions/UpdateCustomerProfile`, `Services/CustomerResolver`, `Services/SegmentationService`, `Support/CustomerProfileNormalizer`
- Config `customers.php`: `database`, `table_prefix`, `json_column_type`, `tables`, `customers`, `segments`, `segment_customer`, `groups`, `group_members`

## Docs map
- Start: `01-overview` → `03-configuration` → `04-usage` → `99-troubleshooting`
- Deep dives: none — the five canonical docs cover this package
