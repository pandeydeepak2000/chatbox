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
    protected $description = 'Sync all 32 SaluteHost hosting, VPS, n8n, domain, and billing FAQs to SaluteHost property in ChatBox';

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
            // 1. Ensure 10-minute session timeout in tenant settings
            if ($property->tenant) {
                $settings = $property->tenant->settings ?? [];
                $settings['session_timeout_minutes'] = 10;
                $property->tenant->update(['settings' => $settings]);
            }

            // Set default staff_label in widget_config if not present
            $wc = $property->widget_config ?? [];
            if (empty($wc['staff_label'])) {
                $wc['staff_label'] = 'SaluteHost Support';
                $property->update(['widget_config' => $wc]);
                \Illuminate\Support\Facades\Cache::forget("prop_pub_{$property->public_key}");
            }

            // 2. Auto-close old inactive conversations (> 10 minutes)
            $closedCount = \App\Models\Conversation::where('property_id', $property->id)
                ->whereIn('status', ['unassigned', 'open', 'pending'])
                ->where(function ($q) {
                    $q->where('last_message_at', '<', now()->subMinutes(10))
                      ->orWhere(function ($q2) {
                          $q2->whereNull('last_message_at')->where('updated_at', '<', now()->subMinutes(10));
                      });
                })
                ->update(['status' => 'closed', 'closed_at' => now()]);
            if ($closedCount > 0) {
                $this->info("Auto-closed {$closedCount} stale inactive conversations (> 10 min).");
            }

            $this->info("Syncing " . count($faqs) . " FAQs for Property: {$property->site_name} (Key: {$property->public_key})...");

            // Remove old placeholder FAQs for this SaluteHost property
            Faq::where('property_id', $property->id)->delete();

            foreach ($faqs as $item) {
                Faq::create([
                    'property_id' => $property->id,
                    'category' => $item['category'],
                    'question' => $item['question'],
                    'answer' => $item['answer'],
                    'order' => $item['order'] ?? 0,
                    'is_active' => true,
                ]);
            }

            $count = Faq::where('property_id', $property->id)->count();
            \Illuminate\Support\Facades\Cache::forget("prop_faqs_all_{$property->id}");
            $this->info("Property {$property->public_key} now has {$count} FAQs (Cache refreshed)!");
        }

        // Update user display name and historical join messages from "Admin" to "SaluteHost Support"
        \App\Models\User::where('name', 'SaluteHost Admin')->update(['name' => 'SaluteHost Support']);
        \App\Models\User::where('email', 'admin@salutehost.com')->where('name', 'like', '%Admin%')->update(['name' => 'SaluteHost Support']);
        \App\Models\Message::where('content', 'like', '%Admin has joined the chat%')
            ->update(['content' => '🟢 SaluteHost Support has joined the chat.']);

        $this->info('All SaluteHost FAQs synchronized successfully!');
        return Command::SUCCESS;
    }

    /**
     * SaluteHost 32 Master FAQs definition (100% Professional English aligned with SaluteHost Portal UI)
     */
    public function getSaluteHostFaqs(): array
    {
        return [
            // SECTION 1: DOMAINS & DNS MANAGEMENT (Order 1 - 5)
            [
                'category' => 'Domains & DNS',
                'question' => 'How do I buy or register a new domain name on SaluteHost?',
                'answer'   => "Registering a new domain on SaluteHost is instant and fully automated:\n\n1. **Search Domain:** Visit salutehost.com/domains or click **Domains** in the top navigation bar.\n2. **Check Availability:** Enter your desired domain name (e.g. `yourbrand.com` or `yourbrand.in`) and click **Search Domain**.\n3. **Add to Cart:** If available, click **Add to Cart** and choose your registration period (1 to 10 Years).\n4. **Checkout:** Click **Proceed to Checkout**. Select your payment method (Instant UPI Dynamic QR, Wallet Balance, Net Banking, or Debit/Credit Card).\n5. **Instant Activation:** Once payment is confirmed, the registry locks the domain to your name and it appears immediately under **Client Portal > My Domains** ready to manage.\n\n💡 **Includes:** Free Lifetime WHOIS Privacy Protection and Anycast DNS routing!",
                'order'    => 1,
            ],
            [
                'category' => 'Domains & DNS',
                'question' => 'How do I transfer my domain from another registrar to SaluteHost?',
                'answer'   => "You can transfer your domain to SaluteHost with zero website downtime by following these steps:\n\n1. **Unlock Domain:** Log in to your current registrar (GoDaddy, Namecheap, Hostinger, etc.) and turn **Domain Transfer Lock** to **OFF / Unlocked**.\n2. **Get EPP / Auth Code:** Request or copy the EPP/Authorization code from your current provider's domain settings.\n3. **Place Transfer Order:** Go to the SaluteHost Transfer page (`salutehost.com/domain-transfer`), enter your domain name, and paste your EPP Auth Code.\n4. **Checkout:** Pay the 1-year transfer fee (which automatically extends your domain validity by +1 additional year).\n5. **Registry Transfer:** Both registrars process the transfer automatically. The registry typically completes the move in 5 to 7 days, and your domain will appear in your SaluteHost **My Domains** list.",
                'order'    => 2,
            ],
            [
                'category' => 'Domains & DNS',
                'question' => 'What are SaluteHost official nameservers and how do I point my domain?',
                'answer'   => "SaluteHost provides high-speed Anycast nameservers:\n\n• **Primary Nameserver (NS1):** `ns1.salutehost.com` (or `z1.myfixdns.com`)\n• **Secondary Nameserver (NS2):** `ns2.salutehost.com` (or `z2.myfixdns.com`)\n\n**Steps to update nameservers in SaluteHost Client Portal:**\n1. Log in to your **SaluteHost Client Portal** (`salutehost.com/login`).\n2. Go to **My Domains** from the left sidebar navigation.\n3. Click the **Manage** button next to your active domain.\n4. Select the **Nameservers** tab.\n5. Choose **\"Use Default SaluteHost Nameservers\"** for automatic DNS and hosting integration, or select **\"Use Custom Nameservers\"** to enter third-party nameservers (like Cloudflare).\n6. Click **Save Nameservers**.\n\n⏳ Global DNS propagation usually takes 15 minutes to 4 hours to take effect worldwide.",
                'order'    => 3,
            ],
            [
                'category' => 'Domains & DNS',
                'question' => 'How do I add or manage DNS records like A, CNAME, MX, or TXT records?',
                'answer'   => "You can manage DNS records through two convenient methods:\n\n• **Method 1 - SaluteHost Client Portal (Domain Only / Standalone DNS):**\n1. Log in to **Client Portal > My Domains** and click **Manage** on your domain.\n2. Click on the **DNS Management** tab.\n3. *Note:* Your domain must point to default SaluteHost nameservers. If not, click **Apply Default Nameservers** so the DNS Zone Editor unlocks.\n4. Click **+ Add Record**, select the record type (A, CNAME, MX, TXT), enter Name/Host, Target/Value, and click **Save Record**.\n\n• **Method 2 - cPanel Zone Editor (With Web Hosting):**\n1. Log in to **cPanel** via **Client Portal > Web Hosting > cPanel Login**.\n2. Under the **Domains** section, open **Zone Editor**.\n3. Click **Manage** next to your domain, then click **+ Add Record** to configure records for Google Workspace, Zoho Mail, server IPs, or subdomains.",
                'order'    => 4,
            ],
            [
                'category' => 'Domains & DNS',
                'question' => 'Is WHOIS Privacy Protection free on SaluteHost and how do I manage it?',
                'answer'   => "Yes! SaluteHost provides **100% Free Lifetime WHOIS Privacy Protection** on all supported top-level domains (.com, .net, .org, etc.):\n\n1. **Automatic Protection:** WHOIS privacy is enabled by default upon domain registration. Your personal phone number, home address, and personal email are shielded from public databases, telemarketers, and spammers.\n2. **To Manage Privacy:** Log in to **Client Portal > My Domains > Manage > Security & Protection**.\n3. You can toggle **WHOIS Privacy Status** ON or OFF at any time.\n\n*Note:* Certain country-code registries (such as `.in` as per NIXI registry regulations) do not permit proxy masking by policy.",
                'order'    => 5,
            ],

            // SECTION 2: WEB & CLOUD HOSTING (Order 6 - 13)
            [
                'category' => 'Web & Cloud Hosting',
                'question' => 'What web hosting plans does SaluteHost offer and what features are included?',
                'answer'   => "SaluteHost provides ultra-fast Cloud NVMe SSD Web Hosting powered by LiteSpeed and CloudLinux:\n\n• **Cloud Starter:** Host 1 Website, 10GB Pure NVMe SSD, Unmetered Bandwidth, Free Lifetime SSL, 5 Business Emails, cPanel control panel.\n• **Cloud Standard:** Host 3 Websites, 30GB Pure NVMe SSD, Free SSL, Unlimited Business Emails, Daily Automated Backups, 2GB RAM.\n• **Business Turbo:** Host Unlimited Websites, 100GB NVMe SSD, 4GB RAM & 2 Dedicated vCPUs, LiteSpeed Cache accelerator, Priority Support.\n\n⭐ **Standard on all plans:** 99.9% Uptime Guarantee, 1-Click Softaculous App Installer, Free Daily JetBackup, and 24/7 Technical Support.",
                'order'    => 6,
            ],
            [
                'category' => 'Web & Cloud Hosting',
                'question' => 'How do I log in to cPanel or reset my cPanel password?',
                'answer'   => "You can access your cPanel dashboard in two simple ways:\n\n• **Method 1 - 1-Click SSO Login (No Password Needed):**\n1. Log in to **SaluteHost Client Portal** (`salutehost.com/login`).\n2. Click **Web Hosting** in the left sidebar menu.\n3. Click the orange **cPanel Login** button next to your active package. You will be logged into cPanel instantly without entering credentials.\n\n• **Method 2 - Direct URL & Password Reset:**\n1. Open `https://yourdomain.com:2083` or `https://cpanel.yourdomain.com` in your browser.\n2. Enter your cPanel Username and Password.\n3. **To view or change password:** In Client Portal > **Web Hosting**, click the **Login Info** button on your service card. You can view your current credentials or enter a new password and click **Update Password** for real-time synchronization.",
                'order'    => 7,
            ],
            [
                'category' => 'Web & Cloud Hosting',
                'question' => 'How do I install WordPress on SaluteHost hosting using Softaculous?',
                'answer'   => "Installing WordPress on SaluteHost takes under 1 minute using the 1-Click installer:\n\n1. Log in to your **cPanel** (via Client Portal > Web Hosting > **cPanel Login**).\n2. Scroll to the **Software** section and click **Softaculous Apps Installer** (or click the **WordPress** icon).\n3. Click the blue **Install Now** button.\n4. **Choose Protocol & Domain:** Select `https://` protocol and select your domain name. Leave the \"In Directory\" field empty to install directly on your root website.\n5. **Admin Account:** Enter your Admin Username, a strong Admin Password, and your working email address.\n6. Click **Install** at the bottom. In 30 seconds, your site is live and you can access your WordPress admin panel at `https://yourdomain.com/wp-admin`.",
                'order'    => 8,
            ],
            [
                'category' => 'Web & Cloud Hosting',
                'question' => 'How do I activate Free SSL Certificate and enable HTTPS redirect?',
                'answer'   => "Every SaluteHost hosting package includes **Free Lifetime AutoSSL (Let's Encrypt)**:\n\n1. **Verify Nameservers:** Ensure your domain is pointed to SaluteHost nameservers (`ns1.salutehost.com` & `ns2.salutehost.com`). AutoSSL triggers automatically within 15–30 minutes of DNS propagation.\n2. **Manual SSL Run (Instant):**\n   - Log in to **cPanel** > search for **SSL/TLS Status**.\n   - Check the box next to your domain(s) and click the blue **Run AutoSSL** button.\n3. **Force HTTPS Redirect:**\n   - In cPanel, go to the **Domains** tool.\n   - Toggle the **Force HTTPS Redirect** switch to **ON** next to your domain so all HTTP visitors are automatically redirected to secure HTTPS.",
                'order'    => 9,
            ],
            [
                'category' => 'Web & Cloud Hosting',
                'question' => 'How do I create a professional email address and access Webmail?',
                'answer'   => "Create custom business email addresses (e.g. `support@yourdomain.com`, `info@yourdomain.com`) in a few clicks:\n\n1. **Create Email Account:**\n   - Log in to **cPanel** > open **Email Accounts** tool.\n   - Click the **+ Create** button on the right.\n   - Choose your domain, type your desired username (e.g. `contact`), and set a secure password.\n   - Set storage quota (or Unlimited) and click **Create**.\n\n2. **Accessing Webmail:**\n   - Open `https://yourdomain.com/webmail` or `https://webmail.yourdomain.com` in any web browser.\n   - Enter your full email address and password to log in.\n   - Access your inbox, send and receive emails via Roundcube webmail interface, or connect it to Outlook, Gmail, or your mobile phone.",
                'order'    => 10,
            ],
            [
                'category' => 'Web & Cloud Hosting',
                'question' => 'How can I change the PHP version and increase memory or upload limits?',
                'answer'   => "SaluteHost supports multiple PHP versions from PHP 7.4 up to PHP 8.3:\n\n1. Log in to **cPanel** and search for **Select PHP Version** (or **MultiPHP Manager**).\n2. Choose your preferred PHP version (e.g. PHP 8.2 or 8.3) from the dropdown and click **Apply / Set as current**.\n3. **Extensions:** Switch to the **Extensions** tab and enable required PHP modules (such as `curl`, `gd`, `zip`, `fileinfo`, `imagick`, `mysqli`, `pdo_mysql`, `intl`).\n4. **Increase Limits:** Click the **Options** tab to adjust directives:\n   - `memory_limit`: Set to `512M`\n   - `upload_max_filesize`: Set to `128M` or `256M`\n   - `post_max_size`: Set to `256M`\n   - `max_execution_time`: Set to `300`",
                'order'    => 11,
            ],
            [
                'category' => 'Web & Cloud Hosting',
                'question' => 'Can I host Node.js and Python applications on SaluteHost cPanel?',
                'answer'   => "Yes! SaluteHost cPanel features built-in application managers for both Node.js and Python:\n\n• **Node.js Deployment:**\n1. In cPanel, navigate to **Setup Node.js App** under Software.\n2. Click **Create Application**.\n3. Select your Node.js version (v18, v20, or v22), specify the Application Root folder and Application Startup File (`app.js` or `server.js`).\n4. Click **Run NPM Install** to install dependencies from `package.json`.\n\n• **Python Deployment:**\n1. In cPanel, click **Setup Python App**.\n2. Select your Python version (3.10, 3.11), set the Application Root and WSGI entry point, and click **Run Pip Install**.\n\nApplication restart and environment variables are managed directly within the cPanel interface.",
                'order'    => 12,
            ],
            [
                'category' => 'Web & Cloud Hosting',
                'question' => 'How do automatic backups work and how do I restore my website?',
                'answer'   => "SaluteHost provides daily automated snapshot backups stored securely off-server:\n\n1. **Restore with JetBackup 5 (Recommended):**\n   - Log in to **cPanel** > open **JetBackup 5**.\n   - Choose **File Backups**, **Database Backups**, or **Cron Jobs**.\n   - Browse the calendar snapshots, select the date you wish to restore, and click **Restore**. The system restores your files or database in minutes without overwriting unrelated data.\n2. **Download Local Backup:**\n   - In cPanel, open **Backup Wizard**.\n   - Click **Backup**, choose **Full Backup** or **Home Directory Backup**, and download the `.tar.gz` archive directly to your computer.",
                'order'    => 13,
            ],

            // SECTION 3: HERMES AI AGENT VPS (Order 14 - 18)
            [
                'category' => 'Hermes AI VPS',
                'question' => 'What is SaluteHost Hermes AI Agent VPS and what are its capabilities?',
                'answer'   => "SaluteHost **Hermes AI Agent VPS** is a dedicated cloud server engineered specifically to run autonomous AI agents and automated agentic loops 24/7:\n\n• **Pre-Configured AI Stack:** Includes Hermes Agent workspace, Docker runtime, Node.js, Python 3, Git, and system libraries pre-installed.\n• **Dedicated KVM Compute:** Dedicated high-frequency vCPU cores, dedicated RAM, and ultra-fast NVMe storage ensuring continuous high-speed execution.\n• **Autonomous Capabilities:** The agent can browse the live web, execute code in sandboxes, integrate with APIs, scrape real-time data, and run multi-step workflows in the background.\n• **Free Subdomain & SSL:** Each instance includes a dedicated subdomain (e.g. `hermes-xxxxx.salutehost.com`) with automated SSL certificates.",
                'order'    => 14,
            ],
            [
                'category' => 'Hermes AI VPS',
                'question' => 'How do I access Hermes Agent Web UI and SSH terminal?',
                'answer'   => "Once your Hermes VPS is provisioned, you can access it via browser or terminal:\n\n1. **Web Workspace GUI:**\n   - Log in to **SaluteHost Client Portal** (`salutehost.com/login`).\n   - Go to **AI Agent & n8n VPS** (`/client/hermes`) in the sidebar menu.\n   - Click the **Deploy Workspace GUI** (or **Open Web UI**) button to launch the browser interface on port 3000.\n2. **SSH Terminal Access (Port 22):**\n   - Connect using PuTTY, PowerShell, or macOS Terminal:\n     `ssh root@YOUR_SERVER_IP -p 22`\n   - Your root password and server IP are listed under **AI Agent & n8n VPS > Login Details** and in your activation email.\n   - You have full root access to install custom Python packages, models, and tools.",
                'order'    => 15,
            ],
            [
                'category' => 'Hermes AI VPS',
                'question' => 'How do I connect AI LLM APIs like OpenAI, Claude, or Gemini in Hermes VPS?',
                'answer'   => "Hermes Agent connects seamlessly to all leading AI model providers:\n\n1. Open your Hermes Web Dashboard and go to **Settings > LLM Configuration** (or edit the `.env` file via SSH).\n2. **Add Your API Key(s):**\n   - **OpenAI:** Enter your `OPENAI_API_KEY` to use GPT-4o and GPT-4 Turbo.\n   - **Anthropic:** Enter your `ANTHROPIC_API_KEY` to use Claude 3.5 Sonnet and Opus.\n   - **Google Gemini:** Enter your `GEMINI_API_KEY` to use Gemini 1.5 Pro and Flash.\n   - **OpenRouter / DeepSeek:** Provide custom baseURL and API key.\n3. Click **Save Configuration**. Hermes will instantly activate the model and begin processing your prompts and autonomous tasks.",
                'order'    => 16,
            ],
            [
                'category' => 'Hermes AI VPS',
                'question' => 'Does Hermes VPS keep persistent memory and run background tasks 24/7?',
                'answer'   => "Yes! 24/7 autonomous persistence is the core design of Hermes VPS:\n\n• **Persistent Long-Term Memory:** Agent context and learned instructions are stored in a persistent SQLite/PostgreSQL vector memory store. Old conversations and task histories are never lost, even if the VPS is rebooted.\n• **Continuous Background Execution:** The agent runs as an isolated systemd daemon service. It continues executing scheduled automations and monitoring tasks even after you close your browser or power off your PC.\n• **Cron Scheduling:** Linux cron jobs and automated event triggers are natively supported for scheduled scraping, alerts, and report generation.",
                'order'    => 17,
            ],
            [
                'category' => 'Hermes AI VPS',
                'question' => 'How do I point my custom domain or subdomain to Hermes VPS?',
                'answer'   => "You can bind your own domain (e.g. `agent.yourdomain.com`) to your Hermes VPS:\n\n1. In your domain provider (SaluteHost Client Portal > My Domains > DNS Management or cPanel Zone Editor), create an **A Record**:\n   - **Host / Name:** `agent` (or `@` for root)\n   - **Type:** `A`\n   - **Target / Value:** Enter your Hermes VPS Public IPv4 Address.\n2. In SaluteHost Client Portal, navigate to **AI Agent & n8n VPS**, select your instance, and enter your domain in the **Custom Domain** field.\n3. Save settings. Nginx reverse proxy and Let's Encrypt SSL certificates are automatically provisioned.",
                'order'    => 18,
            ],

            // SECTION 4: N8N AUTOMATION ENGINE VPS (Order 19 - 22)
            [
                'category' => 'n8n Automation VPS',
                'question' => 'What is SaluteHost n8n Automation VPS and how does it work?',
                'answer'   => "SaluteHost **n8n Automation VPS** is a fully managed, enterprise-grade workflow automation server deployed in under 60 seconds:\n\n• **Zero Execution Limits:** Unlike cloud n8n plans that charge per execution, your self-hosted SaluteHost n8n VPS allows **Unlimited Executions, Unlimited Workflows, and Unlimited Webhooks** with zero extra usage fees!\n• **Pre-Installed Stack:** Delivered pre-configured with Docker, n8n automation engine, PostgreSQL database, and automated SSL reverse proxy.\n• **Instant Free Subdomain:** Every plan includes a free instant subdomain (e.g. `n8n-cyqyq.salutehost.com`) so you can open the canvas editor right away in any browser.\n• **Ideal For:** AI chatbots, CRM sync, WhatsApp messaging, webhook alerts, payment automation, and API orchestrations.",
                'order'    => 19,
            ],
            [
                'category' => 'n8n Automation VPS',
                'question' => 'How do I access n8n Workflow Editor and complete the owner setup?',
                'answer'   => "Accessing your n8n visual canvas is instant:\n\n1. Log in to **SaluteHost Client Portal** (`salutehost.com/login`).\n2. Go to **AI Agent & n8n VPS** (`/client/hermes`) in the sidebar menu.\n3. Click **Open n8n Editor** (or open your assigned subdomain URL `https://n8n-xxxxx.salutehost.com` in your browser).\n4. **First-Time Setup:** On your first visit, the n8n Owner Registration screen will prompt you to enter your Name, Email, and a secure Password.\n5. Click **Set up** to enter your visual workflow editor. You can now import pre-built templates or build custom automation pipelines immediately.",
                'order'    => 20,
            ],
            [
                'category' => 'n8n Automation VPS',
                'question' => 'How do I configure Webhooks, Telegram Bots, and WhatsApp in n8n?',
                'answer'   => "Connecting external services and chat triggers in SaluteHost n8n is simple:\n\n• **Webhooks:** Add a **Webhook Trigger** node to your canvas. Because your n8n VPS has a pre-installed SSL certificate, your production webhook URL is an instant secure `https://` endpoint ready to receive events from Razorpay, Stripe, Shopify, or cPanel.\n• **Telegram Bot:** Create a bot with `@BotFather` on Telegram to receive your API token. Add the **Telegram** node in n8n, paste your token, and configure automated chat alerts or command responses.\n• **WhatsApp:** Use the **HTTP Request** node or Meta Cloud API nodes to send automated WhatsApp messages, order updates, and two-way conversations.",
                'order'    => 21,
            ],
            [
                'category' => 'n8n Automation VPS',
                'question' => 'Can I upgrade my n8n VPS resources like vCPU and RAM when workflow volume grows?',
                'answer'   => "Yes! You can scale your n8n VPS resources seamlessly as your automations grow:\n\n1. Log in to **SaluteHost Client Portal > AI Agent & n8n VPS**.\n2. Locate your instance and click **Upgrade / Downgrade Plan**.\n3. Select your desired tier (e.g. scale from 2-Core / 4GB RAM to 4-Core / 8GB RAM Turbo).\n4. Pay the prorated difference. The server resources will be scaled dynamically, and all your existing workflows, credentials, databases, and webhook links remain 100% intact with zero data loss.",
                'order'    => 22,
            ],

            // SECTION 5: BILLING, INVOICES, WALLET & RENEWALS (Order 23 - 28)
            [
                'category' => 'Billing & Wallet',
                'question' => 'What payment methods can I use to pay invoices on SaluteHost?',
                'answer'   => "SaluteHost supports all major Indian and International payment methods with instant automated activation:\n\n1. **UPI & Dynamic QR Code:** Google Pay, PhonePe, Paytm, BHIM, and any UPI app. Scan the dynamic QR code on checkout for instant payment confirmation in 10 seconds with 0% extra fees.\n2. **SaluteHost Account Wallet:** Use pre-funded prepaid wallet balance for 1-click renewals and instant checkouts.\n3. **Debit & Credit Cards:** Visa, MasterCard, RuPay, and American Express.\n4. **Net Banking:** Supported across 50+ major banks in India.\n\nAll transactions are secured with 256-Bit SSL encryption.",
                'order'    => 23,
            ],
            [
                'category' => 'Billing & Wallet',
                'question' => 'How does SaluteHost Wallet work and how do I add money to it?',
                'answer'   => "SaluteHost Wallet is a prepaid credit balance designed for 1-click purchases and seamless automatic renewals:\n\n1. Log in to **SaluteHost Client Portal** (`salutehost.com/login`).\n2. Click **My Wallet** (`/client/wallet`) in the left sidebar menu.\n3. Click the **+ Add Money** button.\n4. Enter your desired deposit amount (from ₹10 upwards).\n5. Complete payment using the instant UPI QR code. The funds are credited to your wallet balance instantly.\n6. **Key Benefit:** Invoices and domain renewals can be paid with 1 click without going through bank OTPs or gateway redirects.",
                'order'    => 24,
            ],
            [
                'category' => 'Billing & Wallet',
                'question' => 'What should I do if my payment was deducted from my bank but the invoice shows unpaid?',
                'answer'   => "If your bank account was debited but the invoice did not mark as Paid automatically, it is usually caused by a delayed bank gateway webhook:\n\n1. **Wait 5–10 Minutes:** Most banks synchronize delayed UPI transactions within a few minutes, after which the invoice automatically activates.\n2. **Payment Verification:** If the status remains pending, click **Support Tickets** (`/client/support`) in your Client Portal and submit a quick ticket under the **Billing** department.\n3. Include your **Order/Invoice ID** and the **UPI UTR Reference Number** (12-digit transaction number from PhonePe/GPay). Our billing team will verify and activate your service immediately.",
                'order'    => 25,
            ],
            [
                'category' => 'Billing & Wallet',
                'question' => 'Where can I view or download official GST tax invoices and payment receipts?',
                'answer'   => "You can view and download official invoices anytime:\n\n1. Log in to **SaluteHost Client Portal** (`salutehost.com/login`).\n2. Click **My Orders** (or **Payments & Billing**) in the left sidebar menu.\n3. Click on your invoice or order number (e.g. `Order #28`).\n4. On the order detail page, click the **Print Receipt / Download PDF** button in the upper right.\n5. All completed invoices display official GST tax breakdowns, payment transaction IDs (UTR), and a green **COMPLETED / PAID** verification stamp.",
                'order'    => 26,
            ],
            [
                'category' => 'Billing & Wallet',
                'question' => 'How does service renewal work and where can I check my next due date?',
                'answer'   => "SaluteHost offers transparent, hassle-free renewals:\n\n1. **Check Due Date:** In your Client Portal, go to **Web Hosting**, **My Domains**, or **AI Agent & n8n VPS**. Each active service card clearly displays your **Billing Cycle & Next Due Date**.\n2. **Renewal Invoices:** The system generates an automated renewal invoice 7 days prior to your due date and sends an email reminder.\n3. **Manual Renewal:** Click the **Renew** button on your service card, select your renewal tenure (1 Month, 1 Year, etc.), and complete payment via Wallet or UPI.\n4. **Auto-Renewal:** If you keep sufficient balance in your SaluteHost Wallet, the system can automatically settle due renewals on their due date so your services never go offline.",
                'order'    => 27,
            ],
            [
                'category' => 'Billing & Wallet',
                'question' => 'What is SaluteHost refund policy and 7-Day Money-Back Guarantee?',
                'answer'   => "SaluteHost provides a customer-first, transparent refund policy:\n\n• **Web & Cloud Hosting:** All shared and cloud hosting packages are backed by a **7-Day Unconditional Money-Back Guarantee**. If you are not satisfied with performance, open a support ticket within 7 days of purchase for a 100% refund.\n• **Domains & Registry Addons:** Domain registrations and renewals are strictly non-refundable once processed, as registry authorities (.in, .com, ICANN) immediately lock the registration fee upon allocation.\n• **Processing Time:** Approved refunds are processed back to your original payment method or credited to your SaluteHost Wallet within 2 to 5 business days.",
                'order'    => 28,
            ],

            // SECTION 6: SUPPORT, SECURITY & TROUBLESHOOTING (Order 29 - 32)
            [
                'category' => 'Support & Troubleshooting',
                'question' => 'Why is my website showing 403 Forbidden or 500 Internal Server Error and how do I fix it?',
                'answer'   => "These common web server errors can be diagnosed easily in cPanel:\n\n• **403 Forbidden Error (Permission / Index Issue):**\n1. **Check Directory Index:** Open **cPanel > File Manager > public_html** and ensure an `index.php` or `index.html` file exists in lowercase.\n2. **Check File Permissions:** Ensure all folders are set to permission `755` and all files to `644`. Permissions like `777` or `000` trigger 403 blocks.\n3. **Check .htaccess:** Temporarily rename your `.htaccess` file to `.htaccess.bak` to see if a rewrite rule or IP block is causing the error.\n\n• **500 Internal Server Error (PHP / Script Crash):**\n1. In **cPanel > File Manager**, open `public_html/error_log` to see the exact PHP error and line number.\n2. Go to **cPanel > Select PHP Version > Options** and increase `memory_limit` to `512M`.",
                'order'    => 29,
            ],
            [
                'category' => 'Support & Troubleshooting',
                'question' => 'How do I submit a support ticket and select my related service?',
                'answer'   => "Submitting a technical support ticket in the Client Portal ensures fast resolution:\n\n1. Log in to **SaluteHost Client Portal** (`salutehost.com/login`).\n2. Click **Support Tickets** (`/client/support`) in the left sidebar menu.\n3. Click the blue **Open Ticket** button.\n4. **Ticket Subject:** Enter a concise summary of your issue (e.g. \"AutoSSL verification issue on domain\").\n5. **Related Service:** Select your specific web hosting, domain, or VPS from the dropdown so engineers can inspect your server configuration directly.\n6. **Department & Priority:** Select Technical Support, Billing, or Sales, attach screenshots if needed, and click **Submit Ticket**.\n\nAverage ticket resolution time is under 15–30 minutes!",
                'order'    => 30,
            ],
            [
                'category' => 'Support & Troubleshooting',
                'question' => 'Why is my service suspended and how do I restore it using Pay & Unsuspend?',
                'answer'   => "Services are suspended for one of two reasons:\n\n1. **Overdue / Unpaid Renewal:** If an invoice is not settled by the due date, the system places the service into temporary suspension. In your Client Portal, you will see a red **⚡ Pay & Unsuspend** button. Once the invoice is paid via UPI or Wallet, the server is automatically **Reactivated within seconds** with all data completely preserved.\n2. **Abuse / Security Suspension:** If phishing, spam, or high resource abuse is reported, administrator suspension is applied. In this case, please open a ticket with the **Technical Support** department for security review and unlocking.",
                'order'    => 31,
            ],
            [
                'category' => 'Support & Troubleshooting',
                'question' => 'Do you provide free website migration and 24/7 live human assistance?',
                'answer'   => "Yes! SaluteHost offers complimentary website migration and 24/7 human technical support:\n\n• **Free Website Migration:** We transfer your cPanel backup, WordPress websites, databases, and emails from your previous hosting provider to SaluteHost completely free with zero downtime. Simply open a support ticket with your previous provider's cPanel login details.\n• **24/7 Live Support Channels:**\n  1. **Live Chat:** Type \"Talk to Agent\" or click the chat bubble right here on the website to connect with an engineer.\n  2. **WhatsApp Support:** Reach our senior technical team on WhatsApp at **+91 9709910760** for fast responses.\n  3. **Ticket Desk:** Create a formal ticket via **Client Portal > Support Tickets** for detailed technical troubleshooting.",
                'order'    => 32,
            ],
        ];
    }
}
