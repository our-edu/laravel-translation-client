<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient;

use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\Translator;
use OurEdu\TranslationClient\Services\TranslationClient;
use OurEdu\TranslationClient\Services\ApiTranslationLoader;
use OurEdu\TranslationClient\Console\ClearTranslationCacheCommand;

class TranslationServiceProvider extends ServiceProvider
{
    /**
     * Register services
     */
    public function register(): void
    {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../config/translation-client.php',
            'translation-client'
        );

        // Register Translation Client as singleton
        $this->app->scoped(TranslationClient::class, function ($app) {
            return new TranslationClient();
        });
    }

    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        // Publish configuration
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/translation-client.php' => config_path('translation-client.php'),
            ], 'translation-client-config');

            // Register commands
            $this->commands([
                ClearTranslationCacheCommand::class,
                \OurEdu\TranslationClient\Console\ImportTranslationsCommand::class,
                \OurEdu\TranslationClient\Console\ImportNamespacedTranslationsCommand::class,
            ]);
        }

        // Replace Laravel's translation loader AFTER all providers have booted
        // This ensures our loader takes precedence
        $this->app->booted(function () {
            $this->app->scoped('translation.loader', function ($app) {
                $client = $app->make(TranslationClient::class);
                return new ApiTranslationLoader(
                    $app['files'],
                    $app['path.lang'],
                    $client
                );
            });

            $this->app->scoped('translator' , function ($app){
                $translator = new Translator(
                    $app->make('translation.loader'),
                    $app->getLocale()
                );
                $translator->setFallback($app->config['app.fallback_locale'] ?? 'en');
                return $translator;
            });

            // Force re-resolve the validator to use new translator
            $this->app->forgetInstance('validator');
        });
    }

}
