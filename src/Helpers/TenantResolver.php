<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Helpers;

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
     * Set tenant UUID dynamically (for multi-tenant apps)
     */
    public static function setTenant(?int $tenantId): void
    {
        config(['translation-client.tenant_id' => $tenantId]);
    }
}
