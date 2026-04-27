<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Tests;

use OurEdu\TranslationClient\Services\ApiTranslationLoader;
use OurEdu\TranslationClient\Services\TranslationClient;

class TranslationServiceProviderTest extends TestCase
{
    public function test_translation_client_is_registered_in_container(): void
    {
        $client = $this->app->make(TranslationClient::class);

        $this->assertInstanceOf(TranslationClient::class, $client);
    }

    public function test_translation_client_is_scoped_singleton(): void
    {
        $a = $this->app->make(TranslationClient::class);
        $b = $this->app->make(TranslationClient::class);

        $this->assertSame($a, $b);
    }

    public function test_api_translation_loader_replaces_default_loader(): void
    {
        $loader = $this->app->make('translation.loader');

        $this->assertInstanceOf(ApiTranslationLoader::class, $loader);
    }

    public function test_translator_is_bound_in_container(): void
    {
        $translator = $this->app->make('translator');

        $this->assertInstanceOf(\Illuminate\Translation\Translator::class, $translator);
    }

    public function test_translator_uses_api_translation_loader(): void
    {
        $translator = $this->app->make('translator');
        $loader     = $translator->getLoader();

        $this->assertInstanceOf(ApiTranslationLoader::class, $loader);
    }

    public function test_sync_command_is_registered(): void
    {
        $commands = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all();

        $this->assertArrayHasKey('translations:sync', $commands);
    }

    public function test_clear_cache_command_is_registered(): void
    {
        $commands = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all();

        $this->assertArrayHasKey('translations:clear-cache', $commands);
    }

    public function test_import_command_is_registered(): void
    {
        $commands = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all();

        $this->assertArrayHasKey('translations:import', $commands);
    }

    public function test_import_namespaced_command_is_registered(): void
    {
        $commands = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all();

        $this->assertArrayHasKey('translations:import-namespaced', $commands);
    }

    public function test_config_is_merged(): void
    {
        $this->assertNotNull(config('translation-client.service_url'));
        $this->assertNotNull(config('translation-client.client'));
    }
}



