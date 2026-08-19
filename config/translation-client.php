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
    | Tenant ID
    |--------------------------------------------------------------------------
    |
    | Your application's tenant ID for multi-tenancy support
    | 
    | Options:
    | - Set explicitly: TRANSLATION_TENANT_ID=your-tenant-id
    | - Leave empty: Auto-detects from first tenant in database
    | - null: Uses global translations (no tenant isolation)
    |
    */
    'tenant_id' => env('TRANSLATION_TENANT_ID'),

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
    | The regional locale tags this app serves. Read by SetLocaleFromRequest,
    | which uses it to decide whether to honour a requested locale.
    |
    | List **regional variants**, not bare languages. A bare tag still works —
    | the service resolves `ar` by truncation, and the middleware matches it to
    | the variant configured for that language — but requesting one caches under
    | a different key than the variant it resolves to, so it costs an extra
    | fetch for identical bytes.
    |
    | Trim this to the variants your tenants are actually assigned.
    |
    */
    'available_locales' => [
        'ar-SA',
        'ar-EG',
        'en-US',
        'en-GB',
    ],
];
