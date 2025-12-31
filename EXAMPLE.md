# Example Laravel Application Using Translation Client

This example shows how to integrate the translation client package into a Laravel application.

## Installation

### 1. Add Package Repository (if not published to Packagist)

In your Laravel app's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../packages/laravel-translation-client"
        }
    ],
    "require": {
        "ouredu/laravel-translation-client": "*"
    }
}
```

Then run:
```bash
composer update ouredu/laravel-translation-client
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --provider="OurEdu\TranslationClient\TranslationServiceProvider"
```

### 3. Configure Environment

Add to `.env`:

```env
TRANSLATION_SERVICE_URL=http://localhost:8000
TRANSLATION_TENANT_UUID=school-1-uuid
TRANSLATION_PRELOAD=true
TRANSLATION_CLIENT=backend
TRANSLATION_MANIFEST_TTL=300
TRANSLATION_BUNDLE_TTL=3600
```

### 4. Configure Available Locales

In `config/app.php`:

```php
'available_locales' => ['en', 'ar'],
```

## Usage Examples

### In Controllers

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class WelcomeController extends Controller
{
    public function index()
    {
        return view('welcome', [
            'title' => __('messages.welcome'),
            'description' => trans('messages.app_description'),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|min:3',
            'email' => 'required|email',
        ]);

        // Validation messages automatically use translations
        // from validation.required, validation.email, etc.

        return response()->json([
            'message' => __('messages.success'),
        ]);
    }
}
```

### In Blade Templates

```blade
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <title>{{ __('messages.app_name') }}</title>
</head>
<body>
    <h1>{{ __('messages.welcome') }}</h1>
    
    <p>{{ trans('messages.description', ['name' => auth()->user()->name]) }}</p>
    
    @error('email')
        <span class="error">{{ $message }}</span>
    @enderror
    
    <!-- Language Switcher -->
    <div class="language-switcher">
        @foreach(config('app.available_locales') as $locale)
            <a href="{{ route('locale.switch', $locale) }}"
               class="{{ app()->getLocale() === $locale ? 'active' : '' }}">
                {{ strtoupper($locale) }}
            </a>
        @endforeach
    </div>
</body>
</html>
```

### Locale Switching Route

In `routes/web.php`:

```php
Route::get('/locale/{locale}', function ($locale) {
    if (in_array($locale, config('app.available_locales'))) {
        session(['locale' => $locale]);
        app()->setLocale($locale);
    }
    return redirect()->back();
})->name('locale.switch');
```

### Middleware Setup

In `app/Http/Kernel.php`:

```php
protected $middlewareGroups = [
    'web' => [
        // ... other middleware
        \OurEdu\TranslationClient\Middleware\SetLocaleFromRequest::class,
    ],
    
    'api' => [
        // ... other middleware
        \OurEdu\TranslationClient\Middleware\SetLocaleFromRequest::class,
    ],
];
```

### Scheduled Translation Sync

In `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    // Sync translations every hour
    $schedule->command('translations:sync')->hourly();
    
    // Or sync at specific times
    $schedule->command('translations:sync')->dailyAt('03:00');
}
```

### Using the Facade

```php
<?php

namespace App\Services;

use OurEdu\TranslationClient\Facades\Translation;

class TranslationService
{
    public function checkForUpdates(string $locale): array
    {
        return Translation::checkVersion($locale);
    }

    public function getMessagesForLocale(string $locale): array
    {
        return Translation::fetchBundle($locale, ['messages']);
    }

    public function refreshCache(): void
    {
        Translation::clearCache();
    }
}
```

### API Response Example

```php
<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'message' => __('api.users_retrieved'),
            'data' => User::all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users',
        ]);

        $user = User::create($validated);

        return response()->json([
            'message' => __('api.user_created'),
            'data' => $user,
        ], 201);
    }
}
```

### Multi-Tenant Example

For multi-tenant applications:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetTenantTranslations
{
    public function handle(Request $request, Closure $next)
    {
        // Get tenant from authenticated user or subdomain
        $tenant = auth()->user()?->tenant 
            ?? Tenant::where('subdomain', $request->getHost())->first();

        if ($tenant) {
            // Dynamically set tenant UUID for translations
            config(['translation-client.tenant_uuid' => $tenant->uuid]);
        }

        return $next($request);
    }
}
```

### Testing

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use OurEdu\TranslationClient\Services\TranslationClient;

class TranslationTest extends TestCase
{
    public function test_translations_load_correctly()
    {
        // Mock the translation client
        $this->mock(TranslationClient::class, function ($mock) {
            $mock->shouldReceive('loadTranslations')
                ->with('en')
                ->andReturn([
                    'messages.welcome' => 'Welcome',
                    'messages.goodbye' => 'Goodbye',
                ]);
        });

        $this->assertEquals('Welcome', __('messages.welcome'));
    }

    public function test_locale_switching()
    {
        $response = $this->get('/locale/ar');
        
        $response->assertRedirect();
        $this->assertEquals('ar', session('locale'));
    }
}
```

## Deployment

### Production Checklist

1. **Enable Preloading**:
   ```env
   TRANSLATION_PRELOAD=true
   ```

2. **Use Redis for Caching**:
   ```env
   CACHE_DRIVER=redis
   TRANSLATION_CACHE_STORE=redis
   ```

3. **Set Appropriate TTLs**:
   ```env
   TRANSLATION_MANIFEST_TTL=300  # 5 minutes
   TRANSLATION_BUNDLE_TTL=3600   # 1 hour
   ```

4. **Schedule Translation Sync**:
   ```bash
   php artisan schedule:work
   ```

5. **Clear Caches on Deployment**:
   ```bash
   php artisan cache:clear
   php artisan config:cache
   php artisan translations:sync
   ```

### Docker Example

```dockerfile
FROM php:8.2-fpm

# Install dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Install Redis extension for caching
RUN pecl install redis && docker-php-ext-enable redis

# Copy application
COPY . /var/www
WORKDIR /var/www

# Install composer dependencies
RUN composer install --optimize-autoloader --no-dev

# Cache configuration
RUN php artisan config:cache
RUN php artisan route:cache
RUN php artisan view:cache

# Sync translations on container start
CMD php artisan translations:sync && php-fpm
```

## Monitoring

### Log Translation Fetches

Enable logging in production for monitoring:

```env
TRANSLATION_LOGGING=true
TRANSLATION_LOG_CHANNEL=stack
```

### Monitor Cache Hit Rates

```php
// In AppServiceProvider
public function boot()
{
    Event::listen('cache.hit', function ($key) {
        if (str_starts_with($key, 'translation:')) {
            Metrics::increment('translation.cache.hit');
        }
    });

    Event::listen('cache.missed', function ($key) {
        if (str_starts_with($key, 'translation:')) {
            Metrics::increment('translation.cache.miss');
        }
    });
}
```

## Troubleshooting

### Translations Not Updating

```bash
# Clear all caches
php artisan cache:clear
php artisan translations:clear-cache
php artisan config:cache

# Force sync
php artisan translations:sync --force
```

### Performance Issues

```bash
# Check if preloading is enabled
php artisan config:show translation-client.preload

# Verify cache driver
php artisan config:show cache.default

# Monitor API response times
php artisan translations:sync --locale=ar -v
```
