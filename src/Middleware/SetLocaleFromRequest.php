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
        $locale = $this->detectLocale($request);

        if ($locale && $this->isValidLocale($locale)) {
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

        return null;
    }

    /**
     * Check if locale is valid
     */
    protected function isValidLocale(string $locale): bool
    {
        return in_array($locale, $this->getAvailableLocales());
    }

    /**
     * Get available locales from config
     */
    protected function getAvailableLocales(): array
    {
        return config('app.available_locales', [config('app.locale', 'en')]);
    }
}
