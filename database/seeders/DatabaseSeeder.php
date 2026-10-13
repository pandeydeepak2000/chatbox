<?php

namespace Database\Seeders;

use App\Models\CannedResponse;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Faq;
use App\Models\Message;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database idempotently (safe to re-run).
     */
    public function run(): void
    {
        // 1. Super Administrator (Platform Level)
        $superAdmin = User::firstOrCreate(
            ['email' => 'ambitionmanagements@gmail.com'],
            [
                'tenant_id' => null,
                'name' => 'Platform Super Admin',
                'password' => Hash::make('password123'),
                'role' => 'super_admin',
                'is_online' => true,
                'last_active_at' => now(),
            ]
        );

        // 2. Demo Tenant
        $tenant = Tenant::firstOrCreate(
            ['name' => 'ChatBox Technologies'],
            [
                'email' => 'contact@chatbox.com',
                'plan' => 'enterprise',
                'status' => 'active',
                'settings' => [
                    'timezone' => 'Asia/Kolkata',
                    'language' => 'en',
                ],
            ]
        );

        // 3. Tenant Admin (Owner)
        $tenantAdmin = User::firstOrCreate(
            ['email' => 'admin@chatbox.com'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Rohit (Owner)',
                'password' => Hash::make('password123'),
                'role' => 'admin',
                'is_online' => true,
                'last_active_at' => now(),
            ]
        );

        // 4. Demo Support Agents
        $agent1 = User::firstOrCreate(
            ['email' => 'rahul@chatbox.com'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Rahul Verma',
                'password' => Hash::make('password123'),
                'role' => 'agent',
                'is_online' => true,
                'last_active_at' => now(),
            ]
        );

        $agent2 = User::firstOrCreate(
            ['email' => 'priya@chatbox.com'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Priya Patel',
                'password' => Hash::make('password123'),
                'role' => 'agent',
                'is_online' => false,
                'last_active_at' => now()->subMinutes(15),
            ]
        );

        // 5. Tenant Property (Website)
        $property = Property::firstOrCreate(
            ['tenant_id' => $tenant->id, 'site_name' => 'ChatBox Live Cloud'],
            [
                'site_url' => 'https://chatbox.live',
                'public_key' => 'pk_live_chatbox_' . Str::random(20),
                'secret_key' => 'sk_live_chatbox_' . Str::random(24),
                'allowed_domains' => ['localhost', '127.0.0.1', 'chatbox.live'],
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

        // 6. Departments
        $techSupport = Department::firstOrCreate(
            ['property_id' => $property->id, 'name' => 'Technical Support'],
            ['description' => 'Server issues, cPanel, SSL & DNS queries', 'is_active' => true]
        );
        $billing = Department::firstOrCreate(
            ['property_id' => $property->id, 'name' => 'Billing & Invoicing'],
            ['description' => 'Payment failures, renewals & invoices', 'is_active' => true]
        );
        $sales = Department::firstOrCreate(
            ['property_id' => $property->id, 'name' => 'Sales'],
            ['description' => 'New plans, migrations & VPS quotes', 'is_active' => true]
        );

        // Assign agents to departments
        $tenantAdmin->departments()->syncWithoutDetaching([$techSupport->id, $billing->id, $sales->id]);
        $agent1->departments()->syncWithoutDetaching([$techSupport->id, $sales->id]);
        $agent2->departments()->syncWithoutDetaching([$billing->id]);

        // 7. Canned Responses
        CannedResponse::firstOrCreate(
            ['property_id' => $property->id, 'shortcut' => '/greet'],
            ['content' => 'Hello! Welcome to ChatBox. My name is {agent_name}. How may I help you today?', 'created_by' => $tenantAdmin->id]
        );
        CannedResponse::firstOrCreate(
            ['property_id' => $property->id, 'shortcut' => '/pricing'],
            ['content' => 'Our plans start from just ₹499/mo with NVMe storage and 99.99% uptime guarantee!', 'created_by' => $tenantAdmin->id]
        );
        CannedResponse::firstOrCreate(
            ['property_id' => $property->id, 'shortcut' => '/bye'],
            ['content' => 'Thank you for contacting ChatBox Support! Have a wonderful day ahead.', 'created_by' => $tenantAdmin->id]
        );

        // 7.5 Default FAQs
        Faq::firstOrCreate(
            ['property_id' => $property->id, 'question' => 'What is your refund policy?'],
            ['category' => 'Billing', 'answer' => 'We offer a 100% money-back guarantee within 14 days of purchase. No questions asked.', 'order' => 1, 'is_active' => true]
        );
        Faq::firstOrCreate(
            ['property_id' => $property->id, 'question' => 'How long does server setup take?'],
            ['category' => 'Technical', 'answer' => 'Cloud instances are deployed automatically within 60 seconds after payment confirmation.', 'order' => 2, 'is_active' => true]
        );
        Faq::firstOrCreate(
            ['property_id' => $property->id, 'question' => 'How can I contact human support?'],
            ['category' => 'General', 'answer' => 'You can start a live chat right here, or email support@ambitionhost.com 24/7.', 'order' => 3, 'is_active' => true]
        );

        // ==========================================
        // 8. SALUTEHOST TENANT & FAQS
        // ==========================================
        $saluteTenant = Tenant::firstOrCreate(
            ['name' => 'SaluteHost'],
            [
                'email' => 'support@salutehost.com',
                'plan' => 'enterprise',
                'status' => 'active',
                'settings' => ['timezone' => 'Asia/Kolkata', 'language' => 'en']
            ]
        );

        $saluteAdmin = User::firstOrCreate(
            ['email' => 'admin@salutehost.com'],
            [
                'tenant_id' => $saluteTenant->id,
                'name' => 'SaluteHost Admin',
                'password' => Hash::make('password123'),
                'role' => 'admin',
                'is_online' => true,
            ]
        );

        $saluteAgent = User::firstOrCreate(
            ['email' => 'support@salutehost.com'],
            [
                'tenant_id' => $saluteTenant->id,
                'name' => 'SaluteHost Support Tech',
                'password' => Hash::make('password123'),
                'role' => 'agent',
                'is_online' => true,
            ]
        );

        $saluteProp = Property::firstOrCreate(
            ['tenant_id' => $saluteTenant->id, 'site_name' => 'SaluteHost Web Portal'],
            [
                'site_url' => 'https://salutehost.com',
                'public_key' => 'pk_live_salutehost_shGEXXUkoYhpUaDG',
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

        Artisan::call('salutehost:sync-faqs');
    }
}
