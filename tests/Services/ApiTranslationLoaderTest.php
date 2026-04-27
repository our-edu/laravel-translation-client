<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Tests\Services;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use OurEdu\TranslationClient\Services\ApiTranslationLoader;
use OurEdu\TranslationClient\Services\TranslationClient;
use OurEdu\TranslationClient\Tests\TestCase;

class ApiTranslationLoaderTest extends TestCase
{
    private string $tmpLangPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpLangPath = sys_get_temp_dir() . '/loader-test-' . uniqid();
        mkdir($this->tmpLangPath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpLangPath);
        parent::tearDown();
    }

    private function makeLoader(?TranslationClient $client = null): ApiTranslationLoader
    {
        $client = $client ?? $this->app->make(TranslationClient::class);
        return new ApiTranslationLoader(
            new Filesystem(),
            $this->tmpLangPath,
            $client
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // load()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_load_returns_translations_from_api(): void
    {
        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response(['version' => 1], 200),
            '*/api/v1/translation*'          => Http::response([
                'data'    => ['messages' => ['welcome' => 'Hello', 'bye' => 'Goodbye']],
                'version' => 1,
                'count'   => 2,
            ], 200),
        ]);

        $result = $this->makeLoader()->load('en', 'messages');

        $this->assertEquals(['welcome' => 'Hello', 'bye' => 'Goodbye'], $result);
    }

    public function test_load_falls_back_to_file_when_api_returns_empty(): void
    {
        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response(['version' => 1], 200),
            '*/api/v1/translation*'          => Http::response(['data' => [], 'version' => 1], 200),
        ]);

        // Create a lang file to fall back to
        mkdir("{$this->tmpLangPath}/en", 0777, true);
        file_put_contents("{$this->tmpLangPath}/en/messages.php", "<?php\nreturn ['fallback' => 'From file'];");

        $result = $this->makeLoader()->load('en', 'messages');

        $this->assertEquals(['fallback' => 'From file'], $result);
    }

    public function test_load_returns_empty_array_when_api_and_file_both_empty(): void
    {
        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response(['version' => 1], 200),
            '*/api/v1/translation*'          => Http::response(['data' => [], 'version' => 1], 200),
        ]);

        $result = $this->makeLoader()->load('en', 'nonexistent');

        $this->assertSame([], $result);
    }

    public function test_load_with_namespace_uses_namespace_group_format(): void
    {
        $receivedGroups = null;

        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response(['version' => 1], 200),
            '*/api/v1/translation*'          => function ($request) use (&$receivedGroups) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $receivedGroups = $query['groups'] ?? null;
                return Http::response(['data' => [], 'version' => 1], 200);
            },
        ]);

        $this->makeLoader()->load('en', 'messages', 'MyPlugin');

        $this->assertStringContainsString('MyPlugin::messages', $receivedGroups);
    }

    public function test_load_ignores_wildcard_namespace(): void
    {
        $receivedGroups = null;

        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response(['version' => 1], 200),
            '*/api/v1/translation*'          => function ($request) use (&$receivedGroups) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $receivedGroups = $query['groups'] ?? null;
                return Http::response(['data' => [], 'version' => 1], 200);
            },
        ]);

        $this->makeLoader()->load('en', 'messages', '*');

        // Wildcard namespace should be treated as no namespace
        $this->assertEquals('messages', $receivedGroups);
    }

    public function test_load_applies_app_name_prefix_to_response_key_lookup(): void
    {
        $this->app['config']->set('translation-client.app_name_prefix', 'PORTAL');
        $this->app->forgetInstance(TranslationClient::class);
        $client = $this->app->make(TranslationClient::class);

        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response(['version' => 1], 200),
            '*/api/v1/translation*'          => Http::response([
                'data'    => ['PORTAL:messages' => ['greeting' => 'Hi!']],
                'version' => 1,
                'count'   => 1,
            ], 200),
        ]);

        $loader = new ApiTranslationLoader(new Filesystem(), $this->tmpLangPath, $client);
        $result = $loader->load('en', 'messages');

        $this->assertEquals(['greeting' => 'Hi!'], $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Namespace management
    // ─────────────────────────────────────────────────────────────────────────

    public function test_add_namespace_registers_namespace_hint(): void
    {
        $loader = $this->makeLoader();
        $loader->addNamespace('MyPlugin', '/path/to/plugin/lang');

        $this->assertArrayHasKey('MyPlugin', $loader->namespaces());
        $this->assertEquals('/path/to/plugin/lang', $loader->namespaces()['MyPlugin']);
    }

    public function test_namespaces_returns_empty_array_by_default(): void
    {
        $loader = $this->makeLoader();

        $this->assertSame([], $loader->namespaces());
    }

    public function test_add_json_path_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->makeLoader()->addJsonPath('/some/path');
    }

    public function test_load_with_registered_namespace_falls_back_to_namespace_hint_path(): void
    {
        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response(['version' => 1], 200),
            '*/api/v1/translation*'          => Http::response(['data' => [], 'version' => 1], 200),
        ]);

        // Create a file in a custom namespace path
        $customPath = sys_get_temp_dir() . '/ns-test-' . uniqid();
        mkdir("{$customPath}/en", 0777, true);
        file_put_contents("{$customPath}/en/views.php", "<?php\nreturn ['title' => 'My Title'];");

        $loader = $this->makeLoader();
        $loader->addNamespace('MyPlugin', $customPath);

        $result = $loader->load('en', 'views', 'MyPlugin');

        $this->assertEquals(['title' => 'My Title'], $result);

        @unlink("{$customPath}/en/views.php");
        @rmdir("{$customPath}/en");
        @rmdir($customPath);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────────────────────

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}

