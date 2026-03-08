<?php

declare(strict_types=1);

return [

    // Database
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
        'json_column_type' => env('CUSTOMERS_JSON_COLUMN_TYPE', env('COMMERCE_JSON_COLUMN_TYPE', 'json')),
    ],

    // Features
    'features' => [
        'owner' => [
            'enabled' => env('CUSTOMERS_OWNER_ENABLED', true),
            'include_global' => env('CUSTOMERS_OWNER_INCLUDE_GLOBAL', false),
            'auto_assign_on_create' => env('CUSTOMERS_OWNER_AUTO_ASSIGN', true),
        ],

        'segments' => [
            'auto_assign' => true,
        ],
    ],

    // Integrations
    'integrations' => [
        'user_model' => null, // Fallback: config('auth.providers.users.model')
    ],
];
