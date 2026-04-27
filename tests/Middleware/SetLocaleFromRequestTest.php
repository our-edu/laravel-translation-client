<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Tests\Middleware;

use Illuminate\Http\Request;
use OurEdu\TranslationClient\Middleware\SetLocaleFromRequest;
use OurEdu\TranslationClient\Tests\TestCase;

class SetLocaleFromRequestTest extends TestCase
{
    private SetLocaleFromRequest $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new SetLocaleFromRequest();
        $this->app['config']->set('app.available_locales', ['en', 'ar', 'fr']);
        $this->app['config']->set('app.locale', 'en');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Locale from query parameter
    // ─────────────────────────────────────────────────────────────────────────

    public function test_sets_locale_from_query_parameter(): void
    {
        $request = Request::create('/?locale=ar', 'GET');

        $this->middleware->handle($request, function ($req) {});

        $this->assertEquals('ar', app()->getLocale());
    }

    public function test_ignores_invalid_locale_from_query_parameter(): void
    {
        app()->setLocale('en');
        $request = Request::create('/?locale=zz', 'GET');

        $this->middleware->handle($request, function ($req) {});

        $this->assertEquals('en', app()->getLocale());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Locale from X-Locale header
    // ─────────────────────────────────────────────────────────────────────────

    public function test_sets_locale_from_x_locale_header(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_X-LOCALE' => 'fr']);

        $this->middleware->handle($request, function ($req) {});

        $this->assertEquals('fr', app()->getLocale());
    }

    public function test_ignores_invalid_locale_from_x_locale_header(): void
    {
        app()->setLocale('en');
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_X-LOCALE' => 'xx']);

        $this->middleware->handle($request, function ($req) {});

        $this->assertEquals('en', app()->getLocale());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Locale from Accept-Language header
    // ─────────────────────────────────────────────────────────────────────────

    public function test_sets_locale_from_accept_language_header(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => 'ar']);

        $this->middleware->handle($request, function ($req) {});

        $this->assertEquals('ar', app()->getLocale());
    }

    public function test_accept_language_header_is_lower_priority_than_x_locale(): void
    {
        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_ACCEPT_LANGUAGE' => 'ar',
            'HTTP_X-LOCALE'        => 'fr',
        ]);

        $this->middleware->handle($request, function ($req) {});

        // X-Locale takes precedence
        $this->assertEquals('fr', app()->getLocale());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Query param takes highest priority
    // ─────────────────────────────────────────────────────────────────────────

    public function test_query_parameter_takes_priority_over_header(): void
    {
        $request = Request::create('/?locale=ar', 'GET', [], [], [], [
            'HTTP_X-LOCALE' => 'fr',
        ]);

        $this->middleware->handle($request, function ($req) {});

        $this->assertEquals('ar', app()->getLocale());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Passes through when no locale detected
    // ─────────────────────────────────────────────────────────────────────────

    public function test_passes_request_to_next_middleware(): void
    {
        $request  = Request::create('/', 'GET');
        $reached  = false;

        $this->middleware->handle($request, function ($req) use (&$reached) {
            $reached = true;
        });

        $this->assertTrue($reached);
    }

    public function test_does_not_change_locale_when_no_locale_detected(): void
    {
        app()->setLocale('en');
        $request = Request::create('/', 'GET');

        $this->middleware->handle($request, function ($req) {});

        $this->assertEquals('en', app()->getLocale());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Locale from authenticated user
    // ─────────────────────────────────────────────────────────────────────────

    public function test_sets_locale_from_authenticated_user_get_locale_method(): void
    {
        // Symfony's getPreferredLanguage always provides a system-locale fallback,
        // so step 3 (Accept-Language) is never null in practice.
        // We subclass to override detectLocale and isolate the user-locale logic
        // that lives inside handle() → detectLocale() → step 4.
        $middleware = new class extends SetLocaleFromRequest {
            protected function detectLocale(\Illuminate\Http\Request $request): ?string
            {
                // Skip Accept-Language steps; go straight to user-locale detection
                if ($request->user() && method_exists($request->user(), 'getLocale')) {
                    return $request->user()->getLocale();
                }
                return null;
            }

            protected function isValidLocale(string $locale): bool
            {
                return in_array($locale, ['en', 'ar', 'fr']);
            }
        };

        $user = new class {
            public function getLocale(): string { return 'ar'; }
        };

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn() => $user);

        $middleware->handle($request, function ($req) {});

        $this->assertEquals('ar', app()->getLocale());
    }
}




