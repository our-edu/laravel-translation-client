<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocaleFromRequest
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $locale = $this->matchLocale($this->detectLocale($request));

        if ($locale !== null) {
            app()->setLocale($locale);
        }

        return $next($request);
    }

    /**
     * Detect locale from request
     */
    protected function detectLocale(Request $request): ?string
    {
        // 1. Check query parameter
        if ($locale = $request->query('locale')) {
            return $locale;
        }

        // 2. Check header
        if ($locale = $request->header('X-Locale')) {
            return $locale;
        }

        // 3. Check Accept-Language header
        if ($locale = $request->getPreferredLanguage($this->getAvailableLocales())) {
            return $locale;
        }

        // 4. Check authenticated user preference
        if ($request->user() && method_exists($request->user(), 'getLocale')) {
            return $request->user()->getLocale();
        }

        if ($request->route() && $request->route()->hasParameter('language')) {
            return $request->route()->parameter('language');
        }

        return null;
    }

    /**
     * The available locale a request should be served, or null to leave the
     * application locale alone.
     *
     * Exact match first. Failing that, match on the **language**: a client
     * asking for `ar`, or for a variant this app does not serve like `ar-EG`,
     * is served the variant that is configured for Arabic. That mirrors how the
     * service negotiates — the request's language selects, the configuration
     * decides which variant — and it is what stops a config listing only
     * regional tags from rejecting every bare `ar` and `en`.
     */
    protected function matchLocale(?string $locale): ?string
    {
        if ($locale === null || $locale === '') {
            return null;
        }

        $requested = $this->normalize($locale);
        $available = $this->getAvailableLocales();

        foreach ($available as $candidate) {
            if ($this->normalize($candidate) === $requested) {
                return $candidate;
            }
        }

        $language = $this->languageOf($requested);

        foreach ($available as $candidate) {
            if ($this->languageOf($this->normalize($candidate)) === $language) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Check if locale is valid
     */
    protected function isValidLocale(string $locale): bool
    {
        return $this->matchLocale($locale) !== null;
    }

    /**
     * Get available locales from config
     *
     * Reads this package's own key first. The previous implementation read only
     * `app.available_locales`, which is **not a standard Laravel config key** —
     * unless a consuming app had defined one itself, the default collapsed to
     * `[config('app.locale')]` and every locale but the app default was
     * silently rejected, regional or otherwise.
     *
     * `app.available_locales` is still merged in, so apps that do define it keep
     * working.
     */
    protected function getAvailableLocales(): array
    {
        $locales = array_values(array_unique(array_merge(
            (array) config('translation-client.available_locales', []),
            (array) config('app.available_locales', []),
        )));

        return $locales !== [] ? $locales : [config('app.locale', 'en')];
    }

    /**
     * Canonical casing: `AR_eg` and `ar-eg` are both `ar-EG`.
     */
    protected function normalize(string $locale): string
    {
        $parts = preg_split('/[-_]/', trim($locale), 2);
        $language = strtolower($parts[0]);

        return isset($parts[1]) && $parts[1] !== ''
            ? $language . '-' . strtoupper($parts[1])
            : $language;
    }

    protected function languageOf(string $locale): string
    {
        return explode('-', $locale, 2)[0];
    }
}
