<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Console;

use Illuminate\Console\Command;
use OurEdu\TranslationClient\Jobs\ImportNamespacedTranslationsJob;

class ImportNamespacedTranslationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'translations:import-namespaced
                            {--locale= : Specific locale to import (optional)}
                            {--path= : Base path to search for Lang directories (optional, defaults to src/App)}
                            {--pattern= : Directory pattern to search (optional, defaults to */Lang or */*/Lang)}
                            {--sync : Run synchronously instead of dispatching to queue (optional)}';

    /**
     * The console command description.
     *
     * `--only-global` is gone rather than deprecated — see
     * ImportTranslationsCommand.
     */
    protected $description = 'Import namespaced translations from modular directory structures into the base template';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Importing namespaced translations to Translation Service...');
        $this->newLine();

        $basePath = $this->option('path') ?? base_path('src/App');
        $pattern = $this->option('pattern') ?? '*/Lang';

        if (!is_dir($basePath)) {
            $this->error("Base path not found: {$basePath}");
            return self::FAILURE;
        }

        $job = new ImportNamespacedTranslationsJob(
            $basePath,
            $pattern,
            $this->option('locale')
        );

        try {
            if ($this->option('sync')) {
                $job->handle(app(\OurEdu\TranslationClient\Services\TranslationClient::class));
                $this->info('Translations imported successfully!');
            } else {
                ImportNamespacedTranslationsJob::dispatch(
                    $basePath,
                    $pattern,
                    $this->option('locale')
                );
                $this->info('Import job dispatched to queue.');
                $this->line('The import will be processed by your queue worker.');
            }
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to import translations: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
