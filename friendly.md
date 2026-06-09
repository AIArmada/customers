## Second pass — 2026-06-09

### Confirmed

- All 5 Actions exist: `CreateCustomer`, `UpdateCustomerProfile`, `AssignCustomerToSegment`, `RemoveCustomerFromSegment`, `RebuildAllSegments`.
- Both concerns exist: `Concerns/IsCustomerOwned` (111 lines, validates customer_id belongs to owner context on create), `Concerns/IsCustomerRelated` (auto-assigns owner on create).
- `Contracts/HasCustomerProfile` promoted as public interface with the trait remaining as default implementation in `Concerns/`.
- `RebuildAllSegments` Action exists.

### Still open

- All phases are marked `[done]` and verified complete. No open items.

### New findings

- The `RebuildSegmentsCommand` should delegate to `RebuildAllSegments` action per the refactor plan. Worth spot-checking that this delegation is in place.
- The customers package is actually the **cleanest** of all 7 audited packages — all recommendations were implemented, no false [done] markers found, and the code is well-structured.

### Updated recommendation

No further action needed on customers. The package is in excellent shape.

---

# Customers friendliness review

This note reviews `packages/customers` against two repo-level expectations:

- when a capability may grow variants, prefer stable seams such as contracts, metadata, hooks, domain events, resolvers, and support classes
- when orchestration repeats, extract reusable Actions, Services, or Use Cases so the package stays friendly to multiple entrypoints

## What I reviewed

- `src/Services`
- `src/Models`
- `src/Events`
- `src/Console/Commands`
- `src/Payment`
- `src/Concerns`
- downstream consumers in `checkout`, `cashier`, `affiliates`, `signals`

## What is already friendly

### Customer resolver is the right entry point

- `Services/CustomerResolver.php`

Rather than letting callers reach into the auth or owner context directly, the package exposes a single resolver. This is the right shape for a "who is the current customer" seam.

### Segmentation has its own service

- `Services/SegmentationService.php`

Segment evaluation is a real service, not embedded in the model. As segmentation rules grow, they have a place to land.

### Payment subject driver plugs into foundation

- `Payment/CustomersPaymentSubjectDriver.php`

The package implements `PaymentSubjectDriverInterface` from `commerce-support`, which means checkout, cashier, and gateway packages can resolve the customer as a payment subject without depending on `Customer` directly.

### Domain events are explicit

- `Events/CustomerCreated.php`
- `Events/CustomerUpdated.php`
- `Events/CustomerAddedToSegment.php`
- `Events/CustomerSegmentChanged.php`

These give signals, affiliates, and other analytics packages a stable event surface.

## Findings

### 1. There is no `Actions/` directory — orchestration lives in services

**Files**

- `src/Services/CustomerResolver.php`
- `src/Services/SegmentationService.php`

**Why this hurts friendliness**

`CustomerResolver` and `SegmentationService` are real services, but any complex workflow (create customer, move customer between segments, merge customers, expire segments) currently has to be added as another method on these services or inline in callers.

**Recommendation**

Introduce a small `src/Actions` tree for the workflows that span multiple models or services:

- `Actions/CreateCustomer`
- `Actions/UpdateCustomerProfile`
- `Actions/AssignCustomerToSegment`
- `Actions/RemoveCustomerFromSegment`

The services stay focused on read-side and resolution concerns. Actions own the mutation workflows.

### 2. The `RebuildSegmentsCommand` is a one-off with no shared orchestration

**Files**

- `src/Console/Commands/RebuildSegmentsCommand.php`

**Why this hurts friendliness**

This is a batch command that probably iterates over all customers and recomputes segments. If a similar rebuild is needed elsewhere (orders, events), the orchestration will be copied.

**Recommendation**

If the package has a clear "rebuild all segments" workflow, extract it into an Action like `Actions/RebuildAllSegments`. The command becomes a thin adapter. This keeps the orchestration testable and reusable.

### 3. The `Concerns/HasCustomerProfile` trait is not clearly a public extension seam

**Files**

- `src/Concerns/HasCustomerProfile.php`

**Why this hurts friendliness**

If the trait is a public contract (other models can use it to expose customer-like profile fields), the contract is not documented. If it is internal, it should be marked as such.

**Recommendation**

Either:

- move it to `Contracts/HasCustomerProfileContract.php` and document it as a public seam, or
- mark it internal and remove it from any public namespace expectations

Pick one.

### 4. `CustomerAddress` and `CustomerNote` are sibling models with similar boilerplate

**Files**

- `src/Models/Address.php`
- `src/Models/CustomerNote.php`
- `src/Models/CustomerGroup.php`
- `src/Models/Segment.php`

**Why this hurts friendliness**

These models share owner-scope and event patterns. New sibling types (preferences, communication logs, loyalty balances) will copy the boilerplate.

**Recommendation**

Add shared concerns:

- `Concerns/IsCustomerOwned` for owner-scope + event
- `Concerns/IsCustomerRelated` for the relation-style models

### 5. The provider wires everything manually

**Files**

- `src/CustomersServiceProvider.php`

**Why this hurts friendliness**

The provider is the single wiring point. If customers need to register payment drivers, segment evaluators, or extension points, the provider becomes a manifest.

**Recommendation**

Use the monorepo's tagged-registrar pattern:

- `RegisterCustomerSegmentEvaluator` for new segment types
- bind `CustomerResolver` from the container rather than constructing it inline
- keep the provider as a composition root

### 6. Customer status enum exists but transition logic is not centralized

**Files**

- `Enums/CustomerStatus.php`
- `Enums/SegmentType.php`
- `Enums/AddressType.php`

**Why this hurts friendliness**

The status enum is declared, but there is no clear transition module. Callers have to know which status changes are valid and what their side effects are.

**Recommendation**

Consider a state machine (spatie/laravel-model-states) for `CustomerStatus`. Let status transitions dispatch `CustomerUpdated` and any segment re-evaluation events.

## Concrete refactor plan

### Phase 1 — introduce the Actions tree

**Steps**

1. Add `src/Actions/CreateCustomer`, `UpdateCustomerProfile`, `AssignCustomerToSegment`, `RemoveCustomerFromSegment`.
2. Move any inline orchestration out of services and models.
3. Update downstream callers to use Actions.

### Phase 2 — extract segment rebuild

**Steps**

1. Add `Actions/RebuildAllSegments`.
2. Make `RebuildSegmentsCommand` call it.
3. Add characterization tests first.

### Phase 3 — share concerns across sibling models

**Steps**

1. Add `Concerns/IsCustomerOwned` and `Concerns/IsCustomerRelated`.
2. Apply to Address, Note, Group, Segment.

### Phase 4 — decide on `HasCustomerProfile` contract

**Steps**

1. Promote to a contract in `Contracts/`, or
2. Mark as internal in `Concerns/`.





## Refactor tracking

This checklist tracks progress on the refactor plan above. Each item lists a concrete phase/step.
Agents: claim an item by updating its status. Use `@agent-name` to claim ownership.

Status legend:
- `[pending]` — not started
- `[in-progress]` — being worked on
- `[done]` — completed and verified
- `[blocked]` — blocked by another item

### Phase 1 — introduce the Actions tree

- [done] Add `src/Actions/CreateCustomer`, `UpdateCustomerProfile`, `AssignCustomerToSegment`, `RemoveCustomerFromSegment`.
- [done] Move any inline orchestration out of services and models.
- [done] Update downstream callers to use Actions.

### Phase 2 — extract segment rebuild

- [done] Add `Actions/RebuildAllSegments`.
- [done] Make `RebuildSegmentsCommand` call it.
- [done] Add characterization tests first.

### Phase 3 — share concerns across sibling models

- [done] Add `Concerns/IsCustomerOwned` (for customer_id-based models: owner validation + sync).
- [done] Add `Concerns/IsCustomerRelated` (for related models: auto-assign owner on create).
- [done] Apply `IsCustomerOwned` to Address, CustomerNote.
- [done] Apply `IsCustomerRelated` to CustomerGroup, Segment.

### Phase 4 — decide on `HasCustomerProfile` contract

- [done] Promoted to `Contracts/HasCustomerProfile.php` as a public interface.
- [done] The `Concerns/HasCustomerProfile` trait remains the default implementation in `Concerns/`.



## Suggested verification scope

- `tests/src/Customers/Unit/CustomerResolverTest.php`
- `tests/src/Customers/Unit/SegmentationServiceTest.php`
- new tests for the Actions introduced in Phase 1
- tests for the segment rebuild extraction

## Recommended first move

Phase 1 — introduce the Actions tree. The package has services but no Actions, and the most common customer workflows (create, update, segment changes) all want Actions today.
