<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WidgetEmbedTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_js_file_exists_and_contains_shadow_dom_logic(): void
    {
        $widgetFilePath = public_path('widget.js');
        $this->assertFileExists($widgetFilePath);

        $content = file_get_contents($widgetFilePath);
        $this->assertStringContainsString('attachShadow', $content);
        $this->assertStringContainsString('jix-bubble-btn', $content);
        $this->assertStringContainsString('window.JixChatConfig', $content);
        $this->assertStringContainsString('AudioContext', $content);
    }

    public function test_demo_route_redirects_with_active_property_key(): void
    {
        $tenant = Tenant::create([
            'name' => 'Demo Tenant',
            'email' => 'demo@example.com',
        ]);

        $property = Property::create([
            'tenant_id' => $tenant->id,
            'site_name' => 'ChatBox Cloud',
            'site_url' => 'https://chatbox.com',
            'public_key' => 'pk_live_custom_demo123456',
            'secret_key' => 'sk_live_custom_demo123456',
            'is_active' => true,
        ]);

        $response = $this->get('/demo');
        $response->assertStatus(302);
        $response->assertRedirect('/demo.html?pk=' . $property->public_key);
    }
}
