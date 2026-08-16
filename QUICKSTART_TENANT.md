# Quick Setup: Tenant Configuration

## The short version

There is nothing to configure. `TenantResolver::resolve()` reads the
authenticated user's `tenant_id`, falls back to `TenantContext` if the our-edu
multi-tenant package is installed, and otherwise returns `null`.

`null` is fine: the service then serves the shared base template, which is the
right content for an app that is not tenant-aware, and for console commands and
queued jobs where nobody is logged in.

```env
TRANSLATION_SERVICE_URL=http://translation-service
TRANSLATION_APP_PREFIX=DOK
```

---

## To get per-tenant translations

Give your `User` model a `tenant_id` **attribute** — a real column, or an
accessor:

```php
protected function tenantId(): Attribute
{
    return Attribute::get(fn () => $this->tenant?->id);
}
```

That is the whole integration. A `tenant_id()` *method* will not do: the resolver
reads `$user->tenant_id` as a property, so a plain method is never called.

---

## What this guide used to say

It described the package checking `TRANSLATION_TENANT_ID`, then running
`SELECT id FROM tenants ORDER BY created_at LIMIT 1` and caching the result for an
hour, and it suggested a middleware calling `TenantResolver::setTenant()`.

**None of that is implemented.** There is no auto-detect query, no caching,
`TRANSLATION_TENANT_ID` is read by nothing, and `setTenant()` writes a config key
nothing reads — so it is a no-op. A middleware built on it silently does nothing.

Corrected 2026-08-16. See [TENANT_CONFIG.md](TENANT_CONFIG.md) for the full
picture, including the open question about how a fixed or per-request tenant
*should* work.
