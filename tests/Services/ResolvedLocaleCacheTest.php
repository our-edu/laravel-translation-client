<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Tests\Services;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OurEdu\TranslationClient\Services\TranslationClient;
use OurEdu\TranslationClient\Tests\TestCase;

/**
 * Bundles cache on the locale the service resolved, not the one asked for.
 *
 * §4.3 added `resolved_locale` to both API responses precisely so `?locale=ar`
 * and `?locale=ar-AE` stop colliding while holding different content. This
 * package never read the field, so it inherited the mirror image of that problem
 * one hop upstream: several requested tags negotiating to the *same* locale each
 * stored their own identical copy and refetched independently.
 *
 * The manifest stays keyed on the requested tag — it is the lookup that reveals
 * the resolved one, so it cannot be keyed on the answer.
 *
 * The fake is driven by the request rather than re-stubbed mid-test: `Http::fake()`
 * *merges* stub callbacks instead of replacing them, so a second call cannot
 * override the first and a test written that way silently asserts nothing.
 */
class ResolvedLocaleCacheTest extends TestCase
{
    /** requested locale => locale the service negotiates it to */
    private array $resolves = [];

    private int $version = 7;

    /** TenantResolver finds no authenticated user in tests, so keys are global. */
    private const TENANT = 'global';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->resolves = [];
        $this->version = 7;
    }

    /**
     * A service that negotiates per `$this->resolves` and reports `$this->version`.
     *
     * Opt-in rather than registered in setUp(), because `Http::fake()` merges
     * stubs: a catch-all registered first cannot be overridden by a test that
     * needs a different service, it just wins.
     */
    private function fakeNegotiatingService(): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $requested = $query['locale'] ?? '';
            $resolved = $this->resolves[$requested] ?? $requested;

            $body = [
                'tenant' => 1,
                'requested_locale' => $requested,
                'resolved_locale' => $resolved,
                'version' => $this->version,
            ];

            if (! str_contains($request->url(), '/manifest')) {
                $body['data'] = ['messages' => ['greeting' => "GREETING-{$resolved}"]];
            }

            return Http::response($body);
        });
    }

    private function bundleKey(string $resolved): string
    {
        return 'translation:bundle:' . self::TENANT . ":{$resolved}:messages:backend:flat";
    }

    private function cachedBundle(string $resolved): ?array
    {
        return Cache::tags(['translations', "locale:{$resolved}"])->get($this->bundleKey($resolved));
    }

    private function bundleRequests(): int
    {
        $count = 0;

        foreach (Http::recorded() as [$request]) {
            if (! str_contains($request->url(), '/manifest')) {
                $count++;
            }
        }

        return $count;
    }

    public function test_two_requested_tags_resolving_to_one_locale_share_a_bundle(): void
    {
        $this->resolves = ['ar' => 'ar-SA', 'ar-AE' => 'ar-SA'];
        $this->fakeNegotiatingService();

        $client = new TranslationClient();
        $first = $client->fetchBundle('ar', ['messages']);
        $second = $client->fetchBundle('ar-AE', ['messages']);

        $this->assertSame($first, $second);
        $this->assertSame(
            1,
            $this->bundleRequests(),
            'the second request resolves to the same locale, so it should hit the cached bundle'
        );
    }

    public function test_the_bundle_is_stored_under_the_resolved_tag(): void
    {
        $this->resolves = ['ar' => 'ar-SA'];
        $this->fakeNegotiatingService();

        (new TranslationClient())->fetchBundle('ar', ['messages']);

        $this->assertNotNull($this->cachedBundle('ar-SA'), 'the bundle belongs under the locale actually served');
        $this->assertNull(
            Cache::tags(['translations', 'locale:ar'])->get($this->bundleKey('ar')),
            'and not under the tag that was merely asked for'
        );
    }

    public function test_distinct_resolved_locales_do_not_share_a_bundle(): void
    {
        $this->resolves = ['ar' => 'ar-SA', 'en' => 'en-US'];
        $this->fakeNegotiatingService();

        $client = new TranslationClient();
        $client->fetchBundle('ar', ['messages']);
        $client->fetchBundle('en', ['messages']);

        $this->assertNotNull($this->cachedBundle('ar-SA'));
        $this->assertNotNull($this->cachedBundle('en-US'));
        $this->assertSame(2, $this->bundleRequests(), 'different locales are different content');
    }

    public function test_clearing_by_the_requested_tag_evicts_the_resolved_bundle(): void
    {
        // Callers know the tag they asked for, not the one negotiation picked,
        // so clearCache('ar') has to reach a bundle filed under ar-SA.
        $this->resolves = ['ar' => 'ar-SA'];
        $this->fakeNegotiatingService();

        $client = new TranslationClient();
        $client->fetchBundle('ar', ['messages']);
        $this->assertNotNull($this->cachedBundle('ar-SA'));

        $client->clearCache('ar');

        $this->assertNull($this->cachedBundle('ar-SA'), 'clearing by the requested tag should have evicted it');
    }

    public function test_clearing_everything_still_works(): void
    {
        $this->resolves = ['ar' => 'ar-SA'];
        $this->fakeNegotiatingService();

        $client = new TranslationClient();
        $client->fetchBundle('ar', ['messages']);

        $client->clearCache();

        $this->assertNull($this->cachedBundle('ar-SA'));
    }

    public function test_a_service_that_does_not_send_resolved_locale_still_works(): void
    {
        // An older service build, or one with TRANSLATION_REGIONAL_LOCALES off.
        Http::fake([
            '*/api/v1/translation/manifest*' => Http::response(['version' => 3]),
            '*/api/v1/translation*' => Http::response([
                'version' => 3,
                'data' => ['messages' => ['greeting' => 'PLAIN']],
            ]),
        ]);

        $result = (new TranslationClient())->fetchBundle('ar', ['messages']);

        $this->assertSame(['messages' => ['greeting' => 'PLAIN']], $result);
        $this->assertNotNull(
            $this->cachedBundle('ar'),
            'with no resolved_locale to read, the requested tag is the best answer available'
        );
    }

    public function test_the_offline_default_manifest_still_reports_a_locale(): void
    {
        // The moment the field matters most: anything depending on
        // resolved_locale must not get null exactly when the API is unreachable.
        Http::fake(['*' => Http::response('', 500)]);

        $manifest = (new TranslationClient())->checkVersion('ar-AE');

        $this->assertSame('ar-AE', $manifest['requested_locale']);
        $this->assertSame('ar-AE', $manifest['resolved_locale']);
        $this->assertSame(1, $manifest['version']);
    }

    public function test_a_version_bump_refetches_rather_than_serving_a_stale_bundle(): void
    {
        // The resolved locale is unchanged, so the cache key is too — the version
        // comparison is the only thing between a caller and stale content.
        $this->resolves = ['ar' => 'ar-SA'];
        $this->fakeNegotiatingService();

        $client = new TranslationClient();
        $this->assertSame(['messages' => ['greeting' => 'GREETING-ar-SA']], $client->fetchBundle('ar', ['messages']));

        $this->version = 8;
        Cache::tags(['translations', 'locale:ar'])->flush();   // expire the manifest only

        $client->fetchBundle('ar', ['messages']);

        $this->assertSame(2, $this->bundleRequests(), 'a newer version must not be served from the old bundle');
    }
}
