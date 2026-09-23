<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Tests;

use Illuminate\Translation\FileLoader;
use OurEdu\TranslationClient\Services\ApiTranslationLoader;
use OurEdu\TranslationClient\TranslationServiceProvider;

class TranslationServiceProviderTest extends TestCase
{
    public function test_the_package_is_disabled_by_default(): void
    {
        $this->assertFalse(config('translation-client.enabled'));
        $this->assertInstanceOf(FileLoader::class, $this->app->make('translation.loader'));
    }

    public function test_enabling_the_package_registers_the_api_loader(): void
    {
        $this->app['config']->set('translation-client.enabled', true);

        $this->app->getProvider(TranslationServiceProvider::class)->boot();

        $this->assertInstanceOf(ApiTranslationLoader::class, $this->app->make('translation.loader'));
    }
}
