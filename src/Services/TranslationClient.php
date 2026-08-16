<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TranslationClient
{
    private string $baseUrl;
    private ?int $tenantId;
    private string $client;
    private ?string $appNamePrefix;
    private int $manifestTtl;
    private int $bundleTtl;
    private int $httpTimeout;
    private bool $fallbackOnError;
    private ?string $cacheStore;
    private bool $loggingEnabled;

    /** @var string[] keys dropped by flattenTranslations for having no value */
    private array $skippedKeys = [];

    public function __construct()
    {
        $this->baseUrl = rtrim(config('translation-client.service_url'), '/');
        $this->client = config('translation-client.client', 'backend');
        $this->appNamePrefix = config('translation-client.app_name_prefix');
        $this->manifestTtl = config('translation-client.manifest_ttl', 300);
        $this->bundleTtl = config('translation-client.bundle_ttl', 3600);
        $this->httpTimeout = config('translation-client.http_timeout', 10);
        $this->fallbackOnError = config('translation-client.fallback_on_error', true);
        $this->cacheStore = config('translation-client.cache_store');
        $this->loggingEnabled = config('translation-client.logging.enabled', false);
    }

    /**
     * Check if translations need updating
     *
     * Stays keyed on the **requested** locale: this is the lookup that tells us
     * which locale the service actually resolved to, so it cannot itself be
     * keyed on the answer. The payload is small, so holding one per requested
     * tag is cheap — bundles are where the duplication mattered, and those key
     * on the resolved tag.
     */
    public function checkVersion(string $locale, ?string $client = null): array
    {
        $client = $client ?? $this->client;
        $cacheKey = $this->getManifestCacheKey($locale, $client);

        return $this->cache()
            ->tags(['translations', "locale:{$locale}"])
            ->remember($cacheKey, $this->manifestTtl, function () use ($locale, $client) {
                try {
                    $this->log('info', "Fetching manifest for locale: {$locale}, client: {$client}");

                    $response = Http::timeout($this->httpTimeout)
                        ->get("{$this->baseUrl}/api/v1/translation/manifest", [
                            'tenant' => $this->getTenantId(),
                            'locale' => $locale,
                            'client' => $client,
                        ]);

                    if ($response->successful()) {
                        $manifest = $response->json();
                        $this->log('info', "Manifest fetched successfully", ['version' => $manifest['version'] ?? null]);
                        return $manifest;
                    }

                    $this->log('error', 'Translation manifest fetch failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return $this->getDefaultManifest($locale, $client);
                } catch (\Exception $e) {
                    $this->log('error', 'Translation manifest error: ' . $e->getMessage());
                    return $this->getDefaultManifest($locale, $client);
                }
            });
    }

    /**
     * Fetch translation bundle
     */
    public function fetchBundle(
        string $locale,
        ?array $groups = null,
        ?string $client = null,
        string $format = 'flat'
    ): array {
        $client = $client ?? $this->client;

        // Apply app name prefix to groups
        $prefixedGroups = $groups ? array_map([$this, 'prefixGroup'], $groups) : null;

        // The manifest is read first, and unconditionally, because it carries
        // the resolved locale that the bundle cache key is built from. It is
        // itself cached for manifest_ttl, so this is a memory read on the warm
        // path rather than an extra request.
        $manifest = $this->checkVersion($locale, $client);
        $resolvedLocale = $this->resolvedLocale($manifest, $locale);

        $cacheKey = $this->getBundleCacheKey($resolvedLocale, $prefixedGroups, $client, $format);
        $tags = $this->cacheTags($resolvedLocale);

        // Check if we have a cached version
        $cached = $this->cache()->tags($tags)->get($cacheKey);
        if ($cached) {
            // Verify version is still current
            if (isset($cached['version']) && $cached['version'] === $manifest['version']) {
                $this->log('debug', "Using cached bundle for locale: {$resolvedLocale}");
                return $cached['data'];
            }
        }

        // Fetch new bundle
        try {
            $this->log('info', "Fetching bundle for locale: {$locale}, groups: " . json_encode($prefixedGroups));

            $response = Http::timeout($this->httpTimeout)
                ->get("{$this->baseUrl}/api/v1/translation", [
                    'tenant' => $this->getTenantId(),
                    'locale' => $locale,
                    'groups' => $prefixedGroups ? implode(',', $prefixedGroups) : null,
                    'client' => $client,
                    'format' => $format,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                // The bundle response carries resolved_locale too. Prefer it over
                // the manifest's: if the two ever disagree, the bundle is the one
                // describing the bytes being cached.
                $bundleLocale = $this->resolvedLocale($data, $resolvedLocale);

                if ($bundleLocale !== $resolvedLocale) {
                    $cacheKey = $this->getBundleCacheKey($bundleLocale, $prefixedGroups, $client, $format);
                    $tags = $this->cacheTags($bundleLocale);
                }

                // Cache the bundle
                $this->cache()->tags($tags)->put($cacheKey, $data, $this->bundleTtl);

                $this->log('info', "Bundle fetched successfully", [
                    'count' => $data['count'] ?? 0,
                    'version' => $data['version'] ?? null,
                ]);

                return $data['data'] ?? [];
            }

            $this->log('error', 'Translation bundle fetch failed', [
                'status' => $response->status(),
                'locale' => $locale,
            ]);

            // Return stale cache if available and fallback is enabled
            if ($this->fallbackOnError && $cached) {
                $this->log('warning', 'Using stale cache due to API failure');
                return $cached['data'] ?? [];
            }

            return [];
        } catch (\Exception $e) {
            $this->log('error', 'Translation bundle error: ' . $e->getMessage());

            // Return stale cache if available and fallback is enabled
            if ($this->fallbackOnError && $cached) {
                $this->log('warning', 'Using stale cache due to exception');
                return $cached['data'] ?? [];
            }

            return [];
        }
    }

    /**
     * Load translations for Laravel's translator
     */
    public function loadTranslations(string $locale): array
    {
        return $this->fetchBundle(
            locale: $locale,
            groups: null, // All groups
            client: $this->client,
            format: 'flat'
        );
    }

    /**
     * Clear all translation caches
     *
     * Callers pass the tag they asked for, not the one negotiation picked, and
     * bundles are tagged with the resolved locale — so `clearCache('ar')` has to
     * flush `locale:ar-SA` as well or it would evict the manifest and leave the
     * bundle it describes behind.
     *
     * The resolved tag is read from the manifest cache without refetching. If
     * the manifest has already expired there is nothing left to map through, and
     * only the requested tag is flushed; the bundle still self-invalidates on
     * its next version comparison, so the cost is a missed force-refresh rather
     * than stale content.
     */
    public function clearCache(?string $locale = null): void
    {
        if (! $locale) {
            $this->log('info', "Clearing all translation caches");
            $this->cache()->tags(['translations'])->flush();

            return;
        }

        $this->log('info', "Clearing cache for locale: {$locale}");

        foreach ($this->cachedLocaleTags($locale) as $tag) {
            $this->cache()->tags([$tag])->flush();
        }

        $this->cache()->tags(['translations', "locale:{$locale}"])->flush();
    }

    /**
     * Every `locale:` tag a requested locale may have content filed under.
     *
     * @return string[]
     */
    private function cachedLocaleTags(string $locale): array
    {
        $tags = ["locale:{$locale}"];

        foreach ($this->knownClients() as $client) {
            $manifest = $this->cache()
                ->tags(['translations', "locale:{$locale}"])
                ->get($this->getManifestCacheKey($locale, $client));

            if (! is_array($manifest)) {
                continue;
            }

            $resolved = $this->resolvedLocale($manifest, $locale);

            if (! in_array("locale:{$resolved}", $tags, true)) {
                $tags[] = "locale:{$resolved}";
            }
        }

        return $tags;
    }

    /**
     * Clients a manifest may have been cached under: this app's configured one,
     * plus the shared layer.
     *
     * @return array<int, string>
     */
    private function knownClients(): array
    {
        return array_values(array_unique([$this->client, 'backend', 'frontend', 'mobile']));
    }

    /**
     * Push translations to the service
     *
     * @param array $translations Array of translations to create/update
     * @return array Result with created and updated counts
     *
     * Example:
     * $client->pushTranslations([
     *     [
     *         'locale' => 'ar',
     *         'group' => 'messages',
     *         'key' => 'welcome',
     *         'value' => 'مرحبا',
     *         'client' => 'backend',
     *         'is_active' => true,
     *     ]
     * ]);
     */
    public function pushTranslations(array $translations): array
    {
        try {
            $this->log('info', "Pushing " . count($translations) . " translations to service");

            $response = Http::timeout($this->httpTimeout)
                ->post("{$this->baseUrl}/api/v1/translation", [
                    'translations' => $translations,
                ]);

            if ($response->successful()) {
                $result = $response->json();
                $this->log('info', "Translations pushed successfully", [
                    'created' => $result['created'] ?? 0,
                    'updated' => $result['updated'] ?? 0,
                ]);
                return $result;
            }

            $this->log('error', 'Failed to push translations', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \Exception("Failed to push translations: " . $response->body());
        } catch (\Exception $e) {
            $this->log('error', 'Translation push error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Push a single translation
     *
     * @param string $locale
     * @param string $group
     * @param string $key
     * @param string $value
     * @param string|null $client
     * @param bool $isActive
     * @return array
     */
    public function pushTranslation(
        string $locale,
        string $group,
        string $key,
        string $value,
        ?string $client = null,
        bool $isActive = true
    ): array {
        return $this->pushTranslations([[
            'tenant_id' => $this->getTenantId(),
            'locale' => $locale,
            'group' => $group,
            'key' => $key,
            'value' => $value,
            'client' => $client ?? $this->client,
            'is_active' => $isActive,
        ]]);
    }

    /**
     * Read and flatten translations from Laravel lang files, without pushing them.
     * Lets callers parse once and push the same array for multiple tenants.
     *
     * @param string $locale
     * @param string $langPath Path to Laravel lang directory
     * @return array
     */
    public function buildTranslationsFromFiles(string $locale, string $langPath): array
    {
        $translations = [];
        $files = glob("{$langPath}/{$locale}/*.php");

        foreach ($files as $file) {
            $group = basename($file, '.php');
            $data = include $file;

            if (!is_array($data)) {
                continue;
            }

            $translations = array_merge(
                $translations,
                $this->flattenTranslations($data, $locale, $group)
            );
        }

        return $translations;
    }

    /**
     * Push an already-built translations array for a specific tenant,
     * overwriting tenant_id on every row. Avoids re-reading files from
     * disk when pushing the same translations to multiple tenants.
     *
     * @param array $translations
     * @param int|null $tenantId
     * @return array
     */
    public function pushTranslationsForTenant(array $translations, ?int $tenantId): array
    {
        if (empty($translations)) {
            return ['created' => 0, 'updated' => 0, 'total' => 0];
        }

        $translations = array_map(static function (array $translation) use ($tenantId) {
            $translation['tenant_id'] = $tenantId;
            return $translation;
        }, $translations);

        return $this->pushTranslations($translations);
    }

    /**
     * Flatten nested translation array to flat structure
     * Preserves arrays as JSON values (matching API behavior)
     *
     * The single implementation. `TranslationProcessingTrait` used to carry a
     * near-copy of this that diverged in one important way: it skipped empty
     * values and this did not. The service's write API declares
     * `translations.*.value` as `required`, and Laravel's `required` rejects
     * null, `[]`, `''` **and** whitespace-only strings — so a single blank lang
     * value made `translations:import` fail its whole batch with a 422 while
     * `translations:import-namespaced` quietly dropped the key and succeeded.
     *
     * Skipping is the correct half of that pair: an override always carries a
     * value, so a blank is nothing the service can store. Dropped keys are
     * recorded rather than swallowed — see {@see takeSkippedKeys()}.
     */
    public function flattenTranslations(array $data, string $locale, string $group, string $prefix = ''): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            // Skip numeric keys (invalid)
            if (is_numeric($key)) {
                continue;
            }

            // Skip empty keys (invalid)
            if (empty($key)) {
                continue;
            }

            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;

            // Check if value is an associative array (has string keys)
            $isAssociativeArray = is_array($value) && array_keys($value) !== range(0, count($value) - 1);

            if ($isAssociativeArray && !$this->isTranslatableArray($value)) {
                // Recursively flatten associative arrays with string keys
                $result = array_merge(
                    $result,
                    $this->flattenTranslations($value, $locale, $group, $fullKey)
                );

                continue;
            }

            if ($this->isEmptyValue($value)) {
                $this->skippedKeys[] = "{$locale} {$group}.{$fullKey}";

                continue;
            }

            // Arrays (indexed or translatable) are preserved; the API JSON
            // encodes them.
            $result[] = [
                'locale' => $locale,
                'group' => $this->prefixGroup($group), // Apply app name prefix
                'key' => $fullKey,
                'value' => $value,
                'client' => $this->client,
                'is_active' => true,
            ];
        }

        return $result;
    }

    /**
     * Would the service refuse this value?
     *
     * Mirrors Laravel's `required`, which the write API applies to every value:
     * null, an empty array, and any string that trims to nothing.
     */
    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_array($value)) {
            return $value === [];
        }

        return is_string($value) && trim($value) === '';
    }

    /**
     * Keys dropped for having no value, since the last time this was called.
     *
     * Reading clears the list, so a caller reports each key once.
     *
     * @return string[]
     */
    public function takeSkippedKeys(): array
    {
        $skipped = $this->skippedKeys;
        $this->skippedKeys = [];

        return $skipped;
    }

    /**
     * Log what an import discarded, and clear the record.
     *
     * Dropping blank values is what lets an import containing one empty lang
     * string succeed at all — the service refuses them. Dropping them
     * *invisibly* is how a key goes missing and nobody finds out, so this logs
     * unconditionally rather than through this package's opt-in channel.
     */
    public function reportSkippedKeys(): void
    {
        $skipped = $this->takeSkippedKeys();

        if ($skipped === []) {
            return;
        }

        Log::warning(
            '[TranslationClient] Skipped ' . count($skipped) . ' translation key(s) with an empty value; '
            . 'the service rejects blanks, so they were not pushed.',
            ['total' => count($skipped), 'keys' => array_slice($skipped, 0, 50)]
        );
    }

    /**
     * Check if array should be treated as a translatable value (not flattened)
     * Examples: validation messages, pluralization rules
     */
    private function isTranslatableArray(array $value): bool
    {
        // If all values are strings, it's likely a translatable array
        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }
        return true;
    }


    /**
     * Get cache instance
     */
    private function cache(): \Illuminate\Contracts\Cache\Repository
    {
        return $this->cacheStore
            ? Cache::store($this->cacheStore)
            : Cache::store();
    }

    /**
     * Generate manifest cache key
     */
    private function getManifestCacheKey(string $locale, string $client): string
    {
        $tenant = $this->getTenantId() ?? 'global';
        $prefix = $this->appNamePrefix ? "{$this->appNamePrefix}:" : '';
        return "{$prefix}translation:manifest:{$tenant}:{$locale}:{$client}";
    }

    /**
     * Generate bundle cache key
     *
     * `$locale` here is the **resolved** tag, not the requested one. Any number
     * of requested tags can negotiate to the same locale — `ar`, `ar-AE` and
     * `ar-EG` all resolve to `ar-SA` for a tenant assigned it — and keying on
     * the request would store one identical copy per spelling, refetching each.
     */
    private function getBundleCacheKey(string $locale, ?array $groups, string $client, string $format): string
    {
        $tenant = $this->getTenantId() ?? 'global';
        $groupsStr = $groups ? implode('-', $groups) : 'all';
        $prefix = $this->appNamePrefix ? "{$this->appNamePrefix}:" : '';
        return "{$prefix}translation:bundle:{$tenant}:{$locale}:{$groupsStr}:{$client}:{$format}";
    }

    /**
     * The locale the service actually served, per a manifest or bundle response.
     *
     * Falls back to what was asked for when the field is absent, which covers
     * both an older service build and the offline default manifest.
     */
    private function resolvedLocale(array $response, string $requested): string
    {
        $resolved = $response['resolved_locale'] ?? null;

        return is_string($resolved) && $resolved !== '' ? $resolved : $requested;
    }

    /**
     * Cache tags for a bundle, which are always the *resolved* locale's.
     *
     * The tag set must not vary by requested locale. Laravel namespaces a
     * tagged entry by its whole tag set, so tagging `['translations',
     * 'locale:ar-SA', 'locale:ar']` and `['translations', 'locale:ar-SA',
     * 'locale:ar-AE']` produces two namespaces and two copies — reintroducing
     * exactly the duplication this milestone removes. Reaching a bundle by the
     * tag a caller asked for is {@see clearCache()}'s job instead.
     *
     * @return string[]
     */
    private function cacheTags(string $resolved): array
    {
        return ['translations', "locale:{$resolved}"];
    }

    /**
     * Get default manifest when API is unavailable
     *
     * Echoes both locale fields rather than omitting them: callers that read
     * `resolved_locale` must not start getting null exactly when the service is
     * unreachable, which is the moment the fallback exists for.
     */
    private function getDefaultManifest(string $locale, string $client): array
    {
        return [
            'tenant' => $this->getTenantId(),
            'locale' => $locale,
            'requested_locale' => $locale,
            'resolved_locale' => $locale,
            'client' => $client,
            'version' => 1,
            'etag' => 'W/"default-1"',
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Apply app name prefix to group if configured
     */
    private function prefixGroup(string $group): string
    {
        if (!$this->appNamePrefix) {
            return $group;
        }

        return $this->appNamePrefix . ':' . $group;
    }

    /**
     * Log message if logging is enabled
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (!$this->loggingEnabled) {
            return;
        }

        $channel = config('translation-client.logging.channel', 'stack');
        Log::channel($channel)->$level("[TranslationClient] {$message}", $context);
    }

    /**
     * Tenant ID Getter
     * Use TenantResolver to get tenant ID with prioritized fallback
     * @return int|null
     */
    private function getTenantId(): ?int
    {
        if(!isset($this->tenantId)){
            $this->tenantId = \OurEdu\TranslationClient\Helpers\TenantResolver::resolve();
        }
        return $this->tenantId;
    }
}
