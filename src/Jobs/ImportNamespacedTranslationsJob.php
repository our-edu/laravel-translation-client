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

class ImportNamespacedTranslationsJob implements ShouldQueue
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
        public string $basePath,
        public string $pattern,
        public ?string $locale = null,
        public bool $onlyGlobal = false,
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

        // Read and flatten each namespace's lang files exactly once
        $translationsByNamespace = [];
        foreach ($langDirs as $langDir) {
            $namespace = $this->getNamespaceFromPath($langDir, $this->basePath);
            $translations = $this->readFromDirectory($langDir, $namespace, $this->locale);

            if (!empty($translations)) {
                $translationsByNamespace[$namespace] = $translations;
            }
        }

        // Push global translations
        $this->pushForTenant($client, $translationsByNamespace, null);

        // If only global flag is set, stop here
        if ($this->onlyGlobal) {
            return;
        }

        // Get all tenant IDs and dispatch per-tenant jobs
        $tenantIds = TenantResolver::getAllTenantIds();

        if (empty($tenantIds)) {
            throw new \Exception('No tenants found in the tenants table.');
        }

        // Dispatch a job for each tenant
        foreach ($tenantIds as $tenantId) {
            ImportTenantNamespacedTranslationsJob::dispatch(
                $tenantId,
                $this->basePath,
                $this->pattern,
                $this->locale
            );
        }
    }

    /**
     * Push the already-built per-namespace translations to a single tenant
     * (or globally, if $tenantId is null).
     */
    protected function pushForTenant(TranslationClient $client, array $translationsByNamespace, ?int $tenantId): void
    {
        foreach ($translationsByNamespace as $namespace => $translations) {
            try {
                $payload = array_map(static function (array $translation) use ($tenantId) {
                    $translation['tenant_id'] = $tenantId;
                    return $translation;
                }, $translations);

                $client->pushTranslations($payload);
            } catch (\Exception $e) {
                throw new \Exception("Failed to push namespace {$namespace}: {$e->getMessage()}");
            }
        }
    }
}
