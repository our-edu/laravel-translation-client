# Quick Setup: Tenant Configuration

## For Initial Setup (Recommended)

**Don't set `TRANSLATION_TENANT_UUID` in `.env`**

The package will automatically use the first tenant from your `tenants` table.

```env
TRANSLATION_SERVICE_URL=http://translation-service
TRANSLATION_APP_PREFIX=DOK
# TRANSLATION_TENANT_UUID not set - auto-detects first tenant
```

---

## How It Works

1. **Package checks** if `TRANSLATION_TENANT_UUID` is set in config
2. **If not set**, queries database:
   ```sql
   SELECT uuid FROM tenants ORDER BY created_at LIMIT 1
   ```
3. **Caches result** for 1 hour

---

## Example

Your `tenants` table:

| id | uuid | name | created_at |
|----|------|------|------------|
| 1 | school-1-uuid | School 1 | 2024-01-01 |
| 2 | school-2-uuid | School 2 | 2024-01-02 |

**Package automatically uses**: `school-1-uuid`

---

## Later: Full Multi-Tenant

When your codebase is ready for per-user tenants, add middleware:

```php
use OurEdu\TranslationClient\Helpers\TenantResolver;

class SetTranslationTenant
{
    public function handle($request, Closure $next)
    {
        if (auth()->check()) {
            TenantResolver::setTenant(auth()->user()->tenant_uuid);
        }
        return $next($request);
    }
}
```

**No other changes needed!**

---

## Summary

 **Now**: Auto-uses first tenant (zero config)  
 **Later**: Add middleware for per-user tenants  
 **No code changes**: Transparent upgrade path  

See [TENANT_CONFIG.md](TENANT_CONFIG.md) for full details.
