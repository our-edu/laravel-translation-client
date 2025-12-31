# Laravel Translation Client

[![Latest Version](https://img.shields.io/packagist/v/ouredu/laravel-translation-client.svg)](https://packagist.org/packages/ouredu/laravel-translation-client)
[![License](https://img.shields.io/packagist/l/ouredu/laravel-translation-client.svg)](LICENSE)

A Laravel package for consuming centralized translation services. This package replaces Laravel's file-based translation loader with an API-based loader that fetches translations from a remote translation service.

## Features

-  **API-based translations** - Fetch translations from a centralized service
-  **Write translations** - Push translations back to the service
-  **Multi-level caching** - Memory, Laravel cache, and service-side caching
-  **Automatic updates** - Translations update without redeployment
-  **Multi-tenant support** - Per-tenant translation isolation
-  **Zero code changes** - Works with existing `trans()` and `__()` calls
-  **High performance** - Sub-millisecond translation lookups
-  **Graceful degradation** - Falls back to stale cache on API failures
-  **Import from files** - Migrate existing Laravel translations

## Requirements

- PHP 8.1 or higher
- Laravel 10.x or 11.x

## Installation

### 1. Install via Composer

```bash
composer require ouredu/laravel-translation-client
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --provider="OurEdu\TranslationClient\TranslationServiceProvider"
```

This will create `config/translation-client.php`.

### 3. Configure Environment

Add the following to your `.env` file:

```env
TRANSLATION_SERVICE_URL=https://your-translation-service.com
TRANSLATION_PRELOAD=true
TRANSLATION_CLIENT=backend
```

### 4. Configure Available Locales

In `config/app.php`, add:

```php
'available_locales' => ['en', 'ar', 'fr'],
```

## Configuration

All configuration options are in `config/translation-client.php`:

```php
return [
    // Translation service base URL
    'service_url' => env('TRANSLATION_SERVICE_URL', 'http://localhost'),
    
    // Preload translations on boot (recommended for production)
    'preload' => env('TRANSLATION_PRELOAD', true),
    
    // Cache TTL in seconds
    'manifest_ttl' => env('TRANSLATION_MANIFEST_TTL', 300), // 5 minutes
    'bundle_ttl' => env('TRANSLATION_BUNDLE_TTL', 3600), // 1 hour
    
    // Client type: backend, frontend, mobile
    'client' => env('TRANSLATION_CLIENT', 'backend'),
    
    // HTTP timeout in seconds
    'http_timeout' => env('TRANSLATION_HTTP_TIMEOUT', 10),
    
    // Use stale cache on API failure
    'fallback_on_error' => env('TRANSLATION_FALLBACK_ON_ERROR', true),
    
    // Cache store (null = default)
    'cache_store' => env('TRANSLATION_CACHE_STORE'),
    
    // Logging
    'logging' => [
        'enabled' => env('TRANSLATION_LOGGING', false),
        'channel' => env('TRANSLATION_LOG_CHANNEL', 'stack'),
    ],
];
```

## Usage

### Basic Usage

Once installed, use Laravel's translation functions as normal:

```php
// In controllers
__('messages.welcome')
trans('validation.required')

// With replacements
trans('messages.hello', ['name' => 'Ahmed'])
```

### Commands

#### Sync Translations

Manually fetch and cache translations:

```bash
# Sync all locales
php artisan translations:sync

# Sync specific locale
php artisan translations:sync --locale=ar

# Force refresh (clear cache first)
php artisan translations:sync --force
```

#### Clear Cache

Clear translation caches:

```bash
# Clear all translation caches
php artisan translations:clear-cache

# Clear specific locale
php artisan translations:clear-cache --locale=ar
```

#### Import Translations

Import translations from Laravel lang files to the service:

```bash
# Import all locales
php artisan translations:import

# Import specific locale
php artisan translations:import --locale=ar

# Import from custom path
php artisan translations:import --path=/path/to/lang
```

### Scheduled Sync

Add to `app/Console/Kernel.php` to sync translations hourly:

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('translations:sync')->hourly();
}
```

### Middleware for Locale Detection

Add to `app/Http/Kernel.php`:

```php
protected $middlewareGroups = [
    'web' => [
        // ...
        \OurEdu\TranslationClient\Middleware\SetLocaleFromRequest::class,
    ],
];
```

This middleware detects locale from:
1. Query parameter (`?locale=ar`)
2. Header (`X-Locale: ar`)
3. Accept-Language header
4. Authenticated user preference

### Using the Facade

#### Reading Translations

```php
use OurEdu\TranslationClient\Facades\Translation;

// Check version
$manifest = Translation::checkVersion('ar');
// Returns: ['version' => 42, 'etag' => '...', 'updated_at' => '...']

// Fetch specific groups
$translations = Translation::fetchBundle('ar', ['messages', 'validation']);

// Load all translations
$all = Translation::loadTranslations('ar');

// Clear cache
Translation::clearCache('ar');
```

#### Writing Translations

```php
// Push single translation
Translation::pushTranslation(
    locale: 'ar',
    group: 'messages',
    key: 'welcome',
    value: 'مرحبا'
);

// Push multiple translations
Translation::pushTranslations([
    [
        'locale' => 'ar',
        'group' => 'messages',
        'key' => 'hello',
        'value' => 'مرحباً',
        'client' => 'backend',
    ],
]);

// Import from Laravel lang files
Translation::importFromFiles('ar', lang_path());
```

### Programmatic Access

```php
use OurEdu\TranslationClient\Services\TranslationClient;

class MyService
{
    public function __construct(
        private TranslationClient $translationClient
    ) {}
    
    public function getTranslations()
    {
        return $this->translationClient->fetchBundle('ar', ['messages']);
    }
}
```

## How It Works

### Architecture

```
Laravel App → trans() → ApiTranslationLoader → TranslationClient → Translation Service API
                                ↓
                         Memory Cache (in-memory)
                                ↓
                         Laravel Cache (Redis/File)
                                ↓
                         Translation Service (Redis + DB)
```

### Caching Strategy

1. **Memory Cache**: Loaded translations stored in PHP memory
2. **Laravel Cache**: Persistent cache with configurable TTL
3. **Version Checking**: Manifest API checked every 5 minutes
4. **Automatic Invalidation**: Cache refreshed when version changes

### Performance

- **Cached Translation**: ~1-5ms (from memory)
- **Laravel Cache Hit**: ~5-10ms
- **API Call (first time)**: ~50-200ms
- **Manifest Check**: ~10-50ms

## Multi-Tenant Support

For multi-tenant applications:

```php
// Set tenant dynamically based on request
config(['translation-client.tenant_uuid' => $currentTenant->uuid]);

// Or extend the TranslationClient
class MultiTenantTranslationClient extends TranslationClient
{
    public function __construct()
    {
        parent::__construct();
        $this->tenantUuid = auth()->user()?->tenant_uuid;
    }
}
```

## Error Handling

The package handles errors gracefully:

- **API Unavailable**: Uses stale cache if available
- **Network Timeout**: Falls back to cached data
- **Invalid Response**: Logs error and returns empty array
- **Missing Translations**: Falls back to key name (Laravel default)

Enable logging for debugging:

```env
TRANSLATION_LOGGING=true
TRANSLATION_LOG_CHANNEL=stack
```

## Testing

When testing, you may want to disable API calls:

```php
// In tests/TestCase.php
protected function setUp(): void
{
    parent::setUp();
    
    // Mock the translation client
    $this->mock(TranslationClient::class, function ($mock) {
        $mock->shouldReceive('loadTranslations')
            ->andReturn(['messages.welcome' => 'Welcome']);
    });
}
```

## Troubleshooting

### Translations not loading

1. Check service URL is correct: `php artisan config:cache`
2. Verify API is accessible: `curl https://your-service.com/translation/manifest?locale=en`
3. Enable logging: `TRANSLATION_LOGGING=true`
4. Check logs: `tail -f storage/logs/laravel.log`

### Cache not clearing

```bash
php artisan cache:clear
php artisan translations:clear-cache
php artisan config:cache
```

### Performance issues

1. Ensure `TRANSLATION_PRELOAD=true` in production
2. Use Redis for caching instead of file cache
3. Increase cache TTL if translations rarely change

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Support

For issues and questions, please use the [GitHub issue tracker](https://github.com/ouredu/laravel-translation-client/issues).
