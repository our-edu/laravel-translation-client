<?php

namespace OurEdu\TranslationClient\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use OurEdu\TranslationClient\TranslationServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            TranslationServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Translation' => \OurEdu\TranslationClient\Facades\Translation::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup default configuration
        $app['config']->set('translation-client.service_url', 'http://localhost:8000');
        $app['config']->set('translation-client.tenant_uuid', 'test-tenant');
        $app['config']->set('translation-client.preload', false);
    }
}
