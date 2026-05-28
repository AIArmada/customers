---
title: Overview
---

# Customers Package

The Customers package provides the core customer identity and CRM-style data model for the AIArmada Commerce ecosystem.

## Purpose

Use this package when you need the customer-side domain model: profiles, addresses, segments, groups, notes, owner-aware storage, and the policies and events that protect those records.

## What this package owns

- Customer profiles, lifecycle state, and marketing preferences
- Customer addresses, default billing and shipping rules, and address helpers
- Manual and automatic customer segments with rebuild logic
- Customer groups and internal/customer-visible notes
- Customer policies, events, and segmentation services
- Owner-aware persistence for customer-facing domain records
- Customer tagging, activity logging, and media hooks on the core customer model
- Customer resolution for checkout and billing flows when a package needs to map users or guest payloads to a `Customer`
- The customer-aware payment subject driver registered into Commerce Support's payment-subject resolver

## What this package does not own

- Filament admin resources, widgets, or panel navigation for customers
- Orders, carts, checkout orchestration, pricing logic, gateway API calls, or payment capture logic
- Application-specific user registration, authentication UI, or profile management screens
- Tenant resolution itself beyond consuming `commerce-support` owner context

## Related packages

- `aiarmada/commerce-support` provides owner-scoping, activity, and helper primitives used by the models
- `aiarmada/filament-customers` provides the Filament admin resources and widgets for this package
- `aiarmada/checkout` uses this package to resolve authenticated and guest checkout payloads into customer records and billable subjects
- Other Commerce packages such as `orders` and `pricing` consume customer records but do not replace this package as the source of truth

## Main models services or surfaces

- `Customer`
- `Address`
- `Segment`
- `CustomerGroup`
- `CustomerNote`
- `CustomerResolver`
- `CustomersPaymentSubjectDriver`
- `SegmentationService`
- `RebuildSegmentsCommand`

## Features

### Customer Management
- **Customer Profiles**: Store customer information including contact details, status, and preferences
- **User Integration**: Link customers to application users for unified identity
- **Status Tracking**: Monitor customer status (Active, Inactive, Suspended, Pending Verification)
- **Marketing Preferences**: Track opt-in/opt-out status for marketing communications

### Address Management
- **Multiple Addresses**: Support unlimited addresses per customer
- **Address Types**: Billing, Shipping, or Both
- **Default Addresses**: Automatic management of default billing/shipping addresses
- **Address Verification**: Track verification status and coordinates

### Customer Segmentation
- **Automatic Segments**: Rules-based customer segmentation
- **Manual Segments**: Hand-picked customer groups
- **Segment Types**: Loyalty, Behavior, Demographic, Custom
- **Dynamic Updates**: Automatic segment membership updates based on rules
- **Condition Types**: Marketing opt-in, status, creation date

### Customer Groups
- **Group Management**: Organize customers into buying groups
- **Role-Based Access**: Admin and member roles within groups
- **Spending Limits**: Optional spending limits per group
- **Approval Workflow**: Optional approval requirements for purchases

### Customer Notes
- **Internal Notes**: Staff-only notes for customer records
- **Customer-Visible Notes**: Notes that can be shared with customers
- **Pinned Notes**: Highlight important notes
- **Audit Trail**: Track who created each note

### Checkout & Payment Subject Resolution
- **CustomerResolver**: Resolves the best customer record from an authenticated user, session customer, and billing/shipping payloads
- **Guest Promotion & Merge**: Converts guest customers into linked customers and merges guest profiles when the owner context matches
- **Address Hydration**: Creates or reuses default billing and shipping addresses from checkout payloads
- **Payment Subject Driver**: Registers a high-priority customer-aware payment subject driver before the guest fallback driver

### Media & Tags
- **Avatar Images**: Customer profile pictures via Spatie Media Library
- **Document Attachments**: Store customer-related documents
- **Tagging**: Flexible tagging for segmentation via Spatie Tags
- **Activity Logging**: Comprehensive activity tracking

## Owner scoping and security notes

- **Owner Scoping**: All models support owner relationships via `HasOwner` trait
- **Automatic Assignment**: Auto-assign owner on creation
- **Owner Validation**: Enforce owner context on foreign keys
- **Global Records**: Optional support for global records
- **Query Scoping**: Default-on owner scoping with opt-out

## Integrations

Works seamlessly with:
- **Spatie Media Library**: For customer avatars and documents
- **Spatie Tags**: For flexible customer tagging
- **Spatie Activity Log**: For audit trails
- **Laravel Authentication**: Link customers to users
- **Other Commerce Packages**: Orders, Products, Pricing, etc.

## Architecture

The package follows SOLID principles:
- **Models**: Eloquent models with proper relationships
- **Enums**: Type-safe status and type definitions
- **Events**: Dispatchable events for all major actions
- **Policies**: Authorization via Laravel policies
- **Services**: Business logic encapsulation (SegmentationService)
- **Commands**: Artisan commands for maintenance tasks (RebuildSegmentsCommand)

## Read next

- [Installation](02-installation.md) - Set up the package
- [Configuration](03-configuration.md) - Configure package options
- [Usage](04-usage.md) - Learn how to use the package
- [Troubleshooting](99-troubleshooting.md) - Debug common issues
