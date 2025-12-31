<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Console;

use Illuminate\Console\Command;
use OurEdu\TranslationClient\Helpers\TenantResolver;
use OurEdu\TranslationClient\Services\TranslationClient;
use Symfony\Component\Finder\Finder;

class ImportNamespacedTranslationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'translations:import-namespaced 
                            {--locale= : Specific locale to import (optional)}
                            {--path= : Base path to search for Lang directories (optional, defaults to src/App)}
                            {--pattern= : Directory pattern to search (optional, defaults to */Lang or */*/Lang)}';

    /**
     * The console command description.
     */
    protected $description = 'Import namespaced translations from modular directory structures';

    /**
     * Execute the console command.
     */
    public function handle(TranslationClient $client): int
    {
        $this->info('Importing namespaced translations to Translation Service...');
        $this->newLine();

        $basePath = $this->option('path') ?? base_path('src/App');
        $pattern = $this->option('pattern') ?? '*/Lang';

        if (!is_dir($basePath)) {
            $this->error("Base path not found: {$basePath}");
            return self::FAILURE;
        }

        // Find all Lang directories
        $langDirs = $this->findLangDirectories($basePath, $pattern);

        if (empty($langDirs)) {
            $this->error("No Lang directories found in: {$basePath}");
            return self::FAILURE;
        }

        $this->info("Found " . count($langDirs) . " Lang directories");
        $this->newLine();

        $totalCreated = 0;
        $totalUpdated = 0;
        $failureCount = 0;

        foreach ($langDirs as $langDir) {
            $namespace = $this->getNamespaceFromPath($langDir, $basePath);
            $this->info("Processing namespace: {$namespace}");
            $this->line(" Path: {$langDir}");

            try {
                $result = $this->importFromDirectory($client, $langDir, $namespace);

                $created = $result['created'] ?? 0;
                $updated = $result['updated'] ?? 0;

                $this->line("   Created: {$created}");
                $this->line("   Updated: {$updated}");

                $totalCreated += $created;
                $totalUpdated += $updated;
            } catch (\Exception $e) {
                $this->error("   Failed: {$e->getMessage()}");
                $failureCount++;
            }

            $this->newLine();
        }

        // Summary
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info("Total Created: {$totalCreated}");
        $this->info("Total Updated: {$totalUpdated}");
        if ($failureCount > 0) {
            $this->error("Failed Directories: {$failureCount}");
        }
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        return $failureCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Find all Lang directories matching the pattern
     */
    protected function findLangDirectories(string $basePath, string $pattern): array
    {
        $dirs = [];
        $searchPattern = $basePath . DIRECTORY_SEPARATOR . $pattern;

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
     * Import translations from a directory with namespace
     */
    protected function importFromDirectory(
        TranslationClient $client,
        string $langDir,
        string $namespace
    ): array {
        $locales = $this->option('locale') 
            ? [$this->option('locale')] 
            : $this->getLocalesFromDirectory($langDir);

        $allTranslations = [];

        foreach ($locales as $locale) {
            $localeDir = $langDir . DIRECTORY_SEPARATOR . $locale;
            
            if (!is_dir($localeDir)) {
                continue;
            }

            $files = glob($localeDir . DIRECTORY_SEPARATOR . '*.php');

            foreach ($files as $file) {
                $group = basename($file, '.php');
                $data = include $file;

                if (!is_array($data)) {
                    continue;
                }

                // Prefix group with namespace
                $namespacedGroup = $namespace . '::' . $group;

                $allTranslations = array_merge(
                    $allTranslations,
                    $this->flattenTranslations($data, $locale, $namespacedGroup)
                );
            }
        }

        if (empty($allTranslations)) {
            return ['created' => 0, 'updated' => 0];
        }

        return $client->pushTranslations($allTranslations);
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
