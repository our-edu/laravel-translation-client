# Tenant Configuration Guide

## Overview

Every call this package makes to the translation service carries a tenant id, or
carries none at all. That id decides whether the service answers with a tenant's
own translations or with the shared base template.

`OurEdu\TranslationClient\Helpers\TenantResolver::resolve()` produces it, and this
document describes exactly what it does — no more.

> **This guide was rewritten on 2026-08-16.** It previously described four
> configuration strategies, three of which the package has never implemented. If
> you built against the old version, read [What does not
> work](#what-does-not-work-yet) before anything else.

---

## How the tenant is resolved

`resolve()` tries two sources, in order, and gives up:

```php
public static function resolve(): ?int
{
    $user = auth()->user();

    // 1. The authenticated user's tenant_id
    if (auth()->check() && !is_null($user->tenant_id)) {
        return (int) $user->tenant_id;
    }

    // 2. TenantContext, if the our-edu multi-tenant package is installed
    if (class_exists('Ouredu\MultiTenant\Tenancy\TenantContext')) {
        $tenantId = app('Ouredu\MultiTenant\Tenancy\TenantContext')->getTenantId();
        return !is_null($tenantId) ? (int) $tenantId : null;
    }

    return null;
}
```

**`null` is a valid answer, not a failure.** The service then serves the global
base template, which is the right content for an app that is not tenant-aware,
and for console commands and queued jobs where no user is authenticated.

---

## Strategy 1: Per-user tenant

**Best for**: apps where a user belongs to a tenant. This is the strategy the
package actually implements.

Your `User` model needs a `tenant_id` **attribute** — a real column, or an
accessor:

```php
class User extends Authenticatable
{
    // Either a tenant_id column on the users table, or:
    protected function tenantId(): Attribute
    {
        return Attribute::get(fn () => $this->tenant?->id);
    }
}
```

> A `tenant_id()` *method* is not enough. `resolve()` reads `$user->tenant_id` as
> a property, so a plain method is never called. It has to be a column or an
> accessor. The previous version of this guide got this wrong.

Nothing else to configure. Requests made while that user is authenticated carry
their tenant.

---

## Strategy 2: TenantContext

**Best for**: apps already using the our-edu multi-tenant package.

If `Ouredu\MultiTenant\Tenancy\TenantContext` is bound, `resolve()` asks it
whenever there is no authenticated user with a tenant. Nothing to configure here
either — the class being installed is the whole integration.

This is also the route that works in queued jobs and console commands, where
`auth()` is empty.

---

## What does not work (yet)

These are documented here because the package ships config and a public method
that imply otherwise.

| Thing | Status |
|---|---|
| `TRANSLATION_TENANT_ID` env / `translation-client.tenant_id` config | **Read by nothing.** Setting it has no effect. |
| `TenantResolver::setTenant($id)` | **No-op.** It writes `translation-client.tenant_id`, which nothing reads. |
| Auto-detecting the first tenant (`SELECT id FROM tenants ORDER BY created_at LIMIT 1`) | **Never existed.** No such query, and no caching of a resolved tenant. |

So there is currently **no way to pin a fixed tenant** for a single-tenant app,
and **no way to override the tenant per request** — a middleware calling
`setTenant()` from a header or subdomain does nothing.

Making `setTenant()` work is small: `resolve()` would need to read
`config('translation-client.tenant_id')`. The open question is where that read
belongs in the order, and it is a product decision rather than a mechanical one:

- **Config as an override, checked first** — `setTenant()` means "use this
  tenant", so a per-request middleware wins. But a stale `TRANSLATION_TENANT_ID`
  left in `.env` would then silently override per-user resolution for every
  request in a multi-tenant app.
- **Config as a fallback, checked last** — safe, and correct for pinning a
  single-tenant app, but a per-request `setTenant()` would lose to the
  authenticated user, which is not what a caller of that method expects.

Neither is obviously right, so nothing has been implemented. Raise it before
building on either.

---

## Verifying what you get

```php
use OurEdu\TranslationClient\Helpers\TenantResolver;

dump(TenantResolver::resolve());  // int, or null for the global template
```

`null` while a user is logged in means their `tenant_id` attribute is absent or
null — check that first, before looking at anything in this package.
