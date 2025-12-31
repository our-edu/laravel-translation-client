# App Name Prefix Feature

## Overview

Add an **app name prefix** to all translation groups to namespace translations per application. This prevents conflicts when multiple apps share the same translation service.

---

## Configuration

Add to your `.env`:

```env
# Optional: Prefix all translation groups with app name
TRANSLATION_APP_PREFIX=EDMS
```

Or in `config/translation-client.php`:

```php
'app_name_prefix' => env('TRANSLATION_APP_PREFIX'),
```

---

## How It Works

### Without Prefix (Default)

**Groups sent to API:**
- `messages`
- `validation`
- `Translation::messages`
- `Dashboard::menu`

**Usage:**
```php
__('messages.welcome')
__('Translation::messages.welcome')
```

---

### With Prefix (e.g., `EDMS`)

**Groups sent to API:**
- `EDMS:messages`
- `EDMS:validation`
- `EDMS:Translation::messages`
- `EDMS:Dashboard::menu`

**Usage (unchanged in your code):**
```php
__('messages.welcome')  // Fetches from "EDMS:messages"
__('Translation::messages.welcome')  // Fetches from "EDMS:Translation::messages"
```

---

## Examples

### Example 1: DOK Application

```env
TRANSLATION_APP_PREFIX=DOK
```

**API Requests:**
```json
{
  "group": "DOK:messages",
  "key": "welcome",
  "value": "Welcome"
}
```

```json
{
  "group": "DOK:Translation::messages",
  "key": "welcome",
  "value": "Welcome"
}
```

---

### Example 2: EDMS Application

```env
TRANSLATION_APP_PREFIX=EDMS
```

**API Requests:**
```json
{
  "group": "EDMS:messages",
  "key": "welcome",
  "value": "Welcome"
}
```

```json
{
  "group": "EDMS:Dashboard::menu",
  "key": "home",
  "value": "Home"
}
```

---

## Multi-App Scenario

### Shared Translation Service

```
Translation Service Database:
├── DOK:messages.welcome = "Welcome to DOK"
├── DOK:Translation::messages.hello = "Hello from DOK"
├── EDMS:messages.welcome = "Welcome to EDMS"
└── EDMS:Dashboard::menu.home = "EDMS Home"
```

### DOK App

```env
TRANSLATION_APP_PREFIX=DOK
```

```php
__('messages.welcome')  // "Welcome to DOK"
```

### EDMS App

```env
TRANSLATION_APP_PREFIX=EDMS
```

```php
__('messages.welcome')  // "Welcome to EDMS"
```

**No conflicts!** Each app has its own namespace.

---

## Import Behavior

### Standard Import

```bash
php artisan translations:import
```

**Without prefix:**
- `messages` → `messages`
- `validation` → `validation`

**With prefix (EDMS):**
- `messages` → `EDMS:messages`
- `validation` → `EDMS:validation`

---

### Namespaced Import

```bash
php artisan translations:import-namespaced
```

**Without prefix:**
- `Translation/Lang` → `Translation::messages`
- `Dashboard/Lang` → `Dashboard::menu`

**With prefix (EDMS):**
- `Translation/Lang` → `EDMS:Translation::messages`
- `Dashboard/Lang` → `EDMS:Dashboard::menu`

---

## Benefits

 **Namespace per app** - Multiple apps can use same group names  
 **No code changes** - Transparent to your application code  
 **Centralized service** - All apps share one translation service  
 **Tenant isolation** - Combine with tenant_uuid for full isolation  

---

## Best Practices

### 1. Use Short, Uppercase Prefixes

```env
 TRANSLATION_APP_PREFIX=DOK
 TRANSLATION_APP_PREFIX=EDMS
 TRANSLATION_APP_PREFIX=my-long-app-name
```

### 2. Set Once Per Application

Each application should have its own prefix:
- DOK App: `DOK`
- EDMS App: `EDMS`
- Portal App: `PORTAL`

### 3. Combine with Tenant UUID

```env
TRANSLATION_APP_PREFIX=DOK
TRANSLATION_TENANT_UUID=school-1-uuid
```

This gives you **app-level** AND **tenant-level** isolation.

---

## Migration

### Existing Apps Without Prefix

If you have existing translations without prefix and want to add one:

1. **Export existing translations**
2. **Set new prefix**: `TRANSLATION_APP_PREFIX=EDMS`
3. **Re-import**: `php artisan translations:import`
4. **Old translations** remain without prefix
5. **New translations** get the prefix

To migrate old translations, you'd need to update the `group` field in the database:

```sql
UPDATE static_translations 
SET group = CONCAT('EDMS:', group)
WHERE tenant_uuid = 'your-tenant-uuid';
```

---

## Summary

The app name prefix feature provides:

 **Automatic prefixing** of all translation groups  
 **Zero code changes** required  
 **Multi-app support** on shared translation service  
 **Simple configuration** via environment variable  

Perfect for organizations running multiple Laravel applications! 
