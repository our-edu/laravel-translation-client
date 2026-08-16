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
| 2 | Key client-side caches on `resolved_locale` | ✅ **Done** | `e42b571` |
| 3 | Regional tags in config, middleware and the lang-file fallback | ✅ **Done** | `2183edd` |
| 4 | Reconcile the two `flattenTranslations` copies | ✅ **Done** | `b747d4a` |
| 5 | *(cross-repo)* `POST /api/v1/translation` cannot update | ✅ **Closed — not a defect**, insert-only is intended | — |

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

## C2 — key caches on `resolved_locale` (done, `e42b571`)

**This is the one that gates the service's feature flag.**

`getBundleCacheKey()` and the `"locale:…"` cache tags keyed on whatever tag the caller asked for. §4.3
added `resolved_locale` to both API responses so `?locale=ar` and `?locale=ar-AE` stop colliding while
holding different content; this package never read the field, so it inherited the mirror image of that
problem one hop upstream — several requested tags negotiating to the *same* locale each stored an
identical copy and refetched independently.

Bundles now key and tag on the **resolved** locale. The manifest deliberately stays on the **requested**
tag: it is the lookup that reveals the resolved one, so it cannot be keyed on its own answer. It is
also small, which is why the duplication only ever mattered for bundles.

`fetchBundle()` therefore reads the manifest unconditionally rather than only when a cached bundle
exists, since the key depends on it. The manifest is cached for `manifest_ttl`, so the warm path is a
memory read.

### Two things that were not obvious

**Tags cannot include the requested locale.** Laravel namespaces a tagged entry by its *whole tag set*,
so `['translations','locale:ar-SA','locale:ar']` and `['translations','locale:ar-SA','locale:ar-AE']`
are two namespaces holding two copies — reintroducing exactly the duplication being removed. The tag
set is the resolved locale's alone, and `clearCache()` maps the requested tag through the cached
manifest instead. Where the manifest has already expired only the requested tag is flushed; the bundle
still self-invalidates on its next version comparison, so the cost is a missed force-refresh rather
than stale content.

**`Http::fake()` merges stub callbacks rather than replacing them.** A test that re-fakes mid-way
silently asserts against the first stub. The tests use one request-driven fake instead — worth knowing
before adding to them.

### Compatibility

A service that sends no `resolved_locale` — an older build, or one with `TRANSLATION_REGIONAL_LOCALES`
off — still works and falls back to the requested tag. So this ships safely ahead of the flag.

`getDefaultManifest()` now echoes `requested_locale`/`resolved_locale`, so callers depending on the
field do not get null exactly when the API is unreachable.

> **Verified while here:** `fetchBundle()` compares versions with `===`, not `>`. This client is **not**
> vulnerable to the version-goes-backwards trap the service's M3 note warns about — it refetches rather
> than going permanently stale.

### The flag is still not unblocked by this alone

The service's handover states `TRANSLATION_REGIONAL_LOCALES` may only be enabled once consuming clients
read `resolved_locale`. That is now true of the package — but **consuming apps have to upgrade to a
release containing this commit** before the flag can be flipped. Shipping the package is necessary, not
sufficient.

---

## C3 — regional tags in config, middleware and file fallback (done, `2183edd`)

Three fixes, one of which is not in the design doc.

### The middleware read a config key that does not exist

`SetLocaleFromRequest::getAvailableLocales()` read `config('app.available_locales')`. **That is not a
standard Laravel key.** Unless a consuming app had defined one itself, the default collapsed to
`[config('app.locale')]` and every locale but the app default was silently rejected — so the middleware
had effectively never worked for a second language, let alone a regional variant of one. It was
described in §6.8 as a tidy-up; it was a live bug.

It now reads this package's own key and merges `app.available_locales` in, so apps that *did* define
one keep working.

Matching gained two behaviours:

- **Casing is normalised** — `AR_sa`, `ar-sa` and `ar-SA` are one tag.
- **Language-level fallback.** A request for `ar`, or for a variant this app does not serve such as
  `ar-EG`, is served the variant configured for Arabic. Without this, listing only regional tags in
  config would reject every bare `ar` — a regression, not a migration. It mirrors how the service
  negotiates: the request's language selects, configuration decides the variant.

### `available_locales` now lists regional tags

Its only reader is the middleware, which validates requested locales against it. Trim it to the
variants your tenants are actually assigned.

> Originally this also documented `translations:sync` as a consumer. That command has since been
> deleted as dead code — see [below](#translationssync-removed-2026-08-16).

### Not in §6.8: the lang-file layer was dead for regional locales

`ApiTranslationLoader::loadFromFiles()` built `"{$path}/{$locale}/{$group}.php"` straight from the
locale, so `ar-SA` looked for `lang/ar-SA/messages.php`. Apps ship `lang/ar/`, so the file layer
returned nothing for **every** regional locale.

That is not a missed optimisation. `load()` merges the API result *over* the file result, so the files
are what supply any key the service does not return — losing them silently dropped those keys. It now
tries the exact tag first (an app that does keep `lang/ar-SA/` gets it), then the language.

> This is also the mechanism behind the service-side S8 decision: because the API value wins over the
> local file, a service answering in the wrong language *overwrites* a correct local string rather than
> filling a gap. Same merge, two conclusions.

Thirteen tests. Verified by mutation: restoring the raw-tag path fails 2, restoring the wrong config
key fails 7.

---

## C4 — reconcile the two `flattenTranslations` copies (done, `b747d4a`)

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

Deduplicating was therefore a **behaviour reconciliation, not a copy-paste removal**.

### What landed

The trait's copy is gone; both paths call `TranslationClient::flattenTranslations()`.
`readFromDirectory()` takes the client in order to, and `isTranslatableArray()` went with the copy that
used it.

**Skipping is the correct half.** S5 confirmed on the service side that an override always carries a
value, so a blank is nothing the service can store. The check now mirrors Laravel's `required`
*exactly* — null, `[]`, `''` **and whitespace-only strings** — which was verified against the running
service rather than assumed:

```
required rejects ''      : true      required rejects []   : true
required rejects ' '     : true      required rejects null : true
```

That last point matters: the trait compared `=== ''`, so `'   '` slipped through and would still have
422'd the batch. **The surviving copy was wrong too** — deduplicating onto it unchanged would have left
a subtler version of the same bug.

**Dropped keys are logged, not swallowed.** Losing a key because it was blank is correct; losing it
invisibly is how nobody finds out. `reportSkippedKeys()` logs a warning unconditionally rather than
through this package's opt-in `logging` channel, and both import jobs call it.

It lives on `TranslationClient` rather than in the trait because only one of the two jobs uses that
trait — putting a shared helper there would have repeated the very mistake being fixed.

Twelve tests. Verified by mutation: not skipping fails 5, comparing `=== ''` instead of trimming
fails 2.

> Worth knowing for later: the skip applies to a row's whole value, not to leaves inside one. An
> all-string array is a *translatable value* preserved as-is, so a blank leaf within one survives — and
> should, since a non-empty array passes `required`.

---

## C5 — `POST /api/v1/translation` cannot update (closed: by design)

Every push path in this package — `pushTranslations()`, `pushTranslation()`, and both import commands —
goes through that endpoint. The service's `TranslationWriteApiController` uses `firstOrCreate()` plus an
additive `mergeMissingKeys()`, so it is **insert-only while reporting `updated: N`**. Reproduced
service-side against a real database:

| Request sequence | API response | Actually stored |
|---|---|---|
| `POST value: "FIRST"` then `POST value: "SECOND"` | `updated: 1` | `"FIRST"` |

For this package that means **re-importing an edited lang file does not change what is stored**.

That is intended, and it is the answer to C5 rather than a problem to fix. **Only dashboard users
change a translation's value.** Pushes from a consuming app seed content; they must not clobber what
someone has since edited in the admin UI. The additive merge for nested values is the same intent —
new sub-keys arrive, present ones are left alone.

It was briefly "fixed" to a real upsert service-side and that change was rejected and reverted. The
service repo now pins the behaviour with `TranslationWriteApiIsInsertOnlyTest`, so anyone who spots the
apparent bug finds the intent stated.

### What this means for the import commands

`translations:import` and `translations:import-namespaced` are **seeding** tools. They fill in keys the
service does not have yet, and they are safe to re-run — a redeploy cannot undo an admin's edit. What
they cannot do is propagate a changed string from a lang file to a translation that already exists.

Hold this alongside C1's *"lang files are the base template"*: true of the first import, not of later
ones. After that first seed, the template is maintained in the dashboard.

> The response reports `updated: N` for rows it did not touch, so
> `TranslationClient::pushTranslations()` logs an `updated` count that overstates what happened. A
> misleading label rather than wrong behaviour, and left alone deliberately.

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

## Follow-up fixes (2026-08-16)

### `translations:sync` removed

Deleted as dead code. Translations are fetched lazily and a cached bundle carries
the version it was built from, compared against the manifest on every read, so an
edit in the service is picked up on the next request whether or not anything has
been "synced". `--force` only wrapped `clearCache()`, which
`translations:clear-cache` already exposes. Six markdown files referenced it and
now point at `translations:clear-cache` or say plainly that nothing needs
scheduling.

### The push log surfaces `unchanged` (`cb58a06`)

The service now reports `unchanged` alongside `created` and `updated`, and for
this package that is where most of a re-import lands — the endpoint is
insert-only, so a key it already holds is left alone. Logging only created and
updated made a routine re-import look like it had done nothing at all.

### Tenant documentation corrected (`54a220e`)

`TENANT_CONFIG.md` and `QUICKSTART_TENANT.md` described four tenant strategies.
**Three have never been implemented:** auto-detecting the first tenant with
`SELECT id FROM tenants ORDER BY created_at LIMIT 1` cached for an hour, pinning
one with `TRANSLATION_TENANT_ID`, and overriding per request with
`TenantResolver::setTenant()`. There is no such query, no caching, the config key
is read by nothing, and `setTenant()` writes that same unread key — so a
middleware built on it silently does nothing.

Stale docs are worse than none here, because they describe a tenant *selection*
mechanism a reader may believe they are relying on: an app following the old guide
would quietly serve the global template to everyone.

Both files now describe what `resolve()` does — the authenticated user's
`tenant_id`, then `TenantContext`, then `null` — and say that `null` is a valid
answer rather than a failure. They also correct a subtler error: the guide told
readers to add a `tenant_id()` *method*, but the resolver reads `$user->tenant_id`
as a property, so it has to be a column or an accessor.

**The functional gap is documented, not closed.** Making `setTenant()` work needs
`resolve()` to read the config, and where that read belongs is a product decision:
checked **first** means an explicit override wins, but a stale `TRANSLATION_TENANT_ID`
in `.env` silently hijacks a multi-tenant app; checked **last** is safe for pinning
a single-tenant app, but a per-request `setTenant()` would lose to the
authenticated user, which is not what calling it implies. Neither is obviously
right, so nothing was implemented.

### Release state — the flag is still blocked

`TRANSLATION_REGIONAL_LOCALES` may only be enabled once consuming apps read
`resolved_locale`. That is true of this package as of **C2** — but C2 is on
`regional-locale-templates` only. It is **not merged to `main`** and the latest
tag is **1.4.5**, which predates all of this work. **No consuming app can be on a
release containing it.**

The flag is currently `false` service-side. Before it can be enabled: merge this
branch, tag a release, publish, and upgrade the consuming apps.

---

## Pre-existing defects found but not fixed

Neither is caused by this work.

1. **`TENANT_CONFIG.md` documents behaviour that does not exist.** It describes
   `TenantResolver::resolve()` as checking a `TRANSLATION_TENANT_ID` config value, then querying
   `SELECT id FROM tenants ORDER BY created_at LIMIT 1`, and caching the result for an hour. The code
   does none of that — it reads the authenticated user's `tenant_id`, then falls back to
   `Ouredu\MultiTenant\Tenancy\TenantContext`. Stale documentation is worse than none here, because it
   describes a *tenant selection* strategy someone may be relying on.

2. ~~**`config/translation-client.php` has its own `available_locales`** that only the sync command
   reads, while the middleware reads `config('app.available_locales')`~~ — fixed as part of C3
   (`2183edd`). The middleware now reads the package key, with `app.available_locales` merged in for
   apps that had defined it, and it is the only reader left.

---

## `translations:sync` removed (2026-08-16)

`SyncTranslationsCommand` was deleted as dead code, along with its registration in
`TranslationServiceProvider`.

Nothing depended on it. Translations are fetched lazily on first use, and a cached bundle carries the
version it was built from, which is compared against the service's manifest on every read — so an edit
in the service is picked up on the next request whether or not anything has been "synced". The command
was a warm-up loop plus a `--force` that only wrapped `clearCache()`, which `translations:clear-cache`
already exposes.

Its removal reaches back into C3: `available_locales` was documented as having two readers with
different appetites — sync warming every entry, the middleware merely validating against it. Only the
middleware remains, so the "every entry is one more locale to warm" caveat is gone and the list can be
sized purely for what requests are allowed.

C1 and C2 are untouched by this. `clearCache()` — which C2 reworked so that clearing by a requested tag
reaches a bundle filed under the resolved one — is still reached by `translations:clear-cache`, so that
work stands on its own.

Six markdown files referenced the command in setup, deployment, Docker and troubleshooting recipes; all
now point at `translations:clear-cache` or say plainly that nothing needs scheduling. Two "verify by
syncing" steps became empty headings and were folded into the steps around them.

> `LOCALE_TEMPLATES_MIGRATION.md` §6.8 (in the service repo) still lists
> `src/Console/SyncTranslationsCommand.php` as a file to update. That row is now moot. The design doc
> is left as the historical artifact it is rather than rewritten.
