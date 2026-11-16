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
    protected $signature = 'chatbox:clean-accounts {--force : Required to run when APP_ENV=production} {--reset-passwords : Give the three master accounts new random passwords}';
    protected $description = 'Ensure the Super Admin and SaluteHost accounts exist and remove the known demo accounts';

    /**
     * Only these demo accounts are removed. Earlier versions deleted EVERY other user on the
     * platform, which is unsafe for a multi-company deployment.
     */
    private const DEMO_EMAILS = ['admin@chatbox.com', 'rahul@chatbox.com', 'priya@chatbox.com'];

    /** @var array<string, string> e-mail => newly generated password (printed once) */
    private array $generated = [];

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to run in production without --force.');
            return Command::FAILURE;
        }
        if (! $this->confirm('This creates/updates master accounts and deletes the known demo accounts. Continue?', ! $this->input->isInteractive())) {
            return Command::FAILURE;
        }

        $this->info('======================================================');
        $this->info('  ChatBox SaaS: Account Cleanup & Master Credentials  ');
        $this->info('======================================================');

        // 1. Setup / Update Super Admin
        $superAdmin = $this->ensureUser('ambitionmanagements@gmail.com', [
            'tenant_id' => null,
            'name' => 'Platform Super Admin',
            'role' => 'super_admin',
        ]);
        $this->info("✓ Super Admin verified: {$superAdmin->email}");

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
        $saluteAdmin = $this->ensureUser('admin@salutehost.com', [
            'tenant_id' => $saluteTenant->id,
            'name' => 'SaluteHost Support',
            'role' => 'admin',
        ]);
        $this->info("✓ SaluteHost Company Admin verified: {$saluteAdmin->email}");

        // 4. Setup / Update SaluteHost Support Agent
        $saluteAgent = $this->ensureUser('support@salutehost.com', [
            'tenant_id' => $saluteTenant->id,
            'name' => 'SaluteHost Support Agent',
            'role' => 'agent',
        ]);
        $this->info("✓ SaluteHost Support Agent verified: {$saluteAgent->email}");

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

        // 7. Delete only the known demo / test users
        $deletedCount = User::whereIn('email', self::DEMO_EMAILS)->delete();
        $this->info("✓ Removed {$deletedCount} known demo users (" . implode(', ', self::DEMO_EMAILS) . ').');

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
        if ($this->generated) {
            $this->warn('NEW PASSWORDS (shown once — store them in your password manager now):');
            foreach ($this->generated as $email => $password) {
                $this->line("   {$email}  {$password}");
            }
        } else {
            $this->info('Existing passwords were kept. Use --reset-passwords to generate new ones.');
        }

        return Command::SUCCESS;
    }

    /**
     * Create the account with a random password, or update its profile. An existing password
     * is only replaced with --reset-passwords (never with a fixed, published password).
     */
    private function ensureUser(string $email, array $attributes): User
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->fill($attributes);

        if (! $user->exists || $this->option('reset-passwords')) {
            $password = Str::password(16);
            $user->password = Hash::make($password);
            $this->generated[$email] = $password;
            if ($user->exists) {
                $user->tokens()->delete();
            }
        }
        $user->save();

        return $user;
    }
}
