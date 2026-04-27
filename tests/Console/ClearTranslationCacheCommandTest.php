<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Tests\Console;

use OurEdu\TranslationClient\Services\TranslationClient;
use OurEdu\TranslationClient\Tests\TestCase;

class ClearTranslationCacheCommandTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // Clear all
    // ─────────────────────────────────────────────────────────────────────────

    public function test_clear_cache_command_succeeds_for_all_locales(): void
    {
        $this->artisan('translations:clear-cache')
            ->assertExitCode(0)
            ->expectsOutputToContain('Translation cache cleared successfully');
    }

    public function test_clear_cache_command_outputs_clearing_all_message(): void
    {
        $this->artisan('translations:clear-cache')
            ->assertExitCode(0)
            ->expectsOutputToContain('Clearing all translation caches');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Clear specific locale
    // ─────────────────────────────────────────────────────────────────────────

    public function test_clear_cache_command_succeeds_for_specific_locale(): void
    {
        $this->artisan('translations:clear-cache --locale=en')
            ->assertExitCode(0)
            ->expectsOutputToContain('Translation cache cleared successfully');
    }

    public function test_clear_cache_command_mentions_locale_in_output(): void
    {
        $this->artisan('translations:clear-cache --locale=ar')
            ->assertExitCode(0)
            ->expectsOutputToContain('ar');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Failure handling
    // ─────────────────────────────────────────────────────────────────────────

    public function test_clear_cache_command_returns_failure_when_client_throws(): void
    {
        $client = $this->getMockBuilder(TranslationClient::class)
            ->onlyMethods(['clearCache'])
            ->getMock();

        $client->expects($this->once())
            ->method('clearCache')
            ->willThrowException(new \Exception('Cache store unavailable'));

        $this->app->instance(TranslationClient::class, $client);

        $this->artisan('translations:clear-cache')
            ->assertExitCode(1)
            ->expectsOutputToContain('Failed to clear cache');
    }
}

