<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TranslationClient
{
    private string $baseUrl;
    private ?string $tenantUuid;
    private string $client;
    private ?string $appNamePrefix;
    private int $manifestTtl;
    private int $bundleTtl;
    private int $httpTimeout;
    private bool $fallbackOnError;
    private ?string $cacheStore;
    private bool $loggingEnabled;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('translation-client.service_url'), '/');
        
        // Use TenantResolver to get tenant UUID with fallback
        $this->tenantUuid = \OurEdu\TranslationClient\Helpers\TenantResolver::resolve();
//        dd($this->tenantUuid);
            
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
     */
    public function checkVersion(string $locale, ?string $client = null): array
    {
        $client = $client ?? $this->client;
        $cacheKey = $this->getManifestCacheKey($locale, $client);

        return $this->cache()->remember($cacheKey, $this->manifestTtl, function () use ($locale, $client) {
            try {
                $this->log('info', "Fetching manifest for locale: {$locale}, client: {$client}");

                $response = Http::timeout($this->httpTimeout)
                    ->get("{$this->baseUrl}/api/v1/translation/manifest", [
                        'tenant' => $this->tenantUuid,
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
        
        $cacheKey = $this->getBundleCacheKey($locale, $prefixedGroups, $client, $format);

        // Check if we have a cached version
        $cached = $this->cache()->get($cacheKey);
        if ($cached) {
            // Verify version is still current
            $manifest = $this->checkVersion($locale, $client);
            if (isset($cached['version']) && $cached['version'] === $manifest['version']) {
                $this->log('debug', "Using cached bundle for locale: {$locale}");
                return $cached['data'];
            }
        }

        // Fetch new bundle
        try {
            $this->log('info', "Fetching bundle for locale: {$locale}, groups: " . json_encode($prefixedGroups));

            $response = Http::timeout($this->httpTimeout)
                ->get("{$this->baseUrl}/api/v1/translation", [
                    'tenant' => $this->tenantUuid,
                    'locale' => $locale,
                    'groups' => $prefixedGroups ? implode(',', $prefixedGroups) : null,
                    'client' => $client,
                    'format' => $format,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                // Cache the bundle
                $this->cache()->put($cacheKey, $data, $this->bundleTtl);

                $this->log('info', "Bundle fetched successfully", [
                    'count' => $data['count'] ?? 0,
                    'version' => $data['version'] ?? null,
                ]);

                return $data['data'];
            }

            $this->log('error', 'Translation bundle fetch failed', [
                'status' => $response->status(),
                'locale' => $locale,
            ]);

            // Return stale cache if available and fallback is enabled
            if ($this->fallbackOnError && $cached) {
                $this->log('warning', 'Using stale cache due to API failure');
                return $cached['data'];
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
     */
    public function clearCache(?string $locale = null): void
    {
        $prefix = $this->appNamePrefix ? "{$this->appNamePrefix}:" : '';
        
        if ($locale) {
            // Clear specific locale
            $pattern = "{$prefix}translation:*:{$locale}:*";
            $this->log('info', "Clearing cache for locale: {$locale}");
        } else {
            // Clear all
            $pattern = "{$prefix}translation:*";
            $this->log('info', "Clearing all translation caches");
        }

        // Note: This is a simple implementation. For production, you might want
        // to use cache tags or a more sophisticated approach
        $this->cache()->flush();
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
            'tenant_uuid' => $this->tenantUuid,
            'locale' => $locale,
            'group' => $group,
            'key' => $key,
            'value' => $value,
            'client' => $client ?? $this->client,
            'is_active' => $isActive,
        ]]);
    }

    /**
     * Import translations from Laravel lang files
     * 
     * @param string $locale
     * @param string $langPath Path to Laravel lang directory
     * @return array
     */
    public function importFromFiles(string $locale, string $langPath): array
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

        if (empty($translations)) {
            $this->log('warning', "No translations found in {$langPath}/{$locale}");
            return ['created' => 0, 'updated' => 0, 'total' => 0];
        }

        return $this->pushTranslations($translations);
    }

    /**
     * Flatten nested translation array to flat structure
     * Preserves arrays as JSON values (matching API behavior)
     */
    private function flattenTranslations(array $data, string $locale, string $group, string $prefix = ''): array
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
            } else {
                // Preserve arrays (indexed or translatable) as JSON
                // This matches the API's behavior (lines 80-82 in TranslationWriteApiController)
                $finalValue = $value;
                if (is_array($value)) {
                    $finalValue = $value; // API will JSON encode it
                }
                dump($this->tenantUuid);
                $result[] = [
                    'tenant_uuid' => $this->tenantUuid,
                    'locale' => $locale,
                    'group' => $this->prefixGroup($group), // Apply app name prefix
                    'key' => $fullKey,
                    'value' => $finalValue,
                    'client' => $this->client,
                    'is_active' => true,
                ];
            }
        }

        return $result;
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
        $tenant = $this->tenantUuid ?? 'global';
        $prefix = $this->appNamePrefix ? "{$this->appNamePrefix}:" : '';
        return "{$prefix}translation:manifest:{$tenant}:{$locale}:{$client}";
    }

    /**
     * Generate bundle cache key
     */
    private function getBundleCacheKey(string $locale, ?array $groups, string $client, string $format): string
    {
        $tenant = $this->tenantUuid ?? 'global';
        $groupsStr = $groups ? implode('-', $groups) : 'all';
        $prefix = $this->appNamePrefix ? "{$this->appNamePrefix}:" : '';
        return "{$prefix}translation:bundle:{$tenant}:{$locale}:{$groupsStr}:{$client}:{$format}";
    }

    /**
     * Get default manifest when API is unavailable
     */
    private function getDefaultManifest(string $locale, string $client): array
    {
        return [
            'tenant' => $this->tenantUuid,
            'locale' => $locale,
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
}
