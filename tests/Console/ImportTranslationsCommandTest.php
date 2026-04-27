<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Tests\Console;

use Illuminate\Support\Facades\Http;
use OurEdu\TranslationClient\Services\TranslationClient;
use OurEdu\TranslationClient\Tests\TestCase;

class ImportTranslationsCommandTest extends TestCase
{
    private string $tmpLangPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpLangPath = sys_get_temp_dir() . '/import-cmd-test-' . uniqid();
        mkdir("{$this->tmpLangPath}/en", 0777, true);
        mkdir("{$this->tmpLangPath}/ar", 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpLangPath);
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Happy path
    // ─────────────────────────────────────────────────────────────────────────

    public function test_import_command_succeeds_with_valid_lang_files(): void
    {
        file_put_contents("{$this->tmpLangPath}/en/messages.php", "<?php\nreturn ['hello' => 'Hello'];");

        Http::fake([
            '*/api/v1/translation' => Http::response(['created' => 1, 'updated' => 0], 201),
        ]);

        $this->artisan("translations:import --path={$this->tmpLangPath}")
            ->assertExitCode(0)
            ->expectsOutputToContain('Created: 1');
    }

    public function test_import_command_imports_only_specified_locale(): void
    {
        file_put_contents("{$this->tmpLangPath}/en/messages.php", "<?php\nreturn ['hi' => 'Hi'];");
        file_put_contents("{$this->tmpLangPath}/ar/messages.php", "<?php\nreturn ['hi' => 'مرحبا'];");

        $requestedLocales = [];

        Http::fake([
            '*/api/v1/translation' => function ($request) use (&$requestedLocales) {
                foreach ($request->data()['translations'] as $t) {
                    $requestedLocales[] = $t['locale'];
                }
                return Http::response(['created' => 1, 'updated' => 0], 201);
            },
        ]);

        $this->artisan("translations:import --path={$this->tmpLangPath} --locale=en")
            ->assertExitCode(0);

        $this->assertContains('en', $requestedLocales);
        $this->assertNotContains('ar', $requestedLocales);
    }

    public function test_import_command_fails_when_lang_directory_not_found(): void
    {
        $this->artisan('translations:import --path=/nonexistent/path')
            ->assertExitCode(1)
            ->expectsOutputToContain('Lang directory not found');
    }

    public function test_import_command_fails_when_no_locales_found(): void
    {
        // Empty dir with no subdirectories
        $emptyDir = sys_get_temp_dir() . '/import-empty-' . uniqid();
        mkdir($emptyDir, 0777, true);

        $this->artisan("translations:import --path={$emptyDir}")
            ->assertExitCode(1)
            ->expectsOutputToContain('No locales found');

        rmdir($emptyDir);
    }

    public function test_import_command_shows_summary_totals(): void
    {
        file_put_contents("{$this->tmpLangPath}/en/messages.php", "<?php\nreturn ['hello' => 'Hello', 'bye' => 'Bye'];");

        Http::fake([
            '*/api/v1/translation' => Http::response(['created' => 2, 'updated' => 0], 201),
        ]);

        $this->artisan("translations:import --path={$this->tmpLangPath} --locale=en")
            ->assertExitCode(0)
            ->expectsOutputToContain('Total Created: 2');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Error handling
    // ─────────────────────────────────────────────────────────────────────────

    public function test_import_command_reports_failure_when_api_throws(): void
    {
        file_put_contents("{$this->tmpLangPath}/en/messages.php", "<?php\nreturn ['hello' => 'Hello'];");

        $client = $this->getMockBuilder(TranslationClient::class)
            ->onlyMethods(['importFromFiles'])
            ->getMock();

        $client->expects($this->once())
            ->method('importFromFiles')
            ->willThrowException(new \Exception('API is down'));

        $this->app->instance(TranslationClient::class, $client);

        $this->artisan("translations:import --path={$this->tmpLangPath} --locale=en")
            ->assertExitCode(1)
            ->expectsOutputToContain('Failed');
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

