<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use OurEdu\TranslationClient\Services\TranslationClient;

class ImportTenantNamespacedTranslationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TranslationProcessingTrait;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $tenantId,
        public string $basePath,
        public string $pattern,
        public ?string $locale = null,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(TranslationClient $client): void
    {
        // Find all Lang directories
        $langDirs = $this->findLangDirectories($this->basePath, $this->pattern);

        if (empty($langDirs)) {
            throw new \Exception("No Lang directories found in: {$this->basePath}");
        }

        // Read and flatten each namespace's lang files
        $translationsByNamespace = [];
        foreach ($langDirs as $langDir) {
            $namespace = $this->getNamespaceFromPath($langDir, $this->basePath);
            $translations = $this->readFromDirectory($langDir, $namespace, $this->locale);

            if (!empty($translations)) {
                $translationsByNamespace[$namespace] = $translations;
            }
        }

        // Push translations for this tenant
        $this->pushForTenant($client, $translationsByNamespace, $this->tenantId);
    }

    /**
     * Push the per-namespace translations to this tenant.
     */
    protected function pushForTenant(TranslationClient $client, array $translationsByNamespace, int $tenantId): void
    {
        foreach ($translationsByNamespace as $namespace => $translations) {
            try {
                $payload = array_map(static function (array $translation) use ($tenantId) {
                    $translation['tenant_id'] = $tenantId;
                    return $translation;
                }, $translations);

                $client->pushTranslations($payload);
            } catch (\Exception $e) {
                throw new \Exception("Failed to import namespace {$namespace} for tenant {$tenantId}: {$e->getMessage()}");
            }
        }
    }
}
