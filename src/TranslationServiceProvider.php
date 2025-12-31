<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient;

use Illuminate\Support\ServiceProvider;
use OurEdu\TranslationClient\Services\TranslationClient;
use OurEdu\TranslationClient\Services\ApiTranslationLoader;
use OurEdu\TranslationClient\Console\SyncTranslationsCommand;
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
        $this->app->singleton(TranslationClient::class, function ($app) {
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
                SyncTranslationsCommand::class,
                ClearTranslationCacheCommand::class,
                \OurEdu\TranslationClient\Console\ImportTranslationsCommand::class,
                \OurEdu\TranslationClient\Console\ImportNamespacedTranslationsCommand::class,
            ]);
        }

        // Replace Laravel's translation loader AFTER all providers have booted
        // This ensures our loader takes precedence
        $this->app->booted(function () {
            $this->app->singleton('translation.loader', function ($app) {
                $client = $app->make(TranslationClient::class);
                return new ApiTranslationLoader(
                    $app['files'],
                    $app['path.lang'],
                    $client
                );
            });

            // Force re-resolve the translator to use new loader
            $this->app->forgetInstance('translator');
        });

        // Auto-register namespaces from translation service
        if (config('translation-client.auto_register_namespaces', true)) {
            $this->registerNamespaces();
        }

        // Preload translations if enabled
        if (config('translation-client.preload', true)) {
            $this->preloadTranslations();
        }
    }

    /**
     * Auto-register translation namespaces from the service
     */
    protected function registerNamespaces(): void
    {
        try {
            $locale = $this->app->getLocale();
            $client = $this->app->make(TranslationClient::class);
            $loader = $this->app->make('translation.loader');

            // Fetch all groups to detect namespaces
            $bundle = $client->fetchBundle($locale, null, null, 'flat');

            // Extract unique namespaces from group names
            $namespaces = [];
            foreach (array_keys($bundle) as $key) {
                // Check if key contains namespace (format: Namespace::group.key or PREFIX:Namespace::group.key)
                if (preg_match('/^(?:[^:]+:)?([^:]+)::/', $key, $matches)) {
                    $namespace = $matches[1];
                    $namespaces[$namespace] = true;
                }
            }

            // Register each namespace
            foreach (array_keys($namespaces) as $namespace) {
                if ($loader instanceof ApiTranslationLoader) {
                    $loader->addNamespace($namespace, base_path('lang'));
                }
            }
        } catch (\Exception $e) {
            // Silently fail - namespaces can be registered manually if needed
            if (config('translation-client.logging.enabled', false)) {
                \Log::warning('[TranslationClient] Failed to auto-register namespaces: ' . $e->getMessage());
            }
        }
    }

    /**
     * Preload translations for the current locale
     */
    protected function preloadTranslations(): void
    {
        // Only preload in web/API contexts, not in console
        // if ($this->app->runningInConsole()) {
        //     return;
        // }

        try {
            $locale = $this->app->getLocale();
            $loader = $this->app->make('translation.loader');

            if ($loader instanceof ApiTranslationLoader) {
                $loader->preloadLocale($locale);
            }
        } catch (\Exception $e) {
            // Silently fail - translations will be loaded on-demand
            if (config('translation-client.logging.enabled', false)) {
                \Log::warning('[TranslationClient] Failed to preload translations: ' . $e->getMessage());
            }
        }
    }
}
