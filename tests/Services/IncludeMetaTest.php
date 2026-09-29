<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Tests\Services;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OurEdu\TranslationClient\Services\TranslationClient;
use OurEdu\TranslationClient\Tests\TestCase;

/**
 * `include_meta` is a package-wide config switch, not a per-call option: it
 * mirrors the service's own `include_meta=1` bundle parameter (a debugging
 * aid the service caches as a distinct entry), off by default so normal
 * traffic never pays for it.
 */
class IncludeMetaTest extends TestCase
{
    private function fakeService(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/manifest')) {
                return Http::response(['version' => 1]);
            }

            return Http::response([
                'version' => 1,
                'data' => ['messages' => ['greeting' => 'HI']],
            ]);
        });
    }

    public function test_include_meta_is_left_out_of_the_request_by_default(): void
    {
        $this->fakeService();

        (new TranslationClient())->fetchBundle('en', ['messages']);

        Http::assertSent(function (Request $request) {
            if (str_contains($request->url(), '/manifest')) {
                return true;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ! array_key_exists('include_meta', $query);
        });
    }

    public function test_include_meta_is_sent_when_enabled(): void
    {
        $this->app['config']->set('translation-client.include_meta', true);
        $this->fakeService();

        (new TranslationClient())->fetchBundle('en', ['messages']);

        Http::assertSent(function (Request $request) {
            if (str_contains($request->url(), '/manifest')) {
                return true;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['include_meta'] ?? null) === '1';
        });
    }
}
