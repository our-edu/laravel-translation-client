<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Console;

use Illuminate\Console\Command;
use OurEdu\TranslationClient\Services\TranslationClient;
use OurEdu\TranslationClient\Services\ApiTranslationLoader;

class SyncTranslationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'translations:sync 
                            {--locale= : Specific locale to sync (optional)}
                            {--force : Force refresh even if cache is valid}';

    /**
     * The console command description.
     */
    protected $description = 'Sync translations from the Translation Service';

    /**
     * Execute the console command.
     */
    public function handle(TranslationClient $client): int
    {
        $this->info(' Syncing translations from Translation Service...');
        $this->newLine();

        // Get locales to sync
        $locales = $this->getLocalesToSync();

        if (empty($locales)) {
            $this->error('No locales configured. Please set available locales in your config.');
            return self::FAILURE;
        }

        $this->info("Syncing locales: " . implode(', ', $locales));
        $this->newLine();

        $successCount = 0;
        $failureCount = 0;

        foreach ($locales as $locale) {
            try {
                $this->info(" Syncing {$locale}...");

                // Force refresh by clearing cache if --force option is used
                if ($this->option('force')) {
                    $client->clearCache($locale);
                }

                // Check version first
                $manifest = $client->checkVersion($locale);
                $this->line("   Version: {$manifest['version']}");
                $this->line("   Updated: {$manifest['updated_at']}");

                // Fetch translations
                $translations = $client->loadTranslations($locale);
                $count = count($translations);

                // Preload into loader if available
                $loader = app('translation.loader');
                if ($loader instanceof ApiTranslationLoader) {
                    $loader->clearLoaded($locale);
                    $loader->preloadLocale($locale);
                }

                $this->info("    Synced {$count} translations");
                $successCount++;
            } catch (\Exception $e) {
                $this->error("    Failed: {$e->getMessage()}");
                $failureCount++;
            }

            $this->newLine();
        }

        // Summary
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info("✓ Success: {$successCount}");
        if ($failureCount > 0) {
            $this->error("✗ Failed: {$failureCount}");
        }
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        return $failureCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Get list of locales to sync
     */
    protected function getLocalesToSync(): array
    {
        // If specific locale provided via option
        if ($locale = $this->option('locale')) {
            return [$locale];
        }

        // Get from config
        $locales = config('translation-client.available_locales', []);

        // Fallback to app locale if no available_locales configured
        if (empty($locales)) {
            $locales = [config('app.locale', 'en')];
        }

        return $locales;
    }
}
