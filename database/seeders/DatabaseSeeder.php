<?php

namespace Database\Seeders;

use App\Models\CannedResponse;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Super Administrator (Platform Level)
        $superAdmin = User::create([
            'tenant_id' => null,
            'name' => 'Platform Super Admin',
            'email' => 'ambitionmanagements@gmail.com',
            'password' => Hash::make('password123'),
            'role' => 'super_admin',
            'is_online' => true,
            'last_active_at' => now(),
        ]);

        // 2. Demo Tenant
        $tenant = Tenant::create([
            'name' => 'ChatBox Technologies',
            'email' => 'contact@chatbox.com',
            'plan' => 'enterprise',
            'status' => 'active',
            'settings' => [
                'timezone' => 'Asia/Kolkata',
                'language' => 'en',
            ],
        ]);

        // 3. Tenant Admin (Owner)
        $tenantAdmin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Rohit (Owner)',
            'email' => 'admin@chatbox.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'is_online' => true,
            'last_active_at' => now(),
        ]);

        // 4. Demo Support Agents
        $agent1 = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Rahul Verma',
            'email' => 'rahul@chatbox.com',
            'password' => Hash::make('password123'),
            'role' => 'agent',
            'is_online' => true,
            'last_active_at' => now(),
        ]);

        $agent2 = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Priya Patel',
            'email' => 'priya@chatbox.com',
            'password' => Hash::make('password123'),
            'role' => 'agent',
            'is_online' => false,
            'last_active_at' => now()->subMinutes(15),
        ]);

        // 5. Tenant Property (Website)
        $property = Property::create([
            'tenant_id' => $tenant->id,
            'site_name' => 'ChatBox Live Cloud',
            'site_url' => 'https://chatbox.live',
            'public_key' => 'pk_live_chatbox_' . Str::random(20),
            'secret_key' => 'sk_live_chatbox_' . Str::random(24),
            'allowed_domains' => ['localhost', '127.0.0.1', 'chatbox.live'],
            'widget_config' => [
                'primary_color' => '#2563eb',
                'welcome_message' => 'Namaste! Welcome to ChatBox Support. How can we help you today?',
                'logo_url' => 'https://via.placeholder.com/64x64.png?text=CB',
                'position' => 'right',
                'require_email' => true,
                'require_name' => true,
            ],
            'is_active' => true,
        ]);

        // 6. Departments
        $techSupport = Department::create([
            'property_id' => $property->id,
            'name' => 'Technical Support',
            'description' => 'Server issues, cPanel, SSL & DNS queries',
            'is_active' => true,
        ]);

        $billing = Department::create([
            'property_id' => $property->id,
            'name' => 'Billing & Invoicing',
            'description' => 'Payment failures, renewals & invoices',
            'is_active' => true,
        ]);

        $sales = Department::create([
            'property_id' => $property->id,
            'name' => 'Sales',
            'description' => 'New plans, migrations & VPS quotes',
            'is_active' => true,
        ]);

        // Assign agents to departments
        $tenantAdmin->departments()->attach([$techSupport->id, $billing->id, $sales->id]);
        $agent1->departments()->attach([$techSupport->id, $sales->id]);
        $agent2->departments()->attach([$billing->id]);

        // 7. Canned Responses
        CannedResponse::create([
            'property_id' => $property->id,
            'shortcut' => '/greet',
            'content' => 'Hello! Welcome to ChatBox. My name is {agent_name}. How may I help you today?',
            'created_by' => $tenantAdmin->id,
        ]);

        CannedResponse::create([
            'property_id' => $property->id,
            'shortcut' => '/pricing',
            'content' => 'Our plans start from just ₹499/mo with NVMe storage and 99.99% uptime guarantee!',
            'created_by' => $tenantAdmin->id,
        ]);

        CannedResponse::create([
            'property_id' => $property->id,
            'shortcut' => '/bye',
            'content' => 'Thank you for contacting ChatBox Support! Have a wonderful day ahead.',
            'created_by' => $tenantAdmin->id,
        ]);

        // 7.5 Default Company FAQs
        \App\Models\Faq::create([
            'property_id' => $property->id,
            'category' => 'Billing',
            'question' => 'What is your refund policy?',
            'answer' => 'We offer a 100% money-back guarantee within 14 days of purchase. No questions asked.',
            'order' => 1,
            'is_active' => true,
        ]);

        \App\Models\Faq::create([
            'property_id' => $property->id,
            'category' => 'Technical',
            'question' => 'How long does server setup take?',
            'answer' => 'Cloud instances are deployed automatically within 60 seconds after payment confirmation.',
            'order' => 2,
            'is_active' => true,
        ]);

        \App\Models\Faq::create([
            'property_id' => $property->id,
            'category' => 'General',
            'question' => 'How can I contact human support?',
            'answer' => 'You can start a live chat right here, or email support@ambitionhost.com 24/7.',
            'order' => 3,
            'is_active' => true,
        ]);

        // 8. Sample Visitor, Conversation, and Messages (Test Data for Phase 2)
        $visitor = Visitor::create([
            'property_id' => $property->id,
            'visitor_token' => 'vtok_' . Str::random(32),
            'name' => 'Amit Kumar',
            'email' => 'amit@example.com',
            'phone' => '+919876543210',
            'ip_address' => '127.0.0.1',
            'city' => 'Mumbai',
            'country_code' => 'IN',
            'browser' => 'Chrome',
            'os' => 'Windows',
            'current_page_url' => 'https://chatbox.live/pricing',
            'last_seen_at' => now(),
            'is_blocked' => false,
        ]);

        $conversation = Conversation::create([
            'property_id' => $property->id,
            'visitor_id' => $visitor->id,
            'assigned_agent_id' => $agent1->id,
            'department_id' => $sales->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'visitor',
            'sender_id' => null,
            'message_type' => 'text',
            'content' => 'Hi, does the Cloud VPS plan support PHP 8.2 and Redis cache?',
            'is_read' => true,
            'created_at' => now()->subMinutes(2),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'agent',
            'sender_id' => $agent1->id,
            'message_type' => 'text',
            'content' => 'Hello Amit! Yes, absolutely. All our Cloud VPS setups come with full root access, PHP 8.2/8.3, and pre-configured Redis caching.',
            'is_read' => true,
            'created_at' => now()->subMinute(),
        ]);
    }
}
