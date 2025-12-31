# Writing Translations to the Service

This guide shows how to use the Laravel Translation Client package to **push translations** to the centralized translation service.

---

## Overview

The package supports **bidirectional** translation management:
- **Read** (fetch) translations from the service
- **Write** (push) translations to the service

This enables:
-  **Migration** - Import existing Laravel translations to the service
-  **Creation** - Create new translations programmatically
-  **Updates** - Update existing translations
-  **Auto-discovery** - Register missing translation keys

---

## Write API Methods

### 1. Push Multiple Translations

```php
use OurEdu\TranslationClient\Facades\Translation;

$result = Translation::pushTranslations([
    [
        'locale' => 'ar',
        'group' => 'messages',
        'key' => 'welcome',
        'value' => 'مرحبا',
        'client' => 'backend',
        'is_active' => true,
    ],
    [
        'locale' => 'ar',
        'group' => 'messages',
        'key' => 'goodbye',
        'value' => 'وداعاً',
        'client' => 'backend',
        'is_active' => true,
    ],
]);

// Returns:
// [
//     'success' => true,
//     'created' => 2,
//     'updated' => 0,
//     'total' => 2,
// ]
```

### 2. Push Single Translation

```php
$result = Translation::pushTranslation(
    locale: 'ar',
    group: 'messages',
    key: 'hello',
    value: 'مرحباً',
    client: 'backend',
    isActive: true
);
```

### 3. Import from Laravel Lang Files

```php
// Import all translations for a locale
$result = Translation::importFromFiles('ar', lang_path());

// Returns:
// [
//     'created' => 150,
//     'updated' => 25,
//     'total' => 175,
// ]
```

---

## Console Commands

### Import Translations Command

Import translations from Laravel lang files to the service:

```bash
# Import all locales
php artisan translations:import

# Import specific locale
php artisan translations:import --locale=ar

# Import from custom path
php artisan translations:import --path=/path/to/lang
```

**Example Output**:
```
 Importing translations to Translation Service...

Found locales: en, ar

 Importing en...
   - Created: 120
   - Updated: 15
   - Total: 135

 Importing ar...
   - Created: 130
   - Updated: 10
   - Total: 140

----------------------------------------
- Total Created: 250
- Total Updated: 25
----------------------------------------
```

---

## Usage Examples

### 1. Migration from File-Based to API-Based

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use OurEdu\TranslationClient\Services\TranslationClient;

class MigrateTranslations extends Command
{
    protected $signature = 'app:migrate-translations';
    protected $description = 'Migrate all translations to Translation Service';

    public function handle(TranslationClient $client): void
    {
        $locales = ['en', 'ar', 'fr'];

        foreach ($locales as $locale) {
            $this->info("Migrating {$locale}...");
            
            $result = $client->importFromFiles($locale, lang_path());
            
            $this->info("Created: {$result['created']}, Updated: {$result['updated']}");
        }

        $this->info('Migration complete!');
    }
}
```

### 2. Auto-Register Missing Keys

Create a helper to auto-register missing translation keys:

```php
<?php

namespace App\Helpers;

use OurEdu\TranslationClient\Facades\Translation;
use Illuminate\Support\Facades\Log;

class TranslationHelper
{
    public static function registerMissing(string $key, string $value): string
    {
        try {
            // Parse key (e.g., "messages.welcome")
            [$group, $translationKey] = explode('.', $key, 2);
            
            // Push to service
            Translation::pushTranslation(
                locale: app()->getLocale(),
                group: $group,
                key: $translationKey,
                value: $value,
                client: 'backend',
                isActive: false // Mark as inactive for review
            );
            
            Log::info("Auto-registered translation: {$key}");
        } catch (\Exception $e) {
            Log::error("Failed to register translation: {$key}", [
                'error' => $e->getMessage()
            ]);
        }

        return $value;
    }
}
```

Usage:
```php
// In your code
$message = __('messages.new_key') ?: 
    TranslationHelper::registerMissing('messages.new_key', 'Default Value');
```

### 3. Programmatic Translation Creation

```php
<?php

namespace App\Services;

use OurEdu\TranslationClient\Services\TranslationClient;

class TranslationManagementService
{
    public function __construct(
        private TranslationClient $client
    ) {}

    public function createTranslation(
        string $locale,
        string $group,
        string $key,
        string $value
    ): array {
        return $this->client->pushTranslation(
            locale: $locale,
            group: $group,
            key: $key,
            value: $value
        );
    }

    public function bulkCreate(array $translations): array
    {
        return $this->client->pushTranslations($translations);
    }

    public function updateTranslation(
        string $locale,
        string $group,
        string $key,
        string $newValue
    ): array {
        // Same as create - API does upsert
        return $this->createTranslation($locale, $group, $key, $newValue);
    }
}
```

### 4. API Endpoint for Translation Management

Create an API endpoint for managing translations:

```php
<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use OurEdu\TranslationClient\Services\TranslationClient;

class TranslationController extends Controller
{
    public function __construct(
        private TranslationClient $client
    ) {}

    /**
     * Create or update a translation
     * POST /api/translations
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'locale' => 'required|string',
            'group' => 'required|string',
            'key' => 'required|string',
            'value' => 'required|string',
            'client' => 'nullable|string',
        ]);

        $result = $this->client->pushTranslation(
            locale: $validated['locale'],
            group: $validated['group'],
            key: $validated['key'],
            value: $validated['value'],
            client: $validated['client'] ?? null
        );

        return response()->json($result);
    }

    /**
     * Bulk import translations
     * POST /api/translations/bulk
     */
    public function bulkStore(Request $request)
    {
        $validated = $request->validate([
            'translations' => 'required|array',
            'translations.*.locale' => 'required|string',
            'translations.*.group' => 'required|string',
            'translations.*.key' => 'required|string',
            'translations.*.value' => 'required|string',
        ]);

        $result = $this->client->pushTranslations($validated['translations']);

        return response()->json($result);
    }
}
```

### 5. Seeder for Initial Translations

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use OurEdu\TranslationClient\Services\TranslationClient;

class TranslationSeeder extends Seeder
{
    public function run(TranslationClient $client): void
    {
        $translations = [
            // English
            [
                'locale' => 'en',
                'group' => 'messages',
                'key' => 'welcome',
                'value' => 'Welcome',
                'client' => 'backend',
                'is_active' => true,
            ],
            [
                'locale' => 'en',
                'group' => 'messages',
                'key' => 'goodbye',
                'value' => 'Goodbye',
                'client' => 'backend',
                'is_active' => true,
            ],
            
            // Arabic
            [
                'locale' => 'ar',
                'group' => 'messages',
                'key' => 'welcome',
                'value' => 'مرحبا',
                'client' => 'backend',
                'is_active' => true,
            ],
            [
                'locale' => 'ar',
                'group' => 'messages',
                'key' => 'goodbye',
                'value' => 'وداعاً',
                'client' => 'backend',
                'is_active' => true,
            ],
        ];

        $result = $client->pushTranslations($translations);

        $this->command->info("Created: {$result['created']}, Updated: {$result['updated']}");
    }
}
```

---

## Translation Data Structure

When pushing translations, each translation object should have:

```php
[
    'tenant_uuid' => 'optional-tenant-uuid', // null for global
    'locale' => 'ar',                        // required
    'group' => 'messages',                   // required
    'key' => 'welcome',                      // required
    'value' => 'مرحبا',                      // required
    'client' => 'backend',                   // optional: backend, frontend, mobile
    'is_active' => true,                     // optional: default true
]
```

---

## Workflow: File-Based to API-Based Migration

### Step 1: Initial Import

```bash
# Import all existing translations
php artisan translations:import
```

### Step 2: Verify Import

```bash
# Sync to verify
php artisan translations:sync
```

### Step 3: Lock File-Based Translations (Optional)

Make lang files read-only to prevent future edits:

```bash
chmod -R 444 resources/lang/
```

### Step 4: CI/CD Integration

Add to your deployment script:

```bash
# After deployment, sync translations
php artisan translations:sync
```

---

## Best Practices

### 1. **Use Import Command for Initial Migration**

```bash
php artisan translations:import
```

### 2. **Disable File Writes After Migration**

Update your workflow to prevent developers from editing lang files directly.

### 3. **Use Service for New Translations**

Create translations via the Translation Service UI or API, not in files.

### 4. **Auto-Register in Development**

In development, auto-register missing keys:

```php
// In AppServiceProvider
if (app()->environment('local')) {
    Event::listen('translation.missing', function ($key, $locale) {
        TranslationHelper::registerMissing($key, $key);
    });
}
```

### 5. **Batch Updates**

For bulk updates, use `pushTranslations()` instead of multiple `pushTranslation()` calls:

```php
//  Good - Single API call
Translation::pushTranslations($translations);

//  Bad - Multiple API calls
foreach ($translations as $trans) {
    Translation::pushTranslation(...);
}
```

---

## Error Handling

```php
use OurEdu\TranslationClient\Services\TranslationClient;

try {
    $result = $client->pushTranslation(
        locale: 'ar',
        group: 'messages',
        key: 'test',
        value: 'اختبار'
    );
    
    Log::info('Translation created', $result);
} catch (\Exception $e) {
    Log::error('Failed to create translation', [
        'error' => $e->getMessage()
    ]);
    
    // Handle error (e.g., queue for retry)
}
```

---

## Summary

The package now supports **bidirectional** translation management:

 **Read** - Fetch translations from service  
 **Write** - Push translations to service  
 **Import** - Bulk import from Laravel lang files  
 **Auto-register** - Programmatically create missing keys  
 **Migration** - Easy transition from file-based to API-based  

This enables a complete workflow for managing translations centrally while maintaining the familiar Laravel translation API.
