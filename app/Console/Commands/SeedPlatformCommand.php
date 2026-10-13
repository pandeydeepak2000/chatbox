<?php

namespace App\Console\Commands;

use App\Models\Faq;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SeedPlatformCommand extends Command
{
    protected $signature = 'chatbox:seed-platform';
    protected $description = 'Seed both ChatBox platform and SaluteHost live properties with keys and FAQs';

    public function handle()
    {
        $this->info('1. Seeding ChatBox Platform Tenant...');
        $chatboxTenant = Tenant::firstOrCreate(
            ['name' => 'ChatBox Technologies'],
            [
                'email' => 'support@chatbox.live',
                'plan' => 'enterprise',
                'status' => 'active',
                'settings' => ['timezone' => 'Asia/Kolkata', 'language' => 'en']
            ]
        );

        $chatboxProp = Property::updateOrCreate(
            ['public_key' => 'pk_live_chatbox_FiTEeVrRTkfRAuGWIhjD'],
            [
                'tenant_id' => $chatboxTenant->id,
                'site_name' => 'ChatBox Live Support',
                'site_url' => 'https://chat.salutehost.com',
                'secret_key' => 'sk_live_chatbox_' . Str::random(24),
                'allowed_domains' => ['chat.salutehost.com', 'salutehost.com', 'localhost', '127.0.0.1'],
                'widget_config' => [
                    'primary_color' => '#2563eb',
                    'welcome_message' => 'Namaste! Welcome to ChatBox Support. How can we help you today?',
                    'position' => 'right',
                    'require_email' => true,
                    'require_name' => true,
                ],
                'is_active' => true,
            ]
        );
        $this->info("ChatBox Platform key verified: {$chatboxProp->public_key}");

        $this->info('2. Seeding SaluteHost Live Tenant...');
        $saluteTenant = Tenant::firstOrCreate(
            ['name' => 'SaluteHost'],
            [
                'email' => 'support@salutehost.com',
                'plan' => 'enterprise',
                'status' => 'active',
                'settings' => ['timezone' => 'Asia/Kolkata', 'language' => 'en']
            ]
        );

        $saluteProp = Property::updateOrCreate(
            ['public_key' => 'pk_live_salutehost_6eJtm9XjTJZ1GSOA'],
            [
                'tenant_id' => $saluteTenant->id,
                'site_name' => 'SaluteHost Web Portal',
                'site_url' => 'https://salutehost.com',
                'secret_key' => 'sk_live_salutehost_' . Str::random(24),
                'allowed_domains' => ['salutehost.com', 'chat.salutehost.com', 'localhost', '127.0.0.1'],
                'widget_config' => [
                    'primary_color' => '#2563eb',
                    'welcome_message' => 'Namaste! Welcome to SaluteHost 24/7 Support. How can we help you with servers, domains, or hosting today?',
                    'position' => 'right',
                    'require_email' => true,
                    'require_name' => true,
                ],
                'is_active' => true,
            ]
        );
        $this->info("SaluteHost Live key verified: {$saluteProp->public_key}");

        // Sync SaluteHost FAQs
        $this->call('salutehost:sync-faqs');

        $this->info('Platform seeding completed successfully!');
        return Command::SUCCESS;
    }
}
