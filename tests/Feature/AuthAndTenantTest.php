<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAndTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_registration_creates_tenant_owner_property_and_token(): void
    {
        $payload = [
            'company_name' => 'Acme Hosting Ltd',
            'name' => 'John Doe',
            'email' => 'john@acme.com',
            'password' => 'secretPassword123',
            'site_name' => 'Acme Cloud Portal',
            'site_url' => 'https://acmecloud.io',
        ];

        $response = $this->postJson('/api/v1/auth/register-tenant', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email', 'role', 'tenant_id'],
                    'tenant' => ['id', 'name', 'plan', 'status'],
                    'property' => ['id', 'site_name', 'public_key', 'secret_key', 'widget_config'],
                    'token',
                ],
            ]);

        $this->assertDatabaseHas('tenants', ['name' => 'Acme Hosting Ltd', 'email' => 'john@acme.com']);
        $this->assertDatabaseHas('users', ['email' => 'john@acme.com', 'role' => 'admin']);
        $this->assertDatabaseHas('properties', ['site_name' => 'Acme Cloud Portal']);
        $this->assertDatabaseHas('departments', ['name' => 'Support']);
        $this->assertDatabaseHas('canned_responses', ['shortcut' => '/greet']);

        $publicKey = $response->json('data.property.public_key');
        $this->assertStringStartsWith('pk_live_', $publicKey);
    }

    public function test_user_can_login_and_retrieve_profile(): void
    {
        // First register
        $register = $this->postJson('/api/v1/auth/register-tenant', [
            'company_name' => 'Delta Systems',
            'name' => 'Alice Smith',
            'email' => 'alice@delta.com',
            'password' => 'deltaPass123',
        ]);

        $register->assertStatus(201);

        // Then login
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'alice@delta.com',
            'password' => 'deltaPass123',
        ]);

        $loginResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $token = $loginResponse->json('data.token');
        $this->assertNotEmpty($token);

        // Fetch /me
        $meResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me');

        $meResponse->assertStatus(200)
            ->assertJsonPath('data.user.email', 'alice@delta.com')
            ->assertJsonPath('data.user.role', 'admin');
    }

    public function test_admin_can_invite_agents_under_same_tenant(): void
    {
        $register = $this->postJson('/api/v1/auth/register-tenant', [
            'company_name' => 'TechCorp',
            'name' => 'Tech Admin',
            'email' => 'owner@techcorp.io',
            'password' => 'password123',
        ]);

        $token = $register->json('data.token');
        $tenantId = $register->json('data.tenant.id');

        $agentResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/agents', [
                'name' => 'Agent Alex',
                'email' => 'alex@techcorp.io',
                'password' => 'agentPassword123',
            ]);

        $agentResponse->assertStatus(201)
            ->assertJsonPath('data.role', 'agent')
            ->assertJsonPath('data.tenant_id', $tenantId);

        $this->assertDatabaseHas('users', [
            'email' => 'alex@techcorp.io',
            'role' => 'agent',
            'tenant_id' => $tenantId,
        ]);
    }

    public function test_admin_can_create_property_and_regenerate_keys(): void
    {
        $register = $this->postJson('/api/v1/auth/register-tenant', [
            'company_name' => 'E-Commerce Hub',
            'name' => 'Shop Owner',
            'email' => 'shop@echub.com',
            'password' => 'password123',
        ]);

        $token = $register->json('data.token');

        // Create secondary property
        $propResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/properties', [
                'site_name' => 'Store Europe',
                'site_url' => 'https://eu.echub.com',
                'allowed_domains' => ['eu.echub.com'],
            ]);

        $propResponse->assertStatus(201)
            ->assertJsonPath('data.site_name', 'Store Europe');

        $propertyId = $propResponse->json('data.id');
        $oldPublicKey = $propResponse->json('data.public_key');

        // Regenerate keys
        $regenResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/properties/{$propertyId}/regenerate-keys");

        $regenResponse->assertStatus(200);
        $newPublicKey = $regenResponse->json('data.public_key');

        $this->assertNotEquals($oldPublicKey, $newPublicKey);
        $this->assertStringStartsWith('pk_live_', $newPublicKey);
    }
}
