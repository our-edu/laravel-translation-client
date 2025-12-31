# Tenant Configuration Guide

## Overview

The package supports multiple tenant configuration strategies, from simple single-tenant to full multi-tenant applications.

---

## Strategy 1: Auto-Detect First Tenant (Recommended for Initial Setup)

**Best for**: Apps not yet multi-tenant ready

### Configuration

**Don't set** `TRANSLATION_TENANT_UUID` in `.env`:

```env
TRANSLATION_SERVICE_URL=http://translation-service
# TRANSLATION_TENANT_UUID not set - will auto-detect
```

### How It Works

The package automatically:
1. Checks config for `TRANSLATION_TENANT_UUID`
2. If not set, queries database: `SELECT uuid FROM tenants ORDER BY created_at LIMIT 1`
3. Caches result for 1 hour

### Example

```php
// Your tenants table:
// id | uuid                  | name      | created_at
// 1  | school-1-uuid         | School 1  | 2024-01-01
// 2  | school-2-uuid         | School 2  | 2024-01-02

// Package automatically uses: school-1-uuid
```

---

## Strategy 2: Fixed Tenant UUID

**Best for**: Single-tenant apps or testing

### Configuration

```env
TRANSLATION_TENANT_UUID=school-1-uuid
```

### How It Works

Package always uses the configured tenant UUID.

---

## Strategy 3: Per-User Tenant (Full Multi-Tenant)

**Best for**: Apps where each user belongs to a tenant

### Setup

#### Option A: User Model Method

Add to your `User` model:

```php
class User extends Authenticatable
{
    public function tenant_uuid(): ?string
    {
        return $this->tenant?->uuid;
    }
}
```

The package automatically checks for this method.

#### Option B: Middleware

Create middleware to set tenant dynamically:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use OurEdu\TranslationClient\Helpers\TenantResolver;

class SetTranslationTenant
{
    public function handle($request, Closure $next)
    {
        if (auth()->check()) {
            $tenantUuid = auth()->user()->tenant?->uuid;
            TenantResolver::setTenant($tenantUuid);
        }

        return $next($request);
    }
}
```

Register in `app/Http/Kernel.php`:

```php
protected $middlewareGroups = [
    'web' => [
        // ...
        \App\Http\Middleware\SetTranslationTenant::class,
    ],
];
```

---

## Strategy 4: Per-Request Tenant

**Best for**: API applications with tenant in request

### Middleware Example

```php
<?php

namespace App\Http\Middleware;

use Closure;
use OurEdu\TranslationClient\Helpers\TenantResolver;

class SetTenantFromRequest
{
    public function handle($request, Closure $next)
    {
        // From header
        $tenantUuid = $request->header('X-Tenant-UUID');
        
        // Or from subdomain
        $subdomain = $request->getHost();
        $tenant = Tenant::where('subdomain', $subdomain)->first();
        $tenantUuid = $tenant?->uuid;

        TenantResolver::setTenant($tenantUuid);

        return $next($request);
    }
}
```

---

## Migration Path

### Phase 1: Single Tenant (Now)

```env
# Don't set TRANSLATION_TENANT_UUID
# Auto-uses first tenant
```

**Result**: All translations use first tenant UUID

### Phase 2: Transition (Later)

Add middleware to detect tenant per user:

```php
// Middleware sets tenant dynamically
TenantResolver::setTenant($user->tenant_uuid);
```

**Result**: Translations per user's tenant

### Phase 3: Full Multi-Tenant (Future)

Remove auto-detection, require explicit tenant:

```php
// config/translation-client.php
'tenant_uuid' => null, // Must be set via middleware
```

**Result**: Strict multi-tenant enforcement

---

## Examples

### Example 1: School Management System

**Current State**: All schools share translations

```env
# No tenant UUID set
# Uses first school automatically
```

**Usage**:
```php
__('messages.welcome')  // Same for all schools
```

**Future State**: Each school has custom translations

```php
// Middleware sets tenant per logged-in user
if (auth()->user()->school_id === 1) {
    // Uses school-1-uuid translations
}
```

---

### Example 2: SaaS Application

**Current State**: Single tenant for testing

```env
TRANSLATION_TENANT_UUID=demo-tenant-uuid
```

**Future State**: Tenant per customer

```php
// Middleware detects from subdomain
$tenant = Tenant::where('subdomain', $request->getHost())->first();
TenantResolver::setTenant($tenant->uuid);
```

---

## TenantResolver API

### Methods

```php
use OurEdu\TranslationClient\Helpers\TenantResolver;

// Get current tenant (auto-detects if not set)
$uuid = TenantResolver::resolve();

// Get first tenant from database
$uuid = TenantResolver::getFirstTenant();

// Set tenant dynamically
TenantResolver::setTenant('school-1-uuid');
```

### Usage in Code

```php
// In a controller
public function switchTenant($tenantId)
{
    $tenant = Tenant::findOrFail($tenantId);
    TenantResolver::setTenant($tenant->uuid);
    
    // Now translations use this tenant
    return __('messages.welcome');
}
```

---

## Best Practices

### 1. Start Simple

Use auto-detection initially:
```env
# No TRANSLATION_TENANT_UUID
```

### 2. Add Middleware When Ready

```php
// Set tenant per user
TenantResolver::setTenant(auth()->user()->tenant_uuid);
```

### 3. Cache Tenant Resolution

The package caches the first tenant for 1 hour automatically.

### 4. Clear Cache on Tenant Changes

```php
Cache::forget('translation_client:first_tenant');
```

---

## Troubleshooting

### Translations Not Found

**Check current tenant**:
```php
dd(config('translation-client.tenant_uuid'));
```

**Check auto-detection**:
```php
dd(TenantResolver::getFirstTenant());
```

### Wrong Tenant Used

**Set explicitly**:
```php
TenantResolver::setTenant('correct-tenant-uuid');
```

---

## Summary

 **Auto-detection** - Uses first tenant automatically  
 **Fixed tenant** - Set via environment variable  
 **Per-user tenant** - Via User model method  
 **Per-request tenant** - Via middleware  
 **Flexible** - Easy migration from single to multi-tenant  

Start simple with auto-detection, evolve to full multi-tenant when ready! 
