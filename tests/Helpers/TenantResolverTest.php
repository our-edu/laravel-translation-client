<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Tests\Helpers;

use Illuminate\Support\Facades\Cache;
use OurEdu\TranslationClient\Helpers\TenantResolver;
use OurEdu\TranslationClient\Tests\TestCase;

class TenantResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Remove any cached first-tenant value
        Cache::forget('translation_client:first_tenants');
        // Remove tenant_id from config so tests can control the value
        $this->app['config']->set('translation-client.tenant_id', null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // resolve()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_resolve_returns_tenant_id_from_config(): void
    {
        $this->app['config']->set('translation-client.tenant_id', 42);

        $tenantId = TenantResolver::resolve();

        $this->assertEquals(42, $tenantId);
    }

    public function test_resolve_casts_string_tenant_id_to_int(): void
    {
        $this->app['config']->set('translation-client.tenant_id', '99');

        $tenantId = TenantResolver::resolve();

        $this->assertSame(99, $tenantId);
    }

    public function test_resolve_returns_null_when_no_config_and_no_database(): void
    {
        // No config, no DB table → getFirstTenant() should catch the exception and return null
        $this->app['config']->set('translation-client.tenant_id', null);

        $tenantId = TenantResolver::resolve();

        $this->assertNull($tenantId);
    }

    public function test_resolve_returns_authenticated_user_tenant_id_first(): void
    {
        $this->app['config']->set('translation-client.tenant_id', 99);

        $user = new class implements \Illuminate\Contracts\Auth\Authenticatable {
            public int $tenant_id = 7;

            public function getAuthIdentifierName(): string  { return 'id'; }
            public function getAuthIdentifier(): mixed       { return 1; }
            public function getAuthPassword(): string        { return ''; }
            public function getAuthPasswordName(): string    { return 'password'; }
            public function getRememberToken(): ?string      { return null; }
            public function setRememberToken($value): void   {}
            public function getRememberTokenName(): string   { return ''; }
        };

        $this->actingAs($user);

        $tenantId = TenantResolver::resolve();

        $this->assertSame(7, $tenantId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // setTenant()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_set_tenant_updates_config_value(): void
    {
        TenantResolver::setTenant(55);

        $this->assertEquals(55, config('translation-client.tenant_id'));
    }

    public function test_set_tenant_accepts_null(): void
    {
        TenantResolver::setTenant(null);

        $this->assertNull(config('translation-client.tenant_id'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getFirstTenant()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_get_first_tenant_returns_null_when_table_does_not_exist(): void
    {
        $result = TenantResolver::getFirstTenant();

        $this->assertNull($result);
    }

    public function test_get_first_tenant_result_is_cached(): void
    {
        // Pre-seed cache with a known value so we can verify it is read from cache
        Cache::put('translation_client:first_tenants', 123, 3600);

        $result = TenantResolver::getFirstTenant();

        // Should return the cached value, not null (DB fallback)
        $this->assertSame(123, $result);
    }
}



