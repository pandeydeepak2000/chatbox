<?php

namespace App\Console\Commands;

use App\Models\Faq;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SyncSaluteHostFaqs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'salutehost:sync-faqs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync all 20 SaluteHost hosting and domain FAQs to SaluteHost property in ChatBox';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Finding SaluteHost properties...');

        $properties = Property::where('public_key', 'like', '%salutehost%')
            ->orWhere('site_name', 'like', '%SaluteHost%')
            ->orWhereHas('tenant', function ($q) {
                $q->where('name', 'like', '%SaluteHost%');
            })
            ->get();

        if ($properties->isEmpty()) {
            $this->warn('No existing SaluteHost property found. Checking tenant...');
            $tenant = Tenant::firstOrCreate(
                ['name' => 'SaluteHost'],
                [
                    'email' => 'support@salutehost.com',
                    'plan' => 'enterprise',
                    'status' => 'active',
                    'settings' => ['timezone' => 'Asia/Kolkata', 'language' => 'en']
                ]
            );

            $prop = Property::firstOrCreate(
                ['tenant_id' => $tenant->id, 'site_name' => 'SaluteHost Web Portal'],
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
            $properties = collect([$prop]);
        }

        $faqs = $this->getSaluteHostFaqs();

        foreach ($properties as $property) {
            $this->info("Syncing 20 FAQs for Property: {$property->site_name} (Key: {$property->public_key})...");

            // Remove old placeholder FAQs for this SaluteHost property
            Faq::where('property_id', $property->id)->delete();

            foreach ($faqs as $item) {
                Faq::create([
                    'property_id' => $property->id,
                    'category' => $item['category'],
                    'question' => $item['question'],
                    'answer' => $item['answer'],
                    'order' => $item['order'],
                    'is_active' => true,
                ]);
            }

            $count = Faq::where('property_id', $property->id)->count();
            $this->info("Property {$property->public_key} now has {$count} FAQs!");
        }

        $this->info('All SaluteHost FAQs synchronized successfully!');
        return Command::SUCCESS;
    }

    /**
     * SaluteHost FAQs definition
     */
    public function getSaluteHostFaqs(): array
    {
        return [
            [
                'category'  => 'Billing & Account',
                'question'  => 'How do I reset my SaluteHost Client Area or cPanel password?',
                'answer'    => "You can easily reset your passwords through the following methods:\n\n• **Client Area Password:** On the login page (`salutehost.com/login`), click **'Forgot Password'**, enter your registered email address, and follow the reset link sent to your inbox.\n• **cPanel Password:** Log in to your SaluteHost Client Area > **Services** > Select your hosting plan > Click **'Change Password'** from the left sidebar to set a new cPanel password instantly.",
                'order'     => 1,
            ],
            [
                'category'  => 'Billing & Invoices',
                'question'  => 'Where can I view or download my invoices and receipts?',
                'answer'    => "You can view and download all your past and pending invoices directly from the Client Area:\n\n1. Log in to your SaluteHost Client Area (`salutehost.com/login`).\n2. Navigate to **Billing > My Invoices**.\n3. Click on the invoice number to view details or click **'Download PDF'** for official accounting records.\n4. Paid invoices show a green **PAID** status badge with the transaction reference.",
                'order'     => 2,
            ],
            [
                'category'  => 'Billing & Payments',
                'question'  => 'What payment methods does SaluteHost accept?',
                'answer'    => "SaluteHost supports all major secure Indian and International payment methods:\n\n1. **UPI:** Google Pay, PhonePe, Paytm, BHIM, and Amazon Pay (Zero additional transaction fees).\n2. **Dynamic QR Code:** Instant scan-and-pay on the checkout screen with automatic order activation in seconds.\n3. **SaluteHost Wallet:** Pre-fund your account balance for 1-click renewals and instant checkout.\n4. **Cards & Net Banking:** Visa, MasterCard, RuPay Debit/Credit cards, and Net Banking across 50+ major banks.",
                'order'     => 3,
            ],
            [
                'category'  => 'Domains',
                'question'  => 'What are SaluteHost official nameservers to point my domain?',
                'answer'    => "To point your domain name to SaluteHost web hosting, use the following official nameservers:\n\n• **Primary Nameserver:** `ns1.salutehost.com`\n• **Secondary Nameserver:** `ns2.salutehost.com`\n\n**How to update:**\n1. Log in to your domain registrar (GoDaddy, Namecheap, Hostinger, etc.).\n2. Open DNS / Nameserver settings for your domain.\n3. Select **Custom Nameservers** and enter `ns1.salutehost.com` and `ns2.salutehost.com`.\n4. Save your changes. Global DNS propagation usually takes between 15 minutes to a few hours.",
                'order'     => 4,
            ],
            [
                'category'  => 'Domains',
                'question'  => 'How can I buy or register a new domain name on SaluteHost?',
                'answer'    => "Registering a domain on SaluteHost takes less than 2 minutes:\n\n1. Visit the Domain Search page (`salutehost.com/domains`) and search for your desired domain name (e.g., `yourbrand.com` or `yourbrand.in`).\n2. If the domain is available, click **Add to Cart**.\n3. Proceed to checkout, select your billing cycle, and complete payment via UPI, QR code, or Card.\n4. The domain will be registered instantly and appear in your Client Portal under **My Domains**.",
                'order'     => 5,
            ],
            [
                'category'  => 'Domains',
                'question'  => 'How do I transfer my domain to SaluteHost from another registrar?',
                'answer'    => "To transfer an existing domain to SaluteHost without any website downtime:\n\n1. **Unlock Domain:** In your current registrar's panel, set the Domain Transfer Lock to **Disabled / Off**.\n2. **Obtain EPP/Auth Code:** Request the authorization / EPP key from your current registrar.\n3. **Initiate Transfer:** Go to SaluteHost Client Area > **Transfer Domain**, enter your domain name and paste the EPP code.\n4. **Complete Checkout:** Pay the 1-year renewal fee (transfers include an additional 1-year extension). The transfer will complete automatically within 5 to 7 days.",
                'order'     => 6,
            ],
            [
                'category'  => 'Domains',
                'question'  => 'How do I manage DNS records like A record, CNAME, TXT, or MX records?',
                'answer'    => "You can manage DNS records easily in two ways:\n\n• **Via cPanel Zone Editor (Recommended):** Log in to cPanel > **Domains** > **Zone Editor** > Click **Manage** next to your domain. Click **+ Add Record** to add your A, CNAME, TXT, or MX record and save.\n• **Via Client Area DNS:** If you only have a domain without hosting, navigate to **Client Area > My Domains > DNS Management** to configure your records.",
                'order'     => 7,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'What web hosting plans does SaluteHost offer?',
                'answer'    => "SaluteHost provides ultra-fast NVMe SSD powered web hosting backed by a 99.9% Uptime Guarantee:\n\n• **Starter Hosting:** Single website, 10 GB Pure NVMe SSD storage, Free SSL, unmetered bandwidth, and 5 business emails. Perfect for beginners and portfolio sites.\n• **Business Hosting:** Unlimited websites, 50 GB NVMe SSD, free domain on annual billing, LiteSpeed web server, and 2x CPU/RAM resources.\n• **Cloud VPS:** Dedicated KVM virtualized resources, full root access, Tier-4 data center location, 1 Gbps uplink, and enterprise DDoS protection.",
                'order'     => 8,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'How do I install WordPress using Softaculous in cPanel?',
                'answer'    => "You can install WordPress in 60 seconds with our 1-click installer:\n\n1. Log in to your cPanel dashboard.\n2. Scroll to the **Softaculous Apps Installer** section and click the **WordPress** icon.\n3. Click **Install Now**.\n4. Select your domain (`https://yourdomain.com`). Leave the **In Directory** field blank if installing on your root site.\n5. Set your Admin Username, secure Password, and Admin Email.\n6. Click **Install** at the bottom. Your WordPress website will be live in 30 seconds!",
                'order'     => 9,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'How do I activate Free SSL Certificate and force HTTPS?',
                'answer'    => "SaluteHost includes lifetime automated Let's Encrypt / AutoSSL certificates with all hosting accounts:\n\n1. **Automatic Activation:** Once your domain points to our nameservers, AutoSSL runs automatically within 15–30 minutes to install your certificate.\n2. **Manual Force Run:** Log in to cPanel > **SSL/TLS Status** > Select your domain > Click **Run AutoSSL**.\n3. **Force HTTPS:** In cPanel > **Domains**, switch the **Force HTTPS Redirect** toggle to **ON** so all visitors are redirected to secure `https://`.",
                'order'     => 10,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'How do I create a professional email address and access Webmail?',
                'answer'    => "• **Create Email Account:**\n1. Log in to cPanel and open **Email Accounts**.\n2. Click **+ Create**, choose your domain, set your username (e.g. `support`, `info`, `sales`), and choose a strong password.\n3. Click **Create**.\n\n• **Access Webmail:**\nVisit `yourdomain.com/webmail` in your browser, enter your full email address and password, and use Roundcube to send and receive emails.",
                'order'     => 11,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'How do I log in to my cPanel dashboard or find my credentials?',
                'answer'    => "You can access cPanel in two easy ways:\n\n• **Single Sign-On (No Password Needed):** Log in to your SaluteHost Client Area (`salutehost.com/login`) > Go to **Services** > Click on your active hosting package > Click **Login to cPanel** on the left menu.\n• **Direct URL:** Go to `yourdomain.com:2083` or `cpanel.yourdomain.com` and log in using the credentials provided in your hosting welcome email.",
                'order'     => 12,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'How can I change the PHP version or enable PHP extensions?',
                'answer'    => "SaluteHost supports multiple PHP versions from PHP 7.4 up to PHP 8.3:\n\n1. Log in to cPanel and open **Select PHP Version** (or **MultiPHP Manager**).\n2. Choose your preferred PHP version (e.g., PHP 8.2 or 8.3) from the dropdown and click **Apply**.\n3. Switch to the **Extensions** tab to enable modules such as `curl`, `fileinfo`, `gd`, `imagick`, `pdo_mysql`, `zip`, etc.\n4. Adjust memory limits (e.g. 512M) and `upload_max_filesize` in the **Options** tab.",
                'order'     => 13,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'Can I run Node.js and Python applications on SaluteHost?',
                'answer'    => "Yes! SaluteHost Cloud and Business hosting plans feature built-in application runners in cPanel:\n\n• **Node.js:** Open the **Setup Node.js App** tool in cPanel, select your Node version (v18, v20, v22), specify the application root directory, and run `npm install`.\n• **Python (Django / Flask):** Open **Setup Python App** in cPanel to create Python 3.10 / 3.11 virtual environments.\n• **Custom Stacks:** For full custom control with Docker, PM2, and Redis, our Cloud VPS plans provide full root access.",
                'order'     => 14,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'How do automatic backups work and how do I restore my website?',
                'answer'    => "SaluteHost includes automated daily and weekly backups for all accounts:\n\n1. Log in to cPanel and open **JetBackup 5** (or **Backup**).\n2. **Full Download:** Click **Create Full Backup** to download a compressed archive to your local computer.\n3. **1-Click Restore:** Inside JetBackup, select Files, MySQL Databases, Cron Jobs, or Email Accounts and click **Restore** to roll back to any available snapshot with zero data loss.",
                'order'     => 15,
            ],
            [
                'category'  => 'Technical Support',
                'question'  => 'How do I fix a 403 Forbidden error on my website?',
                'answer'    => "Common causes and solutions for 403 Forbidden errors:\n\n1. **Missing Index File:** Ensure your website's main file (`index.html` or `index.php`) is located inside the `public_html` directory.\n2. **File Permissions:** Folder permissions must be set to `755` and file permissions to `644`. Check this in cPanel File Manager by right-clicking and selecting **Change Permissions**.\n3. **Corrupt .htaccess:** In cPanel File Manager, temporarily rename `public_html/.htaccess` to `.htaccess.bak` to check if an invalid deny rule is causing the blockage.",
                'order'     => 16,
            ],
            [
                'category'  => 'Technical Support',
                'question'  => 'How do I resolve a 500 Internal Server Error or Database Connection Error?',
                'answer'    => "• **500 Internal Server Error:**\n1. Open cPanel **File Manager** and inspect `public_html/error_log` for the exact PHP error and line number.\n2. Check your `.htaccess` file for syntax errors.\n3. Increase your PHP memory limit to `512M` in cPanel under **Select PHP Version > Options**.\n\n• **Database Connection Error:**\n1. Verify database credentials in your configuration file (`wp-config.php` for WordPress or `.env` for Laravel).\n2. In cPanel > **MySQL Databases**, ensure the database user is assigned to the database with **ALL PRIVILEGES**.",
                'order'     => 17,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'How do I add a subdomain or host multiple websites (addon domain)?',
                'answer'    => "• **Subdomain (e.g. blog.yourdomain.com):**\n1. In cPanel, navigate to the **Domains** section and click **Create A New Domain**.\n2. Enter your subdomain (e.g., `blog.yourdomain.com`).\n3. The document root will automatically be assigned (e.g., `public_html/blog`). Click **Submit**.\n\n• **Addon Domain (Multiple Websites):**\nIf your hosting plan allows multiple sites, click **Create A New Domain**, enter your new domain name, uncheck 'Share document root', specify a folder name, and click **Submit**.",
                'order'     => 18,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'How do I upload website files using cPanel File Manager or FTP?',
                'answer'    => "You can upload your website files using either method:\n\n• **Method 1 - cPanel File Manager (Easiest):** Open cPanel > **File Manager** > Go to `public_html` > Click **Upload** at the top > Select your website `.zip` archive > Right-click and choose **Extract**.\n• **Method 2 - FTP (FileZilla):** Connect with Host: your domain or server IP, Username: cPanel username, Password: cPanel password, Port: 21. Drag and drop your files into `public_html`.",
                'order'     => 19,
            ],
            [
                'category'  => 'Billing & Support',
                'question'  => 'What is SaluteHost refund policy and money-back guarantee?',
                'answer'    => "SaluteHost offers a transparent money-back guarantee:\n\n• **Web & Cloud Hosting:** All shared and cloud hosting plans come with a **7-Day Money-Back Guarantee**. If you are not fully satisfied, you can open a billing ticket within 7 days for a 100% refund.\n• **Domains & Addons:** Domain registrations, renewals, and software licenses are non-refundable as registry fees are immediately collected by central domain registries.\n\nRefunds are processed back to your original payment method or SaluteHost Wallet within 2–5 business days.",
                'order'     => 20,
            ],
        ];
    }
}
