<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Tests\Console;

use Illuminate\Support\Facades\Http;
use OurEdu\TranslationClient\Services\TranslationClient;
use OurEdu\TranslationClient\Tests\TestCase;

class SyncTranslationsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('translation-client.available_locales', ['en', 'ar']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Happy path
    // ─────────────────────────────────────────────────────────────────────────

    public function test_sync_command_succeeds_and_shows_translation_count(): void
    {
        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response([
                'version'    => 3,
                'locale'     => 'en',
                'client'     => 'backend',
                'updated_at' => '2024-01-01T00:00:00Z',
            ], 200),
            '*/api/v1/translation*' => Http::response([
                'data'    => ['messages.hello' => 'Hello', 'messages.bye' => 'Goodbye'],
                'version' => 3,
                'count'   => 2,
            ], 200),
        ]);

        $this->artisan('translations:sync')
            ->assertExitCode(0)
            ->expectsOutputToContain('Synced');
    }

    public function test_sync_command_syncs_specific_locale_via_option(): void
    {
        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response([
                'version'    => 1,
                'locale'     => 'fr',
                'client'     => 'backend',
                'updated_at' => '2024-01-01T00:00:00Z',
            ], 200),
            '*/api/v1/translation*' => Http::response([
                'data'    => [],
                'version' => 1,
                'count'   => 0,
            ], 200),
        ]);

        $this->artisan('translations:sync --locale=fr')
            ->assertExitCode(0);

        // Only "fr" locale should be requested
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'locale=fr');
        });
    }

    public function test_sync_command_falls_back_to_app_locale_when_available_locales_empty(): void
    {
        $this->app['config']->set('translation-client.available_locales', []);
        $this->app['config']->set('app.locale', 'fr');

        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response([
                'version'    => 1,
                'locale'     => 'fr',
                'client'     => 'backend',
                'updated_at' => '2024-01-01T00:00:00Z',
            ], 200),
            '*/api/v1/translation*' => Http::response(['data' => [], 'version' => 1, 'count' => 0], 200),
        ]);

        $this->artisan('translations:sync')
            ->assertExitCode(0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'locale=fr');
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // --force flag
    // ─────────────────────────────────────────────────────────────────────────

    public function test_sync_command_with_force_clears_cache_before_fetching(): void
    {
        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response([
                'version'    => 5,
                'locale'     => 'en',
                'client'     => 'backend',
                'updated_at' => '2024-01-01T00:00:00Z',
            ], 200),
            '*/api/v1/translation*' => Http::response([
                'data'    => ['key' => 'value'],
                'version' => 5,
                'count'   => 1,
            ], 200),
        ]);

        $this->artisan('translations:sync --force --locale=en')
            ->assertExitCode(0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // API failure – still exits with non-zero
    // ─────────────────────────────────────────────────────────────────────────

    public function test_sync_command_reports_partial_failure_when_api_errors(): void
    {
        // 'en' succeeds, 'ar' fails silently (endpoint returns 500 which produces a manifest with version 1)
        // The command catches exceptions, so we need to trigger one
        $client = $this->getMockBuilder(TranslationClient::class)
            ->onlyMethods(['checkVersion'])
            ->getMock();

        $client->expects($this->any())
            ->method('checkVersion')
            ->willThrowException(new \Exception('Service unavailable'));

        $this->app->instance(TranslationClient::class, $client);

        $this->artisan('translations:sync --locale=en')
            ->assertExitCode(1)
            ->expectsOutputToContain('Failed');
    }
}


