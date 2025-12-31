<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Console;

use Illuminate\Console\Command;
use OurEdu\TranslationClient\Services\TranslationClient;
use OurEdu\TranslationClient\Services\ApiTranslationLoader;

class ClearTranslationCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'translations:clear-cache 
                            {--locale= : Specific locale to clear (optional)}';

    /**
     * The console command description.
     */
    protected $description = 'Clear translation caches';

    /**
     * Execute the console command.
     */
    public function handle(TranslationClient $client): int
    {
        $locale = $this->option('locale');

        if ($locale) {
            $this->info("Clearing translation cache for locale: {$locale}");
        } else {
            $this->info('Clearing all translation caches...');
        }

        try {
            // Clear client cache
            $client->clearCache($locale);

            // Clear loader memory cache
            $loader = app('translation.loader');
            if ($loader instanceof ApiTranslationLoader) {
                $loader->clearLoaded($locale);
            }

            $this->info('Translation cache cleared successfully!');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to clear cache: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
