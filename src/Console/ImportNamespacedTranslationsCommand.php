<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Console;

use Illuminate\Console\Command;
use OurEdu\TranslationClient\Helpers\TenantResolver;
use OurEdu\TranslationClient\Services\TranslationClient;
use Symfony\Component\Finder\Finder;

use OurEdu\TranslationClient\Traits\HandlesNamespacedTranslations;
use OurEdu\TranslationClient\Jobs\ImportNamespacedTranslationsJob;

class ImportNamespacedTranslationsCommand extends Command
{
    use HandlesNamespacedTranslations;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'translations:import-namespaced
                            {--locale= : Specific locale to import (optional)}
                            {--path= : Base path to search for Lang directories (optional, defaults to src/App)}
                            {--pattern= : Directory pattern to search (optional, defaults to */Lang or */*/Lang)}
                            {--queue : Whether to handle the import in a queue job}';

    /**
     * The console command description.
     */
    protected $description = 'Import namespaced translations from modular directory structures';

    /**
     * Execute the console command.
     */
    public function handle(TranslationClient $client): int
    {
        $locale = $this->option('locale');
        $basePath = $this->option('path') ?? base_path('src/App');
        $pattern = $this->option('pattern') ?? '*/Lang';

        if ($this->option('queue')) {
            $this->info('Dispatching translation import job to queue...');
            ImportNamespacedTranslationsJob::dispatch($locale, $basePath, $pattern);
            $this->info('Job dispatched successfully.');
            return self::SUCCESS;
        }

        $this->info('Importing namespaced translations to Translation Service...');
        $this->newLine();

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
}
