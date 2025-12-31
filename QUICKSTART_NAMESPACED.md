# Quick Start: Namespaced Translations

## Your Current Setup

You have apps with this structure:

```
src/App/
├── Translation/
│   └── Lang/
│       ├── en/
│       │   └── messages.php  // ['welcome' => 'Welcome']
│       └── ar/
│           └── messages.php  // ['welcome' => 'مرحبا']
├── Dashboard/
│   └── Lang/
│       └── ...
```

And you use translations like:
```php
__('Translation::messages.welcome')
__('Dashboard::menu.home')
```

---

## Migration Steps

### 1. Install Package

```bash
composer require ouredu/laravel-translation-client
php artisan vendor:publish --provider="OurEdu\TranslationClient\TranslationServiceProvider"
```

### 2. Configure

```env
TRANSLATION_SERVICE_URL=http://your-translation-service
TRANSLATION_TENANT_UUID=your-tenant-uuid
```

### 3. Import Namespaced Translations

```bash
php artisan translations:import-namespaced
```

**Output**:
```
 Importing namespaced translations to Translation Service...

Found 2 Lang directories

 Processing namespace: Translation
   Path: /path/to/src/App/Translation/Lang
   - Created: 45
   - Updated: 0

 Processing namespace: Dashboard
   Path: /path/to/src/App/Dashboard/Lang
   - Created: 30
   - Updated: 0

----------------------------------------
- Total Created: 75
- Total Updated: 0
----------------------------------------
```

### 4. Verify

```bash
php artisan translations:sync
```

### 5. Test

```php
// Should work exactly as before
dd(__('Translation::messages.welcome')); // "Welcome" or "مرحبا"
dd(__('Dashboard::menu.home'));
```

---

## What Happens Behind the Scenes

### Before Import

**Translation Service**: Empty

**Your App**: Uses files from `src/App/*/Lang`

### After Import

**Translation Service**: 
- Group: `Translation::messages`, Key: `welcome`, Value: `Welcome`
- Group: `Dashboard::menu`, Key: `home`, Value: `Home`

**Your App**: Fetches from API with namespace support

---

## Keep or Remove Custom Provider?

### Option 1: Keep Both (Recommended for Gradual Migration)

Keep your custom `TranslationServiceProvider` for fallback:

```php
// config/app.php
'providers' => [
    App\BaseApp\Providers\TranslationServiceProvider::class, // Keep for fallback
    // Package provider auto-registered
],
```

### Option 2: Remove Custom Provider (Full Migration)

Once verified, remove your custom provider:

```php
// config/app.php
'providers' => [
    // App\BaseApp\Providers\TranslationServiceProvider::class, // Removed
    // Package provider auto-registered
],
```

---

## That's It!

Your namespaced translations now work with the centralized service! 🎉

For more details, see [NAMESPACED.md](NAMESPACED.md)
