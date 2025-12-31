<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Services;

use Illuminate\Contracts\Translation\Loader as LoaderContract;
use Illuminate\Filesystem\Filesystem;

class ApiTranslationLoader implements LoaderContract
{
    protected TranslationClient $client;
    protected array $loaded = [];
    protected bool $preloaded = false;
    protected ?string $appNamePrefix;
    protected array $namespaces = [];
    protected Filesystem $files;
    protected string $path;

    public function __construct(Filesystem $files, string $path, TranslationClient $client)
    {
        $this->files = $files;
        $this->path = $path;
        $this->client = $client;
        $this->appNamePrefix = config('translation-client.app_name_prefix');
    }

    /**
     * Load translations from API instead of files
     */
    public function load($locale, $group, $namespace = null): array
    {
        // Build cache key with app prefix
        $prefix = $this->appNamePrefix ? "{$this->appNamePrefix}:" : '';
        $cacheKey = $namespace && $namespace !== '*' 
            ? "{$prefix}{$locale}.{$namespace}::{$group}" 
            : "{$prefix}{$locale}.{$group}";
            
        if (isset($this->loaded[$cacheKey])) {
            return $this->loaded[$cacheKey];
        }

        // If locale was preloaded, return from memory or empty array
        if ($this->preloaded && !isset($this->loaded[$cacheKey])) {
            return [];
        }

        // For namespaced translations, use the namespace::group format
        $apiGroup = $namespace && $namespace !== '*' 
            ? "{$namespace}::{$group}" 
            : $group;

        // Fetch from API
        // TranslationClient will apply app prefix to groups before sending to API
        $translations = $this->client->fetchBundle(
            locale: $locale,
            groups: [$apiGroup],
            client: null, // Use default from config
            format: 'nested'
        );

        // Extract the group from response
        // The API response key will have the app prefix applied by TranslationClient
        $prefixedApiGroup = $this->appNamePrefix ? "{$this->appNamePrefix}:{$apiGroup}" : $apiGroup;
        $result = $translations[$prefixedApiGroup] ?? [];

        // Cache in memory
        $this->loaded[$cacheKey] = $result;

        return $result;
    }

    /**
     * Preload all translations for a locale
     * This is called on application boot for better performance
     */
    public function preloadLocale(string $locale): void
    {
        $allTranslations = $this->client->loadTranslations($locale);

        $prefix = $this->appNamePrefix ? "{$this->appNamePrefix}:" : '';

        // Convert flat keys to nested structure and store in memory
        foreach ($allTranslations as $key => $value) {
            // Split on first dot only
            $parts = explode('.', $key, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$group, $item] = $parts;
            $cacheKey = "{$prefix}{$locale}.{$group}";

            if (!isset($this->loaded[$cacheKey])) {
                $this->loaded[$cacheKey] = [];
            }

            $this->setNestedValue($this->loaded[$cacheKey], $item, $value);
        }

        $this->preloaded = true;
    }

    /**
     * Add a new namespace to the loader
     */
    public function addNamespace($namespace, $hint): void
    {
        $this->namespaces[$namespace] = $hint;
    }

    /**
     * Add a new JSON path to the loader (required by LoaderContract)
     */
    public function addJsonPath($path): void
    {
        // Not needed for API loader, but required by interface
    }

    /**
     * Get registered namespaces
     */
    public function namespaces(): array
    {
        return $this->namespaces;
    }

    /**
     * Get all loaded translations
     */
    public function getLoaded(): array
    {
        return $this->loaded;
    }

    /**
     * Clear loaded translations from memory
     */
    public function clearLoaded(?string $locale = null): void
    {
        if ($locale) {
            $prefix = $this->appNamePrefix ? "{$this->appNamePrefix}:" : '';
            $pattern = "{$prefix}{$locale}.";
            
            // Clear specific locale
            foreach (array_keys($this->loaded) as $key) {
                if (str_starts_with($key, $pattern)) {
                    unset($this->loaded[$key]);
                }
            }
        } else {
            // Clear all
            $this->loaded = [];
        }

        $this->preloaded = false;
    }

    /**
     * Set nested value using dot notation
     */
    private function setNestedValue(array &$array, string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $current[$k] = $value;
            } else {
                if (!isset($current[$k]) || !is_array($current[$k])) {
                    $current[$k] = [];
                }
                $current = &$current[$k];
            }
        }
    }
}
