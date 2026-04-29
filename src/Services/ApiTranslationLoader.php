<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Services;

use Illuminate\Contracts\Translation\Loader as LoaderContract;
use Illuminate\Filesystem\Filesystem;

class ApiTranslationLoader implements LoaderContract
{
    protected TranslationClient $client;
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


        $translationFile = $this->loadFromFiles($locale, $group, $namespace) ?? [];
        return array_replace_recursive($translationFile, $result);
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

    private function loadFromFiles($locale, $group, $namespace = null): array
    {
        $path = $this->path;

        if ($namespace && $namespace !== '*') {
            $path = $this->namespaces[$namespace] ?? $path;
            $filePath = "{$path}/{$locale}/{$group}.php";
        } else {
            $filePath = "{$path}/{$locale}/{$group}.php";
        }

        if ($this->files->exists($filePath)) {
            return require $filePath;
        }

        return [];
    }
}
