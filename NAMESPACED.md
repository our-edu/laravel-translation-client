# Namespaced Translations Support

This guide explains how to use the package with applications that have **modular/namespaced translation structures**.

---

## Problem

Some Laravel applications organize translations in modular directory structures:

```
src/App/
├── Translation/
│   └── Lang/
│       ├── en/
│       │   └── messages.php
│       └── ar/
│           └── messages.php
├── Dashboard/
│   └── Lang/
│       ├── en/
│       │   └── menu.php
│       └── ar/
│           └── menu.php
```

These apps use custom `TranslationServiceProvider` to register translations with namespaces:

```php
// Usage in code
__('Translation::messages.welcome')
__('Dashboard::menu.home')
```

---

## Solution

The package now supports **namespaced translations** both for reading and writing.

---

## Importing Namespaced Translations

### Command

```bash
# Import all namespaced translations from src/App
php artisan translations:import-namespaced

# Import specific locale
php artisan translations:import-namespaced --locale=ar

# Custom base path
php artisan translations:import-namespaced --path=src/App

# Custom pattern
php artisan translations:import-namespaced --pattern="*/Lang"
```

### How It Works

1. **Discovers** all `Lang` directories in your modular structure
2. **Extracts** namespace from directory path (e.g., `Translation/Lang` → `Translation`)
3. **Prefixes** groups with namespace (e.g., `messages` → `Translation::messages`)
4. **Pushes** to translation service with namespaced group names

### Example Output

```
 Importing namespaced translations to Translation Service...

Found 3 Lang directories

 Processing namespace: Translation
   Path: /path/to/src/App/Translation/Lang
   - Created: 45
   - Updated: 5

 Processing namespace: Dashboard
   Path: /path/to/src/App/Dashboard/Lang
   - Created: 30
   - Updated: 2

 Processing namespace: Auth
   Path: /path/to/src/App/Auth/Lang
   - Created: 20
   - Updated: 1

----------------------------------------
- Total Created: 95
- Total Updated: 8
----------------------------------------
```

---

## Using Namespaced Translations

Once imported, use them normally in your code:

```php
// In controllers
__('Translation::messages.welcome')
trans('Dashboard::menu.home')

// In Blade
{{ __('Translation::messages.welcome') }}
{{ trans('Dashboard::menu.home', ['name' => $user->name]) }}
```

The `ApiTranslationLoader` automatically handles the namespace prefix.

---

## Directory Structure Examples

### Pattern 1: Module/Lang

```
src/App/
├── Translation/
│   └── Lang/
│       ├── en/
│       └── ar/
├── Dashboard/
│   └── Lang/
│       ├── en/
│       └── ar/
```

**Namespace**: `Translation`, `Dashboard`  
**Usage**: `Translation::messages.key`

### Pattern 2: Module/Views/Lang

```
src/App/
├── Translation/
│   └── Views/
│       └── Lang/
│           ├── en/
│           └── ar/
├── Dashboard/
│   └── Views/
│       └── Lang/
│           ├── en/
│           └── ar/
```

**Namespace**: `TranslationViews`, `DashboardViews`  
**Usage**: `TranslationViews::messages.key`

### Pattern 3: Deep Nesting

```
src/App/
├── Admin/
│   └── Users/
│       └── Lang/
│           ├── en/
│           └── ar/
```

**Namespace**: `AdminUsers`  
**Usage**: `AdminUsers::messages.key`

---

## Custom TranslationServiceProvider Compatibility

### Example 1: Two-Level Modules

Your existing provider:

```php
protected function fullQualifiedModuleFormDirectory($file): string
{
    $moduleNameSpace = trim(Str::replaceFirst(app()->basePath(), '', $file), DIRECTORY_SEPARATOR);
    $moduleNameSpace = explode('/', $moduleNameSpace);
    return $moduleNameSpace[2] . $moduleNameSpace[3];
}
```

**Import command**:
```bash
php artisan translations:import-namespaced --path=src/App --pattern="*/*/Lang"
```

### Example 2: Single-Level Modules

Your existing provider:

```php
protected function fullQualifiedModuleFormDirectory($file): string
{
    $moduleNameSpace = trim(Str::replaceFirst(app()->basePath(), '', $file), DIRECTORY_SEPARATOR);
    $moduleNameSpace = explode('/', $moduleNameSpace);
    return $moduleNameSpace[2];
}
```

**Import command**:
```bash
php artisan translations:import-namespaced --path=src/App --pattern="*/Lang"
```

---

## Programmatic Import

```php
use OurEdu\TranslationClient\Services\TranslationClient;

class ImportNamespacedTranslations
{
    public function __construct(
        private TranslationClient $client
    ) {}

    public function import(): void
    {
        $basePath = base_path('src/App');
        $langDirs = glob($basePath . '/*/Lang', GLOB_ONLYDIR);

        foreach ($langDirs as $langDir) {
            $namespace = $this->getNamespace($langDir);
            
            // Import each locale
            foreach (['en', 'ar'] as $locale) {
                $this->importLocale($langDir, $locale, $namespace);
            }
        }
    }

    private function getNamespace(string $langDir): string
    {
        // Extract module name from path
        $parts = explode('/', $langDir);
        $moduleIndex = array_search('App', $parts) + 1;
        return $parts[$moduleIndex];
    }

    private function importLocale(string $langDir, string $locale, string $namespace): void
    {
        $localeDir = $langDir . '/' . $locale;
        $files = glob($localeDir . '/*.php');

        $translations = [];

        foreach ($files as $file) {
            $group = basename($file, '.php');
            $data = include $file;

            $translations = array_merge(
                $translations,
                $this->flatten($data, $locale, "{$namespace}::{$group}")
            );
        }

        $this->client->pushTranslations($translations);
    }
}
```

---

## Migration Workflow

### Step 1: Keep Existing Provider

Keep your custom `TranslationServiceProvider` registered - it will still work for local file loading during development.

### Step 2: Import to Service

```bash
php artisan translations:import-namespaced
```

### Step 3: Verify

```bash
php artisan translations:sync
```

Test that namespaced translations work:

```php
dd(__('Translation::messages.welcome'));
```

### Step 4: Optional - Remove Custom Provider

Once verified, you can optionally remove the custom provider since the package handles everything.

---

## Best Practices

### 1. **Consistent Namespace Convention**

Use the same namespace convention in your code and import:

```php
// If your code uses:
__('Translation::messages.key')

// Import should create:
group: "Translation::messages"
```

### 2. **Batch Import**

Import all namespaces at once:

```bash
php artisan translations:import-namespaced
```

### 3. **Sync After Import**

Always sync after importing to verify:

```bash
php artisan translations:sync
```

### 4. **Test Namespaced Access**

```php
// Test each namespace
$namespaces = ['Translation', 'Dashboard', 'Auth'];

foreach ($namespaces as $ns) {
    $test = __("{$ns}::messages.test");
    dump("{$ns}: {$test}");
}
```

---

## Troubleshooting

### Namespace Not Found

**Problem**: `__('Translation::messages.key')` returns the key instead of value

**Solution**: Check that the group was imported with the namespace prefix:

```bash
# Enable logging
TRANSLATION_LOGGING=true

# Check logs
tail -f storage/logs/laravel.log
```

### Wrong Namespace Format

**Problem**: Namespace doesn't match your code

**Solution**: Adjust the import pattern:

```bash
# For src/App/Module/Lang
php artisan translations:import-namespaced --pattern="*/Lang"

# For src/App/Module/Views/Lang
php artisan translations:import-namespaced --pattern="*/*/Lang"
```

### Multiple Patterns

**Problem**: Different modules use different nesting levels

**Solution**: Run import multiple times with different patterns:

```bash
php artisan translations:import-namespaced --pattern="*/Lang"
php artisan translations:import-namespaced --pattern="*/*/Lang"
```

---

## Summary

 **Import namespaced translations** with `translations:import-namespaced`  
 **Auto-detects** Lang directories in modular structures  
 **Preserves namespaces** (e.g., `Translation::messages`)  
 **Compatible** with existing custom TranslationServiceProviders  
 **Works seamlessly** with `trans()` and `__()` helpers  

Your modular translation structure is now fully supported! 🎉
