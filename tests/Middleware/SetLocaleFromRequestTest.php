<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Tests\Middleware;

use Illuminate\Http\Request;
use OurEdu\TranslationClient\Middleware\SetLocaleFromRequest;
use OurEdu\TranslationClient\Tests\TestCase;

/**
 * Locale detection has to understand regional tags.
 *
 * `getAvailableLocales()` read `config('app.available_locales')`, which is **not
 * a standard Laravel key**. Unless a consuming app happened to define one, the
 * default collapsed to `[config('app.locale')]` and every locale but the app
 * default was rejected — so the middleware had effectively never worked for a
 * second language, let alone a regional variant of one.
 */
class SetLocaleFromRequestTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('app.locale', 'en');
        $app['config']->set('translation-client.available_locales', ['ar-SA', 'en-US']);
        $app['config']->offsetUnset('app.available_locales');
    }

    private function localeFor(string $requested): string
    {
        $request = Request::create('/', 'GET', ['locale' => $requested]);

        (new SetLocaleFromRequest())->handle($request, fn () => response(''));

        return app()->getLocale();
    }

    public function test_an_exactly_configured_regional_tag_is_honoured(): void
    {
        $this->assertSame('ar-SA', $this->localeFor('ar-SA'));
    }

    public function test_casing_and_underscores_are_normalised(): void
    {
        $this->assertSame('ar-SA', $this->localeFor('AR_sa'));
        $this->assertSame('ar-SA', $this->localeFor('ar-sa'));
    }

    public function test_a_bare_language_maps_to_the_configured_variant(): void
    {
        // Without this, listing only regional tags in config would reject every
        // plain `ar` — a regression, not a migration.
        $this->assertSame('ar-SA', $this->localeFor('ar'));
        $this->assertSame('en-US', $this->localeFor('en'));
    }

    public function test_an_unserved_variant_falls_back_to_the_configured_one(): void
    {
        // The request's language selects; configuration decides the variant.
        // Mirrors how the service negotiates an unassigned tag.
        $this->assertSame('ar-SA', $this->localeFor('ar-EG'));
        $this->assertSame('en-US', $this->localeFor('en-AU'));
    }

    public function test_an_unknown_language_leaves_the_application_locale_alone(): void
    {
        $this->assertSame('en', $this->localeFor('fr-FR'));
    }

    public function test_an_app_defined_available_locales_list_still_works(): void
    {
        // Apps that had defined the non-standard app.available_locales must not
        // break; the two lists are merged rather than one replacing the other.
        config()->set('app.available_locales', ['fr']);

        $this->assertSame('fr', $this->localeFor('fr'));
        $this->assertSame('ar-SA', $this->localeFor('ar'));
    }

    public function test_the_x_locale_header_is_honoured_too(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('X-Locale', 'ar-SA');

        (new SetLocaleFromRequest())->handle($request, fn () => response(''));

        $this->assertSame('ar-SA', app()->getLocale());
    }
}
