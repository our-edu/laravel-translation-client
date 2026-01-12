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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $locale,
        protected string $langPath
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(TranslationClient $client): void
    {
        try {
            $client->importFromFiles($this->locale, $this->langPath);
        } catch (\Exception $e) {
            if (config('translation-client.logging.enabled', false)) {
                \Log::error("[TranslationClient] Failed to import standard translations for {$this->locale}: " . $e->getMessage());
            }
        }
    }
}
