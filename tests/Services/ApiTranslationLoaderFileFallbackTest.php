<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Tests\Services;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use OurEdu\TranslationClient\Services\ApiTranslationLoader;
use OurEdu\TranslationClient\Services\TranslationClient;
use OurEdu\TranslationClient\Tests\TestCase;

/**
 * The lang-file layer has to survive regional tags.
 *
 * `loadFromFiles()` built its path straight from the locale, so `ar-SA` looked
 * for `lang/ar-SA/messages.php`. No consuming app ships that directory — they
 * ship `lang/ar/` — so the local layer returned nothing for every regional
 * locale.
 *
 * That is not merely a missed optimisation. `load()` merges the API result
 * *over* the file result, so the files are what supply any key the service does
 * not return. Losing them silently drops those keys.
 */
class ApiTranslationLoaderFileFallbackTest extends TestCase
{
    private string $langPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->langPath = sys_get_temp_dir() . '/translation-client-loader-tests/lang';
        File::deleteDirectory(dirname($this->langPath));
        File::ensureDirectoryExists($this->langPath . '/ar');
        File::put(
            $this->langPath . '/ar/messages.php',
            '<?php return ["from_file" => "FILE", "shared" => "FILE-SHARED"];'
        );

    }

    /**
     * Opt-in rather than set in setUp(): Http::fake() *merges* stub callbacks,
     * so a catch-all registered here would win over any test that needs the
     * service to answer differently.
     */
    private function fakeApi(array $data = []): void
    {
        Http::fake(['*' => Http::response(['version' => 1, 'data' => $data])]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->langPath));

        parent::tearDown();
    }

    private function loader(): ApiTranslationLoader
    {
        return new ApiTranslationLoader(
            new Filesystem(),
            $this->langPath,
            $this->app->make(TranslationClient::class)
        );
    }

    public function test_a_regional_tag_reads_its_language_directory(): void
    {
        $this->fakeApi();
        $loaded = $this->loader()->load('ar-SA', 'messages');

        $this->assertSame('FILE', $loaded['from_file'] ?? null);
    }

    public function test_a_bare_language_still_reads_its_own_directory(): void
    {
        $this->fakeApi();
        $loaded = $this->loader()->load('ar', 'messages');

        $this->assertSame('FILE', $loaded['from_file'] ?? null);
    }

    public function test_an_exact_regional_directory_wins_over_the_language_one(): void
    {
        $this->fakeApi();
        File::ensureDirectoryExists($this->langPath . '/ar-SA');
        File::put($this->langPath . '/ar-SA/messages.php', '<?php return ["from_file" => "SAUDI"];');

        $loaded = $this->loader()->load('ar-SA', 'messages');

        $this->assertSame('SAUDI', $loaded['from_file'] ?? null);
    }

    public function test_the_api_result_still_wins_over_the_file(): void
    {
        $this->fakeApi(['messages' => ['shared' => 'API-SHARED']]);

        $loaded = $this->loader()->load('ar-SA', 'messages');

        $this->assertSame('API-SHARED', $loaded['shared'] ?? null, 'the service is authoritative where it answers');
        $this->assertSame('FILE', $loaded['from_file'] ?? null, 'and the file supplies what it does not');
    }

    public function test_an_unknown_locale_yields_nothing_rather_than_erroring(): void
    {
        $this->fakeApi();
        $this->assertSame([], $this->loader()->load('fr-FR', 'messages'));
    }
}
