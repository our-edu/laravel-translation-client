<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array checkVersion(string $locale, ?string $client = null)
 * @method static array fetchBundle(string $locale, ?array $groups = null, ?string $client = null, string $format = 'flat')
 * @method static array loadTranslations(string $locale)
 * @method static void clearCache(?string $locale = null)
 * @method static array pushTranslations(array $translations)
 * @method static array pushTranslation(string $locale, string $group, string $key, string $value, ?string $client = null, bool $isActive = true)
 * @method static array importFromFiles(string $locale, string $langPath)
 *
 * @see \OurEdu\TranslationClient\Services\TranslationClient
 */
class Translation extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return \OurEdu\TranslationClient\Services\TranslationClient::class;
    }
}
