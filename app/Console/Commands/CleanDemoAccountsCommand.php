<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CleanDemoAccountsCommand extends Command
{
    protected $signature = 'chatbox:clean-accounts';
    protected $description = 'Setup and keep only Super Admin and SaluteHost company admin, removing all other demo accounts';

    public function handle(): int
    {
        $this->info('======================================================');
        $this->info('  ChatBox SaaS: Account Cleanup & Master Credentials  ');
        $this->info('======================================================');

        // 1. Setup / Update Super Admin
        $superAdmin = User::updateOrCreate(
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
        $this->info("✓ Super Admin verified: {$superAdmin->email} (Password: password123)");

        // 2. Setup / Update SaluteHost Tenant
        $saluteTenant = Tenant::firstOrCreate(
            ['name' => 'SaluteHost'],
            [
                'email' => 'support@salutehost.com',
                'plan' => 'enterprise',
                'status' => 'active',
                'settings' => [
                    'timezone' => 'Asia/Kolkata',
                    'language' => 'en',
                    'brand' => 'SaluteHost Cloud & Hosting',
                ],
            ]
        );
        $this->info("✓ SaluteHost Tenant verified (ID: {$saluteTenant->id})");

        // 3. Setup / Update SaluteHost Company Admin
        $saluteAdmin = User::updateOrCreate(
            ['email' => 'admin@salutehost.com'],
            [
                'tenant_id' => $saluteTenant->id,
                'name' => 'SaluteHost Admin',
                'password' => Hash::make('password123'),
                'role' => 'admin',
                'is_online' => true,
                'last_active_at' => now(),
            ]
        );
        $this->info("✓ SaluteHost Company Admin verified: {$saluteAdmin->email} (Password: password123)");

        // 4. Setup / Update SaluteHost Support Agent
        $saluteAgent = User::updateOrCreate(
            ['email' => 'support@salutehost.com'],
            [
                'tenant_id' => $saluteTenant->id,
                'name' => 'SaluteHost Support Agent',
                'password' => Hash::make('password123'),
                'role' => 'agent',
                'is_online' => true,
                'last_active_at' => now(),
            ]
        );
        $this->info("✓ SaluteHost Support Agent verified: {$saluteAgent->email} (Password: password123)");

        // 5. Setup / Verify SaluteHost Live Property
        $saluteProp = Property::firstOrCreate(
            ['tenant_id' => $saluteTenant->id, 'site_name' => 'SaluteHost Web Portal'],
            [
                'site_url' => 'https://salutehost.com',
                'public_key' => 'pk_live_salutehost_6eJtm9XjTJZ1GSOA',
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

        // 6. Setup Departments for SaluteHost
        $depts = [
            'Technical Support' => 'Server issues, cPanel, SSL, DNS & Server configurations',
            'Billing & Renewals' => 'Payment failures, invoices & renewals',
            'Sales & Pre-Sales' => 'VPS, Dedicated servers, domain booking & bulk discounts',
        ];

        $deptIds = [];
        foreach ($depts as $name => $desc) {
            $dept = Department::firstOrCreate(
                ['property_id' => $saluteProp->id, 'name' => $name],
                ['description' => $desc, 'is_active' => true]
            );
            $deptIds[] = $dept->id;
        }

        // Attach agents to SaluteHost departments
        $saluteAdmin->departments()->syncWithoutDetaching($deptIds);
        $saluteAgent->departments()->syncWithoutDetaching($deptIds);
        $this->info('✓ SaluteHost departments and agents mapped successfully.');

        // 7. Delete ALL Other Demo / Test Users
        $allowedEmails = [
            'ambitionmanagements@gmail.com',
            'admin@salutehost.com',
            'support@salutehost.com',
        ];

        $deletedCount = User::whereNotIn('email', $allowedEmails)->delete();
        $this->info("✓ Cleaned up {$deletedCount} demo/test users (e.g. admin@chatbox.com, rahul@chatbox.com, priya@chatbox.com).");

        // 8. Delete Dummy Demo Tenants (like ChatBox Technologies if present)
        $demoTenants = Tenant::whereNotIn('name', ['SaluteHost'])->get();
        foreach ($demoTenants as $t) {
            if (in_array(strtolower($t->name), ['chatbox technologies', 'demo tenant', 'test company'])) {
                $t->delete();
                $this->info("✓ Removed demo tenant: {$t->name}");
            }
        }

        // 9. Sync FAQs for SaluteHost
        try {
            Artisan::call('salutehost:sync-faqs');
            $this->info('✓ SaluteHost FAQs synced successfully.');
        } catch (\Throwable $e) {
            $this->warn('SaluteHost FAQs sync skipped: ' . $e->getMessage());
        }

        $this->newLine();
        $this->info('------------------------------------------------------');
        $this->info('ACTIVE CREDENTIALS SUMMARY:');
        $this->info('1. SUPER ADMIN:');
        $this->info('   Email:    ambitionmanagements@gmail.com');
        $this->info('   Password: password123');
        $this->info('   Role:     Platform Super Admin');
        $this->newLine();
        $this->info('2. SALUTEHOST COMPANY ADMIN:');
        $this->info('   Email:    admin@salutehost.com');
        $this->info('   Password: password123');
        $this->info('   Role:     SaluteHost Owner (Company Admin)');
        $this->newLine();
        $this->info('3. SALUTEHOST SUPPORT AGENT:');
        $this->info('   Email:    support@salutehost.com');
        $this->info('   Password: password123');
        $this->info('   Role:     Frontline Support Agent');
        $this->info('------------------------------------------------------');

        return Command::SUCCESS;
    }
}
