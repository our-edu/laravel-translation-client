<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Tests\Console;

use Illuminate\Support\Facades\Http;
use OurEdu\TranslationClient\Services\TranslationClient;
use OurEdu\TranslationClient\Tests\TestCase;

class ImportNamespacedTranslationsCommandTest extends TestCase
{
    private string $tmpBasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpBasePath = sys_get_temp_dir() . '/ns-import-test-' . uniqid();
        mkdir($this->tmpBasePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpBasePath);
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Happy path
    // ─────────────────────────────────────────────────────────────────────────

    public function test_import_namespaced_command_processes_lang_directories(): void
    {
        // Create: {base}/MyModule/Lang/en/messages.php
        $langDir = "{$this->tmpBasePath}/MyModule/Lang";
        mkdir("{$langDir}/en", 0777, true);
        file_put_contents("{$langDir}/en/messages.php", "<?php\nreturn ['title' => 'Module Title'];");

        Http::fake([
            '*/api/v1/translation' => Http::response(['created' => 1, 'updated' => 0], 201),
        ]);

        $this->artisan("translations:import-namespaced --path={$this->tmpBasePath} --pattern=*/Lang")
            ->assertExitCode(0)
            ->expectsOutputToContain('Created: 1');
    }

    public function test_import_namespaced_command_uses_module_name_as_namespace(): void
    {
        $langDir = "{$this->tmpBasePath}/Orders/Lang";
        mkdir("{$langDir}/en", 0777, true);
        file_put_contents("{$langDir}/en/status.php", "<?php\nreturn ['pending' => 'Pending'];");

        $pushed = [];
        Http::fake([
            '*/api/v1/translation' => function ($request) use (&$pushed) {
                $pushed = $request->data()['translations'] ?? [];
                return Http::response(['created' => 1, 'updated' => 0], 201);
            },
        ]);

        $this->artisan("translations:import-namespaced --path={$this->tmpBasePath} --pattern=*/Lang");

        $group = $pushed[0]['group'] ?? '';
        $this->assertStringContainsString('Orders::status', $group);
    }

    public function test_import_namespaced_command_filters_by_locale(): void
    {
        $langDir = "{$this->tmpBasePath}/Auth/Lang";
        mkdir("{$langDir}/en", 0777, true);
        mkdir("{$langDir}/ar", 0777, true);
        file_put_contents("{$langDir}/en/messages.php", "<?php\nreturn ['login' => 'Login'];");
        file_put_contents("{$langDir}/ar/messages.php", "<?php\nreturn ['login' => 'تسجيل دخول'];");

        $requestedLocales = [];
        Http::fake([
            '*/api/v1/translation' => function ($request) use (&$requestedLocales) {
                foreach ($request->data()['translations'] as $t) {
                    $requestedLocales[] = $t['locale'];
                }
                return Http::response(['created' => 1, 'updated' => 0], 201);
            },
        ]);

        $this->artisan("translations:import-namespaced --path={$this->tmpBasePath} --pattern=*/Lang --locale=en");

        $this->assertContains('en', $requestedLocales);
        $this->assertNotContains('ar', $requestedLocales);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Failure cases
    // ─────────────────────────────────────────────────────────────────────────

    public function test_import_namespaced_command_fails_when_base_path_missing(): void
    {
        $this->artisan('translations:import-namespaced --path=/nonexistent/path')
            ->assertExitCode(1)
            ->expectsOutputToContain('Base path not found');
    }

    public function test_import_namespaced_command_fails_when_no_lang_dirs_found(): void
    {
        // Base dir exists but has no matching Lang subdirectory
        $this->artisan("translations:import-namespaced --path={$this->tmpBasePath}")
            ->assertExitCode(1)
            ->expectsOutputToContain('No Lang directories found');
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

