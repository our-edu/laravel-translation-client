<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TenantResolver
{
    /**
     * Get tenant UUID for translation service
     *
     * Priority:
     * 1. Authenticated user's tenant (if multi-tenant ready)
     * 2. tenant Context from our-edu multi tenant package
     */
    public static function resolve(): ?int
    {
        $user = auth()->user();
        // 1. Try from authenticated user (if your app supports this)
        if (auth()->check() && !is_null($user->tenant_id)) {
            return (int) $user->tenant_id;
        }

        if (class_exists('Ouredu\MultiTenant\Tenancy\TenantContext')){
            $tenantId = app('Ouredu\MultiTenant\Tenancy\TenantContext')->getTenantId();
            return !is_null($tenantId) ? (int) $tenantId : null;
        }

        return null;
    }

    /**
     * Get all tenant IDs from the tenants table.
     * Used by CLI import commands to push translations to every tenant.
     * No Tenant model exists in this service, so the DB facade is used directly.
     */
    public static function getAllTenantIds(): array
    {
        try {
            return DB::table('tenants')->orderBy('id')->pluck('id')->all();
        } catch (\Exception $e) {
            Log::error('[TenantResolver] Failed to fetch tenant ids: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Set tenant UUID dynamically (for multi-tenant apps)
     */
    public static function setTenant(?int $tenantId): void
    {
        config(['translation-client.tenant_id' => $tenantId]);
    }
}
