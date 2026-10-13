<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Apex Cloud Inc',
            'email' => 'admin@apexcloud.io',
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Apex Admin',
            'email' => 'admin@apexcloud.io',
            'password' => Hash::make('password123'),
            'role' => 'admin',
        ]);

        $this->property = Property::create([
            'tenant_id' => $this->tenant->id,
            'site_name' => 'Apex Portal',
            'site_url' => 'https://apexcloud.io',
            'public_key' => 'pk_live_apex_1234567890',
            'secret_key' => 'sk_live_apex_1234567890',
            'is_active' => true,
        ]);
    }

    public function test_dashboard_page_loads_successfully(): void
    {
        $response = $this->get('/dashboard');
        $response->assertStatus(200);
        $response->assertSee('ChatBox Workspace');
    }

    public function test_authenticated_user_can_fetch_stats(): void
    {
        $token = $this->admin->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/chat/stats');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'total_conversations',
                    'open_conversations',
                    'unassigned_conversations',
                    'closed_conversations',
                    'total_visitors',
                    'online_agents',
                ],
            ]);
    }

    public function test_admin_can_manage_departments_and_canned_responses(): void
    {
        $token = $this->admin->createToken('test-token')->plainTextToken;

        // 1. Add Department
        $deptRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/properties/{$this->property->id}/departments", [
                'name' => 'Billing & Accounts',
            ]);

        $deptRes->assertStatus(201)
            ->assertJsonPath('data.name', 'Billing & Accounts');

        $deptId = $deptRes->json('data.id');
        $this->assertDatabaseHas('departments', ['id' => $deptId, 'name' => 'Billing & Accounts']);

        // 2. Add Canned Response
        $cannedRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/properties/{$this->property->id}/canned-responses", [
                'shortcut' => 'refund',
                'content' => 'Refunds are processed within 3-5 business days.',
            ]);

        $cannedRes->assertStatus(201)
            ->assertJsonPath('data.shortcut', '/refund');

        $this->assertDatabaseHas('canned_responses', ['shortcut' => '/refund']);
    }
}
