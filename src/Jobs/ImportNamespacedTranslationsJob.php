<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use OurEdu\TranslationClient\Helpers\TenantResolver;
use OurEdu\TranslationClient\Services\TranslationClient;
use OurEdu\TranslationClient\Traits\HandlesNamespacedTranslations;

class ImportNamespacedTranslationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HandlesNamespacedTranslations;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ?string $locale = null,
        protected ?string $basePath = null,
        protected ?string $pattern = null
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(TranslationClient $client): void
    {
        $basePath = $this->basePath ?? base_path('src/App');
        $pattern = $this->pattern ?? '*/Lang';

        if (!is_dir($basePath)) {
            return;
        }

        // Find all Lang directories
        $langDirs = $this->findLangDirectories($basePath, $pattern);

        if (empty($langDirs)) {
            return;
        }

        foreach ($langDirs as $langDir) {
            $namespace = $this->getNamespaceFromPath($langDir, $basePath);

            try {
                $this->importFromDirectory($client, $langDir, $namespace);
            } catch (\Exception $e) {
                // In a job, we might want to log this or let it fail
                if (config('translation-client.logging.enabled', false)) {
                    \Log::error("[TranslationClient] Failed to import from {$langDir}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Import translations from a directory with namespace
     */
    protected function importFromDirectory(
        TranslationClient $client,
        string $langDir,
        string $namespace
    ): array {
        $locales = $this->locale
            ? [$this->locale]
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
