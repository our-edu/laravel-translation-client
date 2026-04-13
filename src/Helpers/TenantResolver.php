<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class TenantResolver
{
    /**
     * Get tenant UUID for translation service
     * 
     * Priority:
     * 1. Authenticated user's tenant (if multi-tenant ready)
     * 2. Config value (TRANSLATION_TENANT_UUID)
     * 3. First tenant from database (fallback)
     */
    public static function resolve(): ?int
    {
        // 1. Try from authenticated user (if your app supports this)
        if (auth()->check() && method_exists(auth()->user(), 'tenant_id')) {
            return auth()->user()->tenant_id;
        }

        // 3. Fallback: Get first tenant from a database
        return static::getFirstTenant();
    }

    /**
     * Get the first tenant UUID from database
     * Cached for 1 hour to avoid repeated queries
     */
    public static function getFirstTenant(): ?int
    {
        $value = Cache::remember('translation_client:first_tenants', 3600, function () {
            try {
                // Try to get first tenant from tenants table
                $tenant = DB::table('tenants')
                    ->orderBy('created_at')
                    ->first();

                return $tenant?->id ?? null;
            } catch (\Exception $e) {
                // Table might not exist or query failed
                return null;
            }
        });
        return $value !== null ? (int) $value : null;
    }

    /**
     * Set tenant UUID dynamically (for multi-tenant apps)
     */
    public static function setTenant(?int $tenantId): void
    {
        config(['translation-client.tenant_id' => $tenantId]);
    }
}
