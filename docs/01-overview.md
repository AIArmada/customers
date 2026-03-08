---
title: Overview
---

# Customers Package

The Customers package provides core customer identity and data management for the AIArmada Commerce ecosystem. It focuses on customer profiles, addresses, segments, groups, and notes.

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

### Media & Tags
- **Avatar Images**: Customer profile pictures via Spatie Media Library
- **Document Attachments**: Store customer-related documents
- **Tagging**: Flexible tagging for segmentation via Spatie Tags
- **Activity Logging**: Comprehensive activity tracking

## Multi-Tenancy

The package includes full multi-tenancy support:
- **Owner Scoping**: All models support owner relationships via `HasOwner` trait
- **Automatic Assignment**: Auto-assign owner on creation
- **Owner Validation**: Enforce owner context on foreign keys
- **Global Records**: Optional support for global records
- **Query Scoping**: Default-on owner scoping with opt-out

## Integration

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

## Next Steps

- [Installation](02-installation.md) - Set up the package
- [Configuration](03-configuration.md) - Configure package options
- [Usage](04-usage.md) - Learn how to use the package
