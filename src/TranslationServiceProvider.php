<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient;

use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\Translator;
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
                SyncTranslationsCommand::class,
                ClearTranslationCacheCommand::class,
                \OurEdu\TranslationClient\Console\ImportTranslationsCommand::class,
                \OurEdu\TranslationClient\Console\ImportNamespacedTranslationsCommand::class,
            ]);
        }

        // Replace Laravel's translation loader AFTER all providers have booted.
        // Laravel's TranslationServiceProvider is a DeferrableProvider: it only calls
        // register() when 'translation.loader' or 'translator' is first resolved, which
        // would override our scoped binding.  We force it to register first, then
        // immediately forget the stale instances and replace them with our own.
        $this->app->booted(function () {
            // Trigger the deferred TranslationServiceProvider so it registers
            // its FileLoader binding before we override it.
            $this->app->make('translation.loader');
            $this->app->forgetInstance('translation.loader');

            $this->app->scoped('translation.loader', function ($app) {
                $client = $app->make(TranslationClient::class);
                return new ApiTranslationLoader(
                    $app['files'],
                    $app['path.lang'],
                    $client
                );
            });

            // Do the same for 'translator'.
            $this->app->make('translator');
            $this->app->forgetInstance('translator');

            $this->app->scoped('translator', function ($app) {
                $translator = new Translator(
                    $app->make('translation.loader'),
                    $app->getLocale()
                );
                $translator->setFallback($app->config['app.fallback_locale'] ?? 'en');
                return $translator;
            });

            // Force re-resolve the validator to use the new translator.
            $this->app->forgetInstance('validator');
        });

        // Auto-register namespaces from translation service
        if (config('translation-client.auto_register_namespaces', true)) {
            $this->registerNamespaces();
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

}
