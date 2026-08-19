<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Jobs;

use OurEdu\TranslationClient\Services\TranslationClient;

/**
 * Shared helper methods for translation processing across jobs and commands.
 * Handles translation file reading, flattening, and namespace extraction.
 */
trait TranslationProcessingTrait
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
     * Read and flatten translations from a namespaced Lang directory
     */
    protected function readFromDirectory(
        TranslationClient $client,
        string $langDir,
        string $namespace,
        ?string $specificLocale = null
    ): array
    {
        $locales = $specificLocale ? [$specificLocale] : $this->getLocalesFromDirectory($langDir);

        $allTranslations = [];

        foreach ($locales as $locale) {
            $localeDir = $langDir . DIRECTORY_SEPARATOR . $locale;

            if (!is_dir($localeDir)) {
                continue;
            }

            $files = glob($localeDir . DIRECTORY_SEPARATOR . '*.php');

            foreach ($files as $file) {
                $group = basename($file, '.php');
                $data = include $file; // should be include() not include_once() as it's repeatable

                if (!is_array($data)) {
                    continue;
                }

                // Prefix group with namespace
                $namespacedGroup = $namespace . '::' . $group;

                $allTranslations = array_merge(
                    $allTranslations,
                    $client->flattenTranslations($data, $locale, $namespacedGroup)
                );
            }
        }

        return $allTranslations;
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

    /*
     * flattenTranslations() and isTranslatableArray() used to live here as
     * near-copies of TranslationClient's. They had drifted — this one skipped
     * empty values and TranslationClient's did not, so the two import commands
     * behaved differently on the same lang file. Both now go through
     * TranslationClient::flattenTranslations(), which is the only copy.
     */
}
