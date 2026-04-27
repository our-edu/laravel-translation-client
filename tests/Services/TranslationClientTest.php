<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Tests\Services;

use Illuminate\Support\Facades\Http;
use OurEdu\TranslationClient\Services\TranslationClient;
use OurEdu\TranslationClient\Tests\TestCase;

class TranslationClientTest extends TestCase
{
    private TranslationClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->app->make(TranslationClient::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // checkVersion
    // ─────────────────────────────────────────────────────────────────────────

    public function test_check_version_returns_manifest_from_api(): void
    {
        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response([
                'version'    => 5,
                'locale'     => 'en',
                'client'     => 'backend',
                'updated_at' => '2024-01-01T00:00:00Z',
            ], 200),
        ]);

        $manifest = $this->client->checkVersion('en');

        $this->assertEquals(5, $manifest['version']);
        $this->assertEquals('en', $manifest['locale']);
    }

    public function test_check_version_includes_tenant_and_locale_in_request(): void
    {
        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response(['version' => 1], 200),
        ]);

        $this->client->checkVersion('ar');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'locale=ar')
                && str_contains($request->url(), 'tenant=1');
        });
    }

    public function test_check_version_returns_default_manifest_on_server_error(): void
    {
        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response([], 500),
        ]);

        $manifest = $this->client->checkVersion('en');

        $this->assertEquals(1, $manifest['version']);
        $this->assertEquals('en', $manifest['locale']);
    }

    public function test_check_version_returns_default_manifest_on_connection_exception(): void
    {
        Http::fake(function () {
            throw new \Exception('Connection refused');
        });

        $manifest = $this->client->checkVersion('en');

        $this->assertEquals(1, $manifest['version']);
        $this->assertEquals('en', $manifest['locale']);
    }

    public function test_check_version_is_cached_on_second_call(): void
    {
        $callCount = 0;

        Http::fake([
            '*/api/v1/translation/manifest*' => function () use (&$callCount) {
                $callCount++;
                return Http::response(['version' => 7, 'locale' => 'en', 'client' => 'backend', 'updated_at' => now()->toIso8601String()], 200);
            },
        ]);

        $this->client->checkVersion('en');
        $this->client->checkVersion('en');

        $this->assertEquals(1, $callCount, 'Manifest should be cached after first call');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // fetchBundle
    // ─────────────────────────────────────────────────────────────────────────

    public function test_fetch_bundle_returns_translations_on_success(): void
    {
        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response(['version' => 1], 200),
            '*/api/v1/translation*'          => Http::response([
                'data'    => ['messages.welcome' => 'Hello', 'messages.bye' => 'Goodbye'],
                'version' => 1,
                'count'   => 2,
            ], 200),
        ]);

        $bundle = $this->client->fetchBundle('en');

        $this->assertCount(2, $bundle);
        $this->assertEquals('Hello', $bundle['messages.welcome']);
        $this->assertEquals('Goodbye', $bundle['messages.bye']);
    }

    public function test_fetch_bundle_sends_groups_parameter(): void
    {
        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response(['version' => 1], 200),
            '*/api/v1/translation*'          => Http::response(['data' => [], 'version' => 1], 200),
        ]);

        $this->client->fetchBundle('en', ['messages', 'auth']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'groups=messages%2Cauth')
                || str_contains($request->url(), 'groups=messages,auth');
        });
    }

    public function test_fetch_bundle_returns_empty_array_on_api_failure(): void
    {
        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response([], 500),
            '*/api/v1/translation*'          => Http::response([], 503),
        ]);

        $bundle = $this->client->fetchBundle('en');

        $this->assertEmpty($bundle);
    }

    public function test_fetch_bundle_returns_empty_array_on_exception(): void
    {
        Http::fake(function () {
            throw new \Exception('Network error');
        });

        $bundle = $this->client->fetchBundle('en');

        $this->assertEmpty($bundle);
    }

    public function test_fetch_bundle_uses_stale_cache_when_fallback_enabled_and_api_fails(): void
    {
        $this->app['config']->set('translation-client.fallback_on_error', true);
        $this->app->forgetInstance(TranslationClient::class);
        $client = $this->app->make(TranslationClient::class);

        // First call: populate cache
        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response(['version' => 1, 'locale' => 'en', 'client' => 'backend', 'updated_at' => now()->toIso8601String()], 200),
            '*/api/v1/translation*'          => Http::response([
                'data'    => ['messages.hello' => 'Hi'],
                'version' => 1,
                'count'   => 1,
            ], 200),
        ]);
        $client->fetchBundle('en');

        // Second call: API fails, but cache exists with different version to bypass version check
        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response(['version' => 2, 'locale' => 'en', 'client' => 'backend', 'updated_at' => now()->toIso8601String()], 200),
            '*/api/v1/translation*'          => Http::response([], 503),
        ]);
        $bundle = $client->fetchBundle('en');

        // Stale cache still contains old data
        $this->assertArrayHasKey('messages.hello', $bundle);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // loadTranslations
    // ─────────────────────────────────────────────────────────────────────────

    public function test_load_translations_delegates_to_fetch_bundle(): void
    {
        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response(['version' => 1], 200),
            '*/api/v1/translation*'          => Http::response([
                'data'    => ['auth.failed' => 'These credentials do not match.'],
                'version' => 1,
                'count'   => 1,
            ], 200),
        ]);

        $translations = $this->client->loadTranslations('en');

        $this->assertArrayHasKey('auth.failed', $translations);
        $this->assertEquals('These credentials do not match.', $translations['auth.failed']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // clearCache
    // ─────────────────────────────────────────────────────────────────────────

    public function test_clear_cache_for_all_locales_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->client->clearCache();
    }

    public function test_clear_cache_for_specific_locale_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->client->clearCache('en');
    }

    public function test_clear_cache_invalidates_cached_manifest(): void
    {
        $callCount = 0;

        Http::fake([
            '*/api/v1/translation/manifest*' => function () use (&$callCount) {
                $callCount++;
                return Http::response(['version' => 9, 'locale' => 'en', 'client' => 'backend', 'updated_at' => now()->toIso8601String()], 200);
            },
        ]);

        $this->client->checkVersion('en'); // Populates cache
        $this->client->clearCache('en');   // Invalidates cache
        $this->client->checkVersion('en'); // Should hit API again

        $this->assertEquals(2, $callCount);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // pushTranslations
    // ─────────────────────────────────────────────────────────────────────────

    public function test_push_translations_returns_created_and_updated_counts(): void
    {
        Http::fake([
            '*/api/v1/translation' => Http::response(['created' => 2, 'updated' => 1], 201),
        ]);

        $result = $this->client->pushTranslations([
            ['locale' => 'en', 'group' => 'messages', 'key' => 'foo', 'value' => 'bar', 'client' => 'backend', 'is_active' => true],
            ['locale' => 'ar', 'group' => 'messages', 'key' => 'foo', 'value' => 'بار', 'client' => 'backend', 'is_active' => true],
        ]);

        $this->assertEquals(2, $result['created']);
        $this->assertEquals(1, $result['updated']);
    }

    public function test_push_translations_sends_translations_in_request_body(): void
    {
        Http::fake([
            '*/api/v1/translation' => Http::response(['created' => 1, 'updated' => 0], 201),
        ]);

        $translations = [
            ['locale' => 'en', 'group' => 'messages', 'key' => 'hello', 'value' => 'Hello', 'client' => 'backend', 'is_active' => true],
        ];
        $this->client->pushTranslations($translations);

        Http::assertSent(function ($request) use ($translations) {
            $body = $request->data();
            return isset($body['translations']) && count($body['translations']) === 1;
        });
    }

    public function test_push_translations_throws_exception_on_api_failure(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Failed to push translations/');

        Http::fake([
            '*/api/v1/translation' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->client->pushTranslations([
            ['locale' => 'en', 'group' => 'messages', 'key' => 'foo', 'value' => 'bar'],
        ]);
    }

    public function test_push_translations_throws_exception_on_connection_failure(): void
    {
        $this->expectException(\Exception::class);

        Http::fake(function () {
            throw new \Exception('Connection refused');
        });

        $this->client->pushTranslations([
            ['locale' => 'en', 'group' => 'messages', 'key' => 'foo', 'value' => 'bar'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // pushTranslation (single item convenience method)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_push_translation_sends_single_item_with_correct_structure(): void
    {
        Http::fake([
            '*/api/v1/translation' => Http::response(['created' => 1, 'updated' => 0], 201),
        ]);

        $result = $this->client->pushTranslation('en', 'messages', 'welcome', 'Welcome!');

        Http::assertSent(function ($request) {
            $data = $request->data();
            $item = $data['translations'][0] ?? [];
            return $item['locale'] === 'en'
                && $item['group'] === 'messages'
                && $item['key'] === 'welcome'
                && $item['value'] === 'Welcome!'
                && $item['is_active'] === true;
        });

        $this->assertEquals(1, $result['created']);
    }

    public function test_push_translation_uses_custom_client_when_provided(): void
    {
        Http::fake([
            '*/api/v1/translation' => Http::response(['created' => 1, 'updated' => 0], 201),
        ]);

        $this->client->pushTranslation('en', 'messages', 'key', 'value', 'frontend');

        Http::assertSent(function ($request) {
            $data = $request->data();
            return ($data['translations'][0]['client'] ?? null) === 'frontend';
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // importFromFiles
    // ─────────────────────────────────────────────────────────────────────────

    public function test_import_from_files_reads_php_lang_files_and_pushes_them(): void
    {
        $tmpDir = sys_get_temp_dir() . '/tc-test-' . uniqid();
        mkdir("{$tmpDir}/en", 0777, true);
        file_put_contents("{$tmpDir}/en/messages.php", "<?php\nreturn ['welcome' => 'Hello', 'bye' => 'Goodbye'];");

        Http::fake([
            '*/api/v1/translation' => Http::response(['created' => 2, 'updated' => 0], 201),
        ]);

        $result = $this->client->importFromFiles('en', $tmpDir);

        $this->assertEquals(2, $result['created']);

        @unlink("{$tmpDir}/en/messages.php");
        @rmdir("{$tmpDir}/en");
        @rmdir($tmpDir);
    }

    public function test_import_from_files_flattens_nested_arrays(): void
    {
        $tmpDir = sys_get_temp_dir() . '/tc-test-nested-' . uniqid();
        mkdir("{$tmpDir}/en", 0777, true);
        // 'section' has a mix of string AND an array sub-value, so isTranslatableArray returns false
        // and the recursive flatter is triggered, creating 'section.label' and 'section.sub' keys.
        file_put_contents(
            "{$tmpDir}/en/auth.php",
            "<?php\nreturn ['section' => ['label' => 'A Label', 'sub' => ['nested' => 'Deep Value']]];"
        );

        $pushed = [];
        Http::fake([
            '*/api/v1/translation' => function ($request) use (&$pushed) {
                $pushed = $request->data()['translations'];
                return Http::response(['created' => 2, 'updated' => 0], 201);
            },
        ]);

        $this->client->importFromFiles('en', $tmpDir);

        $keys = array_column($pushed, 'key');
        // 'section.label' is a string leaf → pushed
        $this->assertContains('section.label', $keys);
        // 'section.sub' is an all-string associative array → isTranslatableArray = true → leaf (not further flattened)
        $this->assertContains('section.sub', $keys);

        @unlink("{$tmpDir}/en/auth.php");
        @rmdir("{$tmpDir}/en");
        @rmdir($tmpDir);
    }

    public function test_import_from_files_returns_zeros_when_directory_is_empty(): void
    {
        $tmpDir = sys_get_temp_dir() . '/tc-test-empty-' . uniqid();
        mkdir("{$tmpDir}/en", 0777, true);

        Http::fake();

        $result = $this->client->importFromFiles('en', $tmpDir);

        $this->assertEquals(0, $result['created']);
        $this->assertEquals(0, $result['updated']);

        @rmdir("{$tmpDir}/en");
        @rmdir($tmpDir);
    }

    public function test_import_from_files_skips_numeric_keys(): void
    {
        $tmpDir = sys_get_temp_dir() . '/tc-test-numeric-' . uniqid();
        mkdir("{$tmpDir}/en", 0777, true);
        file_put_contents("{$tmpDir}/en/list.php", "<?php\nreturn ['title' => 'My List', 0 => 'skip me', 'desc' => 'Description'];");

        $pushed = [];
        Http::fake([
            '*/api/v1/translation' => function ($request) use (&$pushed) {
                $pushed = $request->data()['translations'];
                return Http::response(['created' => 2, 'updated' => 0], 201);
            },
        ]);

        $this->client->importFromFiles('en', $tmpDir);

        $keys = array_column($pushed, 'key');
        $this->assertNotContains('0', $keys);
        $this->assertContains('title', $keys);

        @unlink("{$tmpDir}/en/list.php");
        @rmdir("{$tmpDir}/en");
        @rmdir($tmpDir);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // App name prefix
    // ─────────────────────────────────────────────────────────────────────────

    public function test_app_name_prefix_is_applied_to_group_when_pushing(): void
    {
        $this->app['config']->set('translation-client.app_name_prefix', 'MYAPP');
        $this->app->forgetInstance(TranslationClient::class);
        $client = $this->app->make(TranslationClient::class);

        $pushed = [];
        Http::fake([
            '*/api/v1/translation' => function ($request) use (&$pushed) {
                $pushed = $request->data()['translations'];
                return Http::response(['created' => 1, 'updated' => 0], 201);
            },
        ]);

        $tmpDir = sys_get_temp_dir() . '/tc-test-prefix-' . uniqid();
        mkdir("{$tmpDir}/en", 0777, true);
        file_put_contents("{$tmpDir}/en/messages.php", "<?php\nreturn ['hi' => 'Hi'];");

        $client->importFromFiles('en', $tmpDir);

        $this->assertEquals('MYAPP:messages', $pushed[0]['group']);

        @unlink("{$tmpDir}/en/messages.php");
        @rmdir("{$tmpDir}/en");
        @rmdir($tmpDir);
    }
}


