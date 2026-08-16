<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Console;

use Illuminate\Console\Command;
use OurEdu\TranslationClient\Jobs\ImportTranslationsJob;

class ImportTranslationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'translations:import
                            {--locale= : Specific locale to import (optional)}
                            {--path= : Path to lang directory (optional, defaults to lang_path())}
                            {--sync : Run synchronously instead of dispatching to queue (optional)}';

    /**
     * The console command description.
     *
     * `--only-global` is gone rather than deprecated. Writing the base template
     * is now the only behaviour, so the flag had nothing left to select, and a
     * no-op flag reads as though the other mode still exists.
     */
    protected $description = 'Import translations from Laravel lang files to the Translation Service base template';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Importing translations to Translation Service...');
        $this->newLine();

        $langPath = $this->option('path') ?? lang_path();

        if (!is_dir($langPath)) {
            $this->error("Lang directory not found: {$langPath}");
            return self::FAILURE;
        }

        $job = new ImportTranslationsJob(
            $langPath,
            $this->option('locale')
        );

        try {
            if ($this->option('sync')) {
                $job->handle(app(\OurEdu\TranslationClient\Services\TranslationClient::class));
                $this->info('Translations imported successfully!');
            } else {
                ImportTranslationsJob::dispatch(
                    $langPath,
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
