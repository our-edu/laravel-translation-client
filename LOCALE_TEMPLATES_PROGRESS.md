# Regional Locale Templates — Client Package Progress

**Companion to:** `LOCALE_TEMPLATES_MIGRATION.md` §6.8, which lives in the sibling
**`translation-service`** repository along with its own
[`LOCALE_TEMPLATES_PROGRESS.md`](../translation-service/LOCALE_TEMPLATES_PROGRESS.md). Section numbers
below refer to the design doc.
**Branch:** `regional-locale-templates`
**Scope:** `our-edu/laravel-translation-client` — the Composer package every consuming Laravel app
installs to talk to the translation service.
**Last updated:** 2026-08-16

---

## Why this exists separately

The service-side work (M1–M8) is complete and recorded in that repo's progress file. §6.8 lists the
changes this package needs so it does not break, or silently degrade, once the service changes ship.
Those are tracked here as **C1–C5**.

Only one of them has an ordering constraint in either direction, and it points *this* way: the
service's `TRANSLATION_REGIONAL_LOCALES` flag must not be enabled until **C2** has shipped and
consuming apps have upgraded.

---

## Status

| # | Milestone | Status | Commit |
|---|---|---|---|
| 1 | Lang-file imports write the base template only — delete the per-tenant fan-out | ✅ **Done** | `4b6269a` |
| 2 | Key client-side caches on `resolved_locale` | ⬜ Not started | — |
| 3 | Regional tags in config, middleware and the lang-file fallback | ⬜ Not started | — |
| 4 | Reconcile the two `flattenTranslations` copies | ⬜ Not started | — |
| 5 | *(cross-repo)* `POST /api/v1/translation` cannot update | ⬜ Not started, **service-side** | — |

---

## C1 — imports write the base template only (done, `4b6269a`)

Both import jobs pushed globally and then fanned out, dispatching a per-tenant job for **every row in
the consuming app's `tenants` table** that re-pushed byte-identical content with `tenant_id` set. That
is the client-side instance of the physical-copy model the service replaced with inheritance.

Under inheritance those copies are worse than wasteful. A tenant with no override for a key already
reads the base template, so the copy changes nothing while it exists — and once the tenant moves to a
regional variant, the resolution chain has **no `tenant + <bare language>` step**, so the row becomes
*unreachable* rather than merely duplicated. The service's backfill classifies exactly these rows as
redundant and deletes them; continuing to create them would refill what it just cleared.

**Lang files are the template.** Tenant overrides are authored in the service's admin screens, never
pushed from a consuming app's disk.

Deleted, all of it dead once the fan-out went:

- the fan-out block in `ImportTranslationsJob` and `ImportNamespacedTranslationsJob`
- `ImportTenantTranslationsJob` and `ImportTenantNamespacedTranslationsJob` — their only dispatch sites
- `TenantResolver::getAllTenantIds()` — its only callers, plus the `DB`/`Log` imports it left behind
- `--only-global` and the `onlyGlobal` constructor param from both commands and jobs

`--only-global` was **removed rather than made a no-op**: writing the base template is the only
behaviour now, so it had nothing left to select, and a silently inert flag reads as though the other
mode still exists. No documentation referenced it.

**No ordering dependency on the service.** Writing the base template only is already correct against
today's data, since existing tenants read those bare-locale global rows directly.

### First tests in this package

`php vendor/phpunit/phpunit/phpunit` previously reported *"No tests executed!"* — there was a
`TestCase` and no tests. There are now five, pinning the shape rather than the implementation: exactly
one push, always at `tenant_id = null`, nothing further dispatched, both per-tenant classes gone, and
neither command still accepting the flag. Verified by mutation — restoring a fan-out fails the first.

`phpunit.xml` also moved `include`/`exclude` from `<coverage>` to `<source>`. Pre-existing deprecation,
invisible until now because the suite had no tests to run.

---

## C2 — key caches on `resolved_locale` (not started)

**This is the one that gates the service's feature flag.**

`getManifestCacheKey()` (`src/Services/TranslationClient.php:397`), `getBundleCacheKey()` (`:407`) and
the `"locale:{$locale}"` cache tags (`:46`, `:94`, `:121`, `:177`) all key on the **requested** locale.
§4.3 added `resolved_locale` to both API responses precisely so `?locale=ar` and `?locale=ar-AE` stop
colliding while holding different content — this package does not read the field at all.

There is a chicken-and-egg to resolve: the resolved tag comes *from* the manifest, but the manifest is
itself cached. The cleanest split is **manifest keyed on the requested tag** — it is small and carries
`resolved_locale` — and **bundles keyed on the resolved tag**, which removes the duplication where it
is actually expensive.

`getDefaultManifest()` (`:418`) must also echo `requested_locale`/`resolved_locale`, or callers that
come to depend on the field break exactly when the API is unreachable.

> **Good news, verified:** `fetchBundle()` compares versions with `===` (`:98`), not `>`. So this client
> is **not** vulnerable to the version-goes-backwards trap the service's M3 note warns about — it
> refetches rather than going permanently stale.

The service's `LOCALE_TEMPLATES_PROGRESS.md` states the flag may only be enabled once consuming clients
read `resolved_locale`. C2 is that precondition, and shipping the package is not enough — consuming
apps have to upgrade too.

---

## C3 — regional tags in config, middleware and file fallback (not started)

Three items, one of which is not in the design doc.

**Wrong config key.** `SetLocaleFromRequest::getAvailableLocales()` (`src/Middleware/SetLocaleFromRequest.php:71`)
reads `config('app.available_locales')` — a *different* key from this package's own
`config/translation-client.php:132`. Pre-existing, and a wrong key silently rejects every regional tag
and falls through to the app default.

**Bare tags in config.** `available_locales` is `['ar', 'en']`. It drives which locales
`translations:sync` warms. Bare tags keep working through truncation fallback, but produce exactly the
cache-key collision C2 is about. Casing also needs normalising, so `ar_EG` and `ar-EG` are one tag.

**The lang-file fallback breaks on regional tags — not in the design doc.**
`ApiTranslationLoader::loadFromFiles()` (`src/Services/ApiTranslationLoader.php:82`) builds
`"{$path}/{$locale}/{$group}.php"` from the raw tag. Set the app locale to `ar-SA` and it looks for
`lang/ar-SA/messages.php`, which no consuming app has — so the local file layer that
`array_replace_recursive` merges under the API result (`:54`) silently returns nothing for **every**
regional locale. It must truncate the tag to its language.

---

## C4 — reconcile the two `flattenTranslations` copies (not started)

> ⚠️ **§6.8 is wrong about this one.** It says the two copies "are byte-for-byte identical and should be
> deduplicated rather than patched independently", and that *both* skip empty values. Neither is true.

`TranslationClient::flattenTranslations()` (`:321`) and
`TranslationProcessingTrait::flattenTranslations()` (`src/Jobs/TranslationProcessingTrait.php:122`)
diverge behaviourally:

| | `TranslationClient` | `TranslationProcessingTrait` |
|---|---|---|
| empty value (`null` / `''` / `[]`) | **pushed** | **skipped** via `continue` |
| group prefix | `$this->prefixGroup($group)` | inline `config(...)` lookup |
| client | `$this->client` | `config('translation-client.client', 'backend')` |
| signature | `private` | `protected` |

So the two import commands already behave differently, and the difference is not cosmetic. The service's
`StoreTranslationsRequest` has `'translations.*.value' => 'required'`, and Laravel's `required` rejects
an empty string (verified). Therefore:

- `translations:import` — a **single** empty string anywhere in a lang file makes the whole
  `POST /api/v1/translation` batch fail validation with 422, and the job throws.
- `translations:import-namespaced` — drops those keys client-side and succeeds, silently.

Deduplicating is a **behaviour reconciliation, not a copy-paste removal**. S5 confirmed on the service
side that an override always carries a value, which argues for skipping — but skipping makes the import
quietly lossy, so it should report what it dropped rather than swallowing it.

---

## C5 — `POST /api/v1/translation` cannot update (not started, service-side)

Every push path in this package — `pushTranslations()`, `pushTranslation()`, and both import commands —
goes through that endpoint. The service's `TranslationWriteApiController` uses `firstOrCreate()` plus an
additive `mergeMissingKeys()`, so it is **insert-only while reporting `updated: N`**. Reproduced
service-side against a real database:

| Request sequence | API response | Actually stored |
|---|---|---|
| `POST value: "FIRST"` then `POST value: "SECOND"` | `updated: 1` | `"FIRST"` |

For this package that means **re-importing edited lang files silently does not update anything**. The
service scoped it out on the theory that non-clobbering imports may be intended; from the client's side
that makes C1's "imports write the base template" story only half-usable. Needs a decision, then either
a fix to `updateOrCreate` or explicit documentation that the endpoint is insert-only.

---

## Environment notes

Unlike the service, this package **runs on the host** — it requires PHP `^8.1|^8.2|^8.3` and the host
has 8.2.30. No Docker needed.

```bash
php vendor/phpunit/phpunit/phpunit                      # whole suite
php vendor/phpunit/phpunit/phpunit --filter <TestName>  # one class
php -l src/<path>                                       # syntax check
```

Tests use `orchestra/testbench`; `tests/TestCase.php` registers the service provider and sets
`service_url`, `tenant_id` and `preload`. There is no `composer test` script.

> The service repo is the opposite: it requires PHP `^8.4`, the host is 8.2, so everything there runs
> through `docker exec translation-app php /var/www/…`.

---

## Pre-existing defects found but not fixed

Neither is caused by this work.

1. **`TENANT_CONFIG.md` documents behaviour that does not exist.** It describes
   `TenantResolver::resolve()` as checking a `TRANSLATION_TENANT_ID` config value, then querying
   `SELECT id FROM tenants ORDER BY created_at LIMIT 1`, and caching the result for an hour. The code
   does none of that — it reads the authenticated user's `tenant_id`, then falls back to
   `Ouredu\MultiTenant\Tenancy\TenantContext`. Stale documentation is worse than none here, because it
   describes a *tenant selection* strategy someone may be relying on.

2. **`config/translation-client.php` has its own `available_locales`** that nothing but
   `translations:sync` reads, while the middleware reads `config('app.available_locales')`. Two keys,
   one concept — see C3.
