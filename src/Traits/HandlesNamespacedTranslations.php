<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Traits;

use OurEdu\TranslationClient\Helpers\TenantResolver;

trait HandlesNamespacedTranslations
{
    /**
     * Find all Lang directories matching the pattern
     */
    protected function findLangDirectories(string $basePath, string $pattern): array
    {
        $dirs = [];

        // Support both */Lang and */*/Lang patterns
        $patterns = is_array($pattern) ? $pattern : [$pattern];

        foreach ($patterns as $pat) {
            $fullPattern = $basePath . DIRECTORY_SEPARATOR . $pat;
            $found = glob($fullPattern, GLOB_ONLYDIR);
            if ($found) {
                $dirs = array_merge($dirs, $found);
            }
        }

        // Also try common patterns if nothing found
        if (empty($dirs)) {
            $commonPatterns = ['*/Lang', '*/*/Lang', '*/*/*/Lang'];
            foreach ($commonPatterns as $pat) {
                $found = glob($basePath . DIRECTORY_SEPARATOR . $pat, GLOB_ONLYDIR);
                if ($found) {
                    $dirs = array_merge($dirs, $found);
                }
            }
        }

        return array_unique($dirs);
    }

    /**
     * Get namespace from directory path
     */
    protected function getNamespaceFromPath(string $langDir, string $basePath): string
    {
        // Remove base path and Lang suffix
        $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $langDir);
        $relativePath = str_replace(DIRECTORY_SEPARATOR . 'Lang', '', $relativePath);

        // Convert path to namespace (e.g., "Translation/Views" -> "TranslationViews")
        $parts = explode(DIRECTORY_SEPARATOR, $relativePath);

        // Remove empty parts
        $parts = array_filter($parts);

        // Join with no separator for namespace
        return implode('', $parts);
    }

    /**
     * Get available locales from directory
     */
    protected function getLocalesFromDirectory(string $langDir): array
    {
        $locales = [];
        $dirs = glob($langDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $locales[] = basename($dir);
        }

        return $locales;
    }

    /**
     * Flatten nested translation array
     * Preserves arrays as JSON values (matching API behavior)
     */
    protected function flattenTranslations(
        array $data,
        string $locale,
        string $group,
        string $prefix = ''
    ): array {
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
                // Apply app name prefix to group
                $appPrefix = config('translation-client.app_name_prefix');
                $finalGroup = $appPrefix ? "{$appPrefix}:{$group}" : $group;

                // Preserve arrays (indexed or translatable) as values
                // API will JSON encode them automatically
                $result[] = [
                    'tenant_uuid' => TenantResolver::resolve(),
                    'locale' => $locale,
                    'group' => $finalGroup, // Apply app prefix here
                    'key' => $fullKey,
                    'value' => $value, // Preserve array, API handles JSON encoding
                    'client' => config('translation-client.client', 'backend'),
                    'is_active' => true,
                ];
            }
        }

        return $result;
    }

    /**
     * Check if array should be treated as a translatable value (not flattened)
     */
    protected function isTranslatableArray(array $value): bool
    {
        // If all values are strings, it's likely a translatable array
        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }
        return true;
    }
}
