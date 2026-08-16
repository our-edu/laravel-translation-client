<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use OurEdu\TranslationClient\Services\TranslationClient;

class ImportTranslationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
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

        // Read and flatten each locale's lang files exactly once
        $translationsByLocale = [];
        foreach ($locales as $locale) {
            $translationsByLocale[$locale] = $client->buildTranslationsFromFiles($locale, $this->langPath);
        }

        $client->reportSkippedKeys();

        // Write the base template and stop.
        //
        // This used to fan out afterwards, re-pushing identical content once per
        // tenant in the tenants table. That was the client-side instance of the
        // physical-copy model the service has now replaced with inheritance: a
        // tenant with no override for a key reads the base template already, so
        // a per-tenant copy adds nothing and becomes an unreachable row the
        // moment the tenant moves to a regional variant — the resolution chain
        // has no `tenant + <bare language>` step.
        //
        // Lang files are the template, never a tenant's overrides. Overrides are
        // authored in the service's admin screens.
        $this->pushLocales($client, $translationsByLocale, null);
    }

    /**
     * Push the already-built per-locale translations to a single tenant
     * (or globally, if $tenantId is null).
     */
    protected function pushLocales(TranslationClient $client, array $translationsByLocale, ?int $tenantId): void
    {
        foreach ($translationsByLocale as $locale => $translations) {
            try {
                $client->pushTranslationsForTenant($translations, $tenantId);
            } catch (\Exception $e) {
                throw new \Exception("Failed to import locale {$locale}: {$e->getMessage()}");
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
