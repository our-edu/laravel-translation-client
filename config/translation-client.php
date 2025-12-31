<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Translation Service URL
    |--------------------------------------------------------------------------
    |
    | The base URL of your centralized translation service
    |
    */
    'service_url' => env('TRANSLATION_SERVICE_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Tenant UUID
    |--------------------------------------------------------------------------
    |
    | Your application's tenant UUID for multi-tenancy support
    | 
    | Options:
    | - Set explicitly: TRANSLATION_TENANT_UUID=your-tenant-uuid
    | - Leave empty: Auto-detects from first tenant in database
    | - null: Uses global translations (no tenant isolation)
    |
    */
    'tenant_uuid' => env('TRANSLATION_TENANT_UUID'),

    /*
    |--------------------------------------------------------------------------
    | Preload Translations
    |--------------------------------------------------------------------------
    |
    | Whether to preload all translations on application boot
    | Recommended for production to reduce API calls
    |
    */
    'preload' => env('TRANSLATION_PRELOAD', true),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL
    |--------------------------------------------------------------------------
    |
    | Cache duration in seconds for manifest and bundles
    |
    */
    'manifest_ttl' => env('TRANSLATION_MANIFEST_TTL', 300), // 5 minutes
    'bundle_ttl' => env('TRANSLATION_BUNDLE_TTL', 3600), // 1 hour

    /*
    |--------------------------------------------------------------------------
    | Client Type
    |--------------------------------------------------------------------------
    |
    | The client type identifier for this application
    | Options: backend, frontend, mobile
    |
    */
    'client' => env('TRANSLATION_CLIENT', 'backend'),

    /*
    |--------------------------------------------------------------------------
    | App Name Prefix
    |--------------------------------------------------------------------------
    |
    | Optional prefix to add to all translation groups
    | This helps namespace translations per application
    | 
    | Examples:
    | - null: groups are "messages", "Translation::messages"
    | - "EDMS": groups are "EDMS:messages", "EDMS:Translation::messages"
    | - "DOK": groups are "DOK:messages", "DOK:Translation::messages"
    |
    */
    'app_name_prefix' => env('TRANSLATION_APP_PREFIX'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for HTTP requests to the translation service
    |
    */
    'http_timeout' => env('TRANSLATION_HTTP_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Fallback on Error
    |--------------------------------------------------------------------------
    |
    | Whether to use stale cache or empty array when API fails
    |
    */
    'fallback_on_error' => env('TRANSLATION_FALLBACK_ON_ERROR', true),

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | The cache store to use for translation caching
    | Set to null to use the default cache store
    |
    */
    'cache_store' => env('TRANSLATION_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Auto-Register Namespaces
    |--------------------------------------------------------------------------
    |
    | Automatically detect and register translation namespaces from the service.
    | When enabled, the package will fetch all groups and register any namespaced
    | translations (e.g., "Namespace::group") automatically.
    |
    */
    'auto_register_namespaces' => env('TRANSLATION_AUTO_REGISTER_NAMESPACES', true),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Enable detailed logging for debugging
    |
    */
    'logging' => [
        'enabled' => env('TRANSLATION_LOGGING', false),
        'channel' => env('TRANSLATION_LOG_CHANNEL', 'stack'),
    ],


    /*
    |--------------------------------------------------------------------------
    | Available Locales
    |--------------------------------------------------------------------------
    |
    | The list of available locales to sync translations for
    |
    */
    'available_locales' => [
        'ar',
        'en',
    ],
];
