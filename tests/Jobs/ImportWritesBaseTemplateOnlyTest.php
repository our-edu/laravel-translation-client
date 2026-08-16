<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Tests\Jobs;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use OurEdu\TranslationClient\Jobs\ImportNamespacedTranslationsJob;
use OurEdu\TranslationClient\Jobs\ImportTranslationsJob;
use OurEdu\TranslationClient\Services\TranslationClient;
use OurEdu\TranslationClient\Tests\TestCase;

/**
 * Lang-file imports write the base template and nothing else.
 *
 * Both jobs used to push globally and then fan out, dispatching a per-tenant job
 * for every row in the consuming app's `tenants` table that re-pushed identical
 * content with `tenant_id` set. That is the client-side instance of the physical
 * copy model the service replaced with inheritance: a tenant with no override
 * already reads the base template, so the copy adds nothing — and once the tenant
 * moves to a regional variant the chain has no `tenant + <bare language>` step,
 * so the copy is not merely redundant but unreachable.
 *
 * These pin the shape rather than the implementation: exactly one push, always at
 * `tenant_id = null`, and no follow-up jobs queued.
 */
class ImportWritesBaseTemplateOnlyTest extends TestCase
{
    private string $langPath;

    /** @var list<array{translations: array, tenantId: ?int}> */
    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->langPath = sys_get_temp_dir() . '/translation-client-tests/lang';
        File::ensureDirectoryExists($this->langPath . '/en');
        File::put($this->langPath . '/en/messages.php', '<?php return ["greeting" => "Hello"];');

        $this->pushes = [];
        $this->app->instance(TranslationClient::class, $this->recordingClient());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->langPath));

        parent::tearDown();
    }

    /**
     * A TranslationClient that records pushes instead of making HTTP calls.
     */
    private function recordingClient(): TranslationClient
    {
        return new class($this->pushes) extends TranslationClient {
            public function __construct(private array &$pushes)
            {
                parent::__construct();
            }

            public function buildTranslationsFromFiles(string $locale, string $langPath): array
            {
                return [[
                    'locale' => $locale,
                    'group' => 'messages',
                    'key' => 'greeting',
                    'value' => 'Hello',
                    'client' => 'backend',
                    'is_active' => true,
                ]];
            }

            public function pushTranslationsForTenant(array $translations, ?int $tenantId): array
            {
                $this->pushes[] = ['translations' => $translations, 'tenantId' => $tenantId];

                return ['created' => count($translations), 'updated' => 0, 'total' => count($translations)];
            }

            public function pushTranslations(array $translations): array
            {
                $this->pushes[] = [
                    'translations' => $translations,
                    'tenantId' => $translations[0]['tenant_id'] ?? null,
                ];

                return ['created' => count($translations), 'updated' => 0, 'total' => count($translations)];
            }
        };
    }

    public function test_a_lang_file_import_pushes_once_and_only_to_the_template(): void
    {
        Bus::fake();

        (new ImportTranslationsJob($this->langPath, 'en'))
            ->handle($this->app->make(TranslationClient::class));

        $this->assertCount(1, $this->pushes, 'one push for the template, and no per-tenant repeats');
        $this->assertNull($this->pushes[0]['tenantId'], 'lang files are the template, never a tenant override');
    }

    public function test_a_lang_file_import_dispatches_no_follow_up_jobs(): void
    {
        Bus::fake();

        (new ImportTranslationsJob($this->langPath, 'en'))
            ->handle($this->app->make(TranslationClient::class));

        Bus::assertNothingDispatched();
    }

    public function test_a_namespaced_import_pushes_once_and_only_to_the_template(): void
    {
        Bus::fake();

        $basePath = sys_get_temp_dir() . '/translation-client-tests/modules';
        File::ensureDirectoryExists($basePath . '/Billing/Lang/en');
        File::put($basePath . '/Billing/Lang/en/messages.php', '<?php return ["greeting" => "Hello"];');

        (new ImportNamespacedTranslationsJob($basePath, '*/Lang', 'en'))
            ->handle($this->app->make(TranslationClient::class));

        $this->assertNotEmpty($this->pushes, 'the namespace should have been pushed');

        foreach ($this->pushes as $push) {
            $this->assertNull($push['tenantId'], 'no push may carry a tenant id');
        }

        Bus::assertNothingDispatched();
    }

    public function test_the_removed_per_tenant_jobs_are_gone(): void
    {
        // They had no other call sites, so leaving them would have left dead
        // classes that still look dispatchable.
        $this->assertFalse(class_exists(
            'OurEdu\TranslationClient\Jobs\ImportTenantTranslationsJob'
        ));
        $this->assertFalse(class_exists(
            'OurEdu\TranslationClient\Jobs\ImportTenantNamespacedTranslationsJob'
        ));
    }

    public function test_only_global_is_removed_rather_than_left_as_a_no_op(): void
    {
        // A flag that silently does nothing reads as though the other mode is
        // still available.
        foreach (['translations:import', 'translations:import-namespaced'] as $command) {
            $definition = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)
                ->all()[$command]->getDefinition();

            $this->assertFalse(
                $definition->hasOption('only-global'),
                "{$command} should no longer accept --only-global"
            );
        }
    }
}
