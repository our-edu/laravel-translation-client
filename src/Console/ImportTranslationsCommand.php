<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Console;

use Illuminate\Console\Command;
use OurEdu\TranslationClient\Services\TranslationClient;

class ImportTranslationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'translations:import 
                            {--locale= : Specific locale to import (optional)}
                            {--path= : Path to lang directory (optional, defaults to lang_path())}';

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

        $totalCreated = 0;
        $totalUpdated = 0;
        $failureCount = 0;

        foreach ($locales as $locale) {
            try {
                $this->info("Importing {$locale}...");

                $result = $client->importFromFiles($locale, $langPath);

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

        // Summary
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
