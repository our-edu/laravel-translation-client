<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use OurEdu\TranslationClient\Services\TranslationClient;

class ImportTenantTranslationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $tenantId,
        public string $langPath,
        public ?string $locale = null,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(TranslationClient $client): void
    {
        // Get locales to import
        $locales = $this->getLocalesToImport($this->langPath);

        if (empty($locales)) {
            throw new \Exception('No locales found to import.');
        }

        // Read and flatten each locale's lang files
        $translationsByLocale = [];
        foreach ($locales as $locale) {
            $translationsByLocale[$locale] = $client->buildTranslationsFromFiles($locale, $this->langPath);
        }

        // Push translations for this tenant
        $this->pushLocales($client, $translationsByLocale, $this->tenantId);
    }

    /**
     * Push the per-locale translations to this tenant.
     */
    protected function pushLocales(TranslationClient $client, array $translationsByLocale, int $tenantId): void
    {
        foreach ($translationsByLocale as $locale => $translations) {
            try {
                $client->pushTranslationsForTenant($translations, $tenantId);
            } catch (\Exception $e) {
                throw new \Exception("Failed to import locale {$locale} for tenant {$tenantId}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Get list of locales to import
     */
    protected function getLocalesToImport(string $langPath): array
    {
        // If specific locale provided via option
        if ($this->locale) {
            return [$this->locale];
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
