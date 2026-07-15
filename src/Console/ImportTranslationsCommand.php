<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Console;

use Illuminate\Console\Command;
use OurEdu\TranslationClient\Helpers\TenantResolver;
use OurEdu\TranslationClient\Services\TranslationClient;

class ImportTranslationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'translations:import
                            {--locale= : Specific locale to import (optional)}
                            {--path= : Path to lang directory (optional, defaults to lang_path())}
                            {--global : Push as shared translations (tenant_id = null) instead of per-tenant}';

    /**
     * The console command description.
     */
    protected $description = 'Import translations from Laravel lang files to Translation Service';

    /**
     * Execute the console command.
     */
    public function handle(TranslationClient $client): int
    {
        $this->info('Importing translations to Translation Service...');
        $this->newLine();

        $langPath = $this->option('path') ?? lang_path();

        if (!is_dir($langPath)) {
            $this->error("Lang directory not found: {$langPath}");
            return self::FAILURE;
        }

        // Get locales to import
        $locales = $this->getLocalesToImport($langPath);

        if (empty($locales)) {
            $this->error('No locales found to import.');
            return self::FAILURE;
        }

        $this->info("Found locales: " . implode(', ', $locales));
        $this->newLine();

        // Read and flatten each locale's lang files exactly once, regardless
        // of how many tenants we push to afterwards.
        $translationsByLocale = [];
        foreach ($locales as $locale) {
            $translationsByLocale[$locale] = $client->buildTranslationsFromFiles($locale, $langPath);
        }

        if ($this->option('global')) {
            [$created, $updated, $failures] = $this->pushLocales($client, $translationsByLocale, null);

            return $this->printSummary($created, $updated, $failures);
        }

        $tenantIds = TenantResolver::getAllTenantIds();

        if (empty($tenantIds)) {
            $this->error('No tenants found in the tenants table.');
            return self::FAILURE;
        }

        $this->info('Found tenants: ' . implode(', ', $tenantIds));
        $this->newLine();

        $grandCreated = 0;
        $grandUpdated = 0;
        $grandFailures = 0;

        foreach ($tenantIds as $tenantId) {
            $this->info("=== Tenant #{$tenantId} ===");

            [$created, $updated, $failures] = $this->pushLocales($client, $translationsByLocale, $tenantId);

            $grandCreated += $created;
            $grandUpdated += $updated;
            $grandFailures += $failures;
        }

        return $this->printSummary($grandCreated, $grandUpdated, $grandFailures);
    }

    /**
     * Push the already-built per-locale translations to a single tenant
     * (or globally, if $tenantId is null).
     *
     * @return array{0:int,1:int,2:int} [created, updated, failureCount]
     */
    protected function pushLocales(TranslationClient $client, array $translationsByLocale, ?int $tenantId): array
    {
        $totalCreated = 0;
        $totalUpdated = 0;
        $failureCount = 0;

        foreach ($translationsByLocale as $locale => $translations) {
            try {
                $this->info("Importing {$locale}...");

                $result = $client->pushTranslationsForTenant($translations, $tenantId);

                $created = $result['created'] ?? 0;
                $updated = $result['updated'] ?? 0;
                $total = $result['total'] ?? ($created + $updated);

                $this->line("   Created: {$created}");
                $this->line("   Updated: {$updated}");
                $this->line("   Total: {$total}");

                $totalCreated += $created;
                $totalUpdated += $updated;
            } catch (\Exception $e) {
                $this->error("   Failed: {$e->getMessage()}");
                $failureCount++;
            }

            $this->newLine();
        }

        return [$totalCreated, $totalUpdated, $failureCount];
    }

    /**
     * Print the run summary and return the resulting exit code.
     */
    protected function printSummary(int $totalCreated, int $totalUpdated, int $failureCount): int
    {
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info("Total Created: {$totalCreated}");
        $this->info("Total Updated: {$totalUpdated}");
        if ($failureCount > 0) {
            $this->error("Failed Locales: {$failureCount}");
        }
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        return $failureCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Get list of locales to import
     */
    protected function getLocalesToImport(string $langPath): array
    {
        // If specific locale provided via option
        if ($locale = $this->option('locale')) {
            return [$locale];
        }

        // Scan lang directory for locale directories
        $locales = [];
        $dirs = glob("{$langPath}/*", GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $locale = basename($dir);
            // Skip vendor directory
            if ($locale !== 'vendor') {
                $locales[] = $locale;
            }
        }

        return $locales;
    }
}
