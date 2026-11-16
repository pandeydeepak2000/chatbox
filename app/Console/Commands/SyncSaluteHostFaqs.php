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
            $this->info("Property {$property->public_key} now has {$count} FAQs!");
        }

        $this->info('All SaluteHost FAQs synchronized successfully!');
        return Command::SUCCESS;
    }

    /**
     * SaluteHost 32 Master FAQs definition
     */
    public function getSaluteHostFaqs(): array
    {
        return [
            // SECTION 1: DOMAINS & DNS (Order 1 - 5)
            [
                'category' => 'Domains & DNS',
                'question' => 'How can I buy or register a new domain name on SaluteHost? / SaluteHost पर नया डोमेन कैसे खरीदें?',
                'answer'   => "SaluteHost par domain register karna instant aur automatic hai:\n\n1. **Search Domain:** SaluteHost ke Domain Search page par jayein (`salutehost.com/domains`) aur apna manpasand domain type karein (e.g. `yourbrand.com` ya `yourbrand.in`).\n2. **Cart:** Availability check karke **Add to Cart** par click karein.\n3. **Billing Cycle:** 1 Year se 10 Years tak ka registration period choose karein.\n4. **Payment:** UPI, Dynamic QR Code, Card ya SaluteHost Wallet balance se checkout karein.\n5. **Instant Activation:** Payment hote hi domain registry par lock ho jata hai aur Dashboard mein **My Domains** section mein manage karne ke liye live ho jata hai.\n\n💡 **Pricing & Feature:** .IN Domain ₹399/year aur .COM Domain ₹899/year with **Free Lifetime WHOIS Privacy Protection**!",
                'order'    => 1,
            ],
            [
                'category' => 'Domains & DNS',
                'question' => 'How do I transfer my domain from another registrar to SaluteHost? / Kisi dusre registrar se domain SaluteHost par kaise transfer karein?',
                'answer'   => "Apna domain bina kisi website downtime ke SaluteHost par transfer karne ke steps:\n\n1. **Unlock Domain:** Apne current registrar (GoDaddy, Namecheap, Hostinger, etc.) panel mein jakar Domain Transfer Lock ko **OFF / Unlocked** karein.\n2. **EPP / Auth Code:** Purane registrar se EPP/Authorization code generate karke copy karein.\n3. **Transfer Order:** SaluteHost ke **Transfer Domain** page par jayein, domain naam aur EPP code enter karein.\n4. **Checkout:** 1-Year renewal fee pay karein (transfer ke sath aapko 1 additional year validity free add hoti hai).\n5. **Registry Approval:** Registry approval hote hi 5 se 7 dinon ke andar domain automatically SaluteHost par transfer ho jata hai.",
                'order'    => 2,
            ],
            [
                'category' => 'Domains & DNS',
                'question' => 'What are SaluteHost official nameservers and how do I point my domain? / SaluteHost ke nameservers kya hain aur domain kaise point karein?',
                'answer'   => "SaluteHost ke ultra-fast Anycast nameservers hain:\n\n• **Primary Nameserver (NS1):** `ns1.salutehost.com`\n• **Secondary Nameserver (NS2):** `ns2.salutehost.com`\n\n**Nameservers update karne ke steps:**\n1. Jahan se domain khareeda hai us panel mein login karein (ya SaluteHost Client Portal > **My Domains** > **Manage Nameservers**).\n2. **Custom Nameservers** select karein aur `ns1.salutehost.com` aur `ns2.salutehost.com` save karein.\n\n⏳ Global DNS propagation mein 15 minute se 4 ghante tak ka samay lag sakta hai.",
                'order'    => 3,
            ],
            [
                'category' => 'Domains & DNS',
                'question' => 'How do I manage DNS records like A record, CNAME, TXT, or MX records? / DNS Records (A, CNAME, MX, TXT) kaise add karein?',
                'answer'   => "DNS records manage karne ke 2 aasan methods hain:\n\n• **Method 1 - cPanel Zone Editor (Hosting ke sath):**\n1. cPanel mein login karein aur **Zone Editor** tool open karein.\n2. Apne domain ke samne **Manage** par click karein.\n3. **+ Add Record** par click karke A record (Server IP ke liye), CNAME (subdomain ke liye), ya TXT/MX record (Google Workspace / Zoho Mail verification ke liye) save karein.\n\n• **Method 2 - Client Portal DNS (Only Domain ke liye):**\nClient Portal > **My Domains** > **DNS Management** mein jakar direct records manage kar sakte hain.",
                'order'    => 4,
            ],
            [
                'category' => 'Domains & DNS',
                'question' => 'How does domain renewal work and what is the expiry grace period? / Domain renewal kaise karein aur expiry ke baad kya hota hai?',
                'answer'   => "SaluteHost par domain renewal process:\n\n1. **Renewal Steps:** Client Portal > **My Domains** > Apne domain ke samne **Renew** button par click karein > 1 se 10 saal ka tenure chunein > UPI ya Wallet se pay karein.\n2. **Auto-Debit:** Agar aapke SaluteHost Wallet mein balance hai, toh due date par automatic renewal trigger ho sakta hai.\n3. **Grace Period:** Expiry date ke baad domain 30 dinon ke liye Grace Period mein rehta hai jahan standard fee par renew ho jata hai. Uske baad Redemption Period shuru hota hai jisme heavy penalty lagti hai, isliye due date se pehle renew karein.",
                'order'    => 5,
            ],

            // SECTION 2: WEB & CLOUD HOSTING (Order 6 - 13)
            [
                'category' => 'Web & Cloud Hosting',
                'question' => 'What web hosting plans does SaluteHost offer and what are the features? / SaluteHost ke hosting plans aur features kya hain?',
                'answer'   => "SaluteHost high-performance Cloud NVMe SSD Web Hosting provide karta hai:\n\n• **Cloud Starter Plan:** 1 Website, 10GB NVMe SSD, Unmetered Bandwidth, Free Lifetime SSL, 5 Business Emails, cPanel control panel.\n• **Cloud Standard Plan:** 3 Websites, 30GB Pure NVMe SSD, Free SSL, Unlimited Business Emails, Daily Automated Backups.\n• **Business Turbo Plan:** Unlimited Websites, 100GB NVMe SSD, 4GB RAM & 2 vCPU isolation, CloudLinux OS, LiteSpeed caching.\n\n⭐ Sabhi plans 99.9% Uptime Guarantee, 1-Click Softaculous Apps, Tier-4 Data Center aur 24/7 Live Support ke sath aate hain!",
                'order'    => 6,
            ],
            [
                'category' => 'Web & Cloud Hosting',
                'question' => 'How do I access my cPanel dashboard or reset my cPanel password? / cPanel login ya password reset kaise karein?',
                'answer'   => "cPanel access karne ke 2 simple options hain:\n\n• **1-Click SSO Login (No Password Needed):** SaluteHost Client Portal (`salutehost.com/login`) par jayein > **Web Hosting** > Apne active package ke samne orange **cPanel Login** button par click karein. Yeh direct single-sign-on se bina credentials dale cPanel open kar deta hai.\n• **Direct URL:** Browser mein `yourdomain.com:2083` ya `cpanel.yourdomain.com` open karke apna cPanel username aur password enter karein.\n\n🔑 **Password Reset:** Client Portal mein hosting account ke andar **Login Details / Change Password** par click karke naya password set karein.",
                'order'    => 7,
            ],
            [
                'category' => 'Web & Cloud Hosting',
                'question' => 'How to install WordPress in cPanel using Softaculous in 1-Click? / cPanel mein 1 minute mein WordPress kaise install karein?',
                'answer'   => "Softaculous se WordPress install karne ke simple steps:\n\n1. cPanel dashboard mein login karein.\n2. **Softaculous Apps Installer** section mein jayein aur **WordPress** par click karein.\n3. **Install Now** button dabayein.\n4. **Protocol:** `https://` choose karein aur apna domain select karein. (Agar root site par chahiye toh 'In Directory' field ko khali chhodein).\n5. **Admin Account:** Apna Admin Username, secure Password, aur apna working Email address enter karein.\n6. Neeche **Install** button par click karein. 30 seconds ke andar website live ho jayegi aur aap `yourdomain.com/wp-admin` par login kar sakenge!",
                'order'    => 8,
            ],
            [
                'category' => 'Web & Cloud Hosting',
                'question' => 'How do I activate Free SSL Certificate and force HTTPS? / Free SSL Certificate kaise activate karein aur HTTPS enable karein?',
                'answer'   => "SaluteHost ke sabhi hosting plans mein **Free Lifetime AutoSSL (Let's Encrypt)** shamil hota hai:\n\n1. Make sure aapka domain SaluteHost ke nameservers (`ns1.salutehost.com` & `ns2.salutehost.com`) par point kar raha ho.\n2. Nameserver point hote hi 15 se 30 minute mein AutoSSL automatically certificate issue kar deta hai.\n3. **Manual Run:** cPanel mein jakar **SSL/TLS Status** search karein > apna domain select karein > blue **Run AutoSSL** button click karein.\n4. **Force HTTPS Redirect:** cPanel ke **Domains** section mein jakar 'Force HTTPS Redirect' toggle ko **ON** kar dein taaki sari visitors secure `https://` par redirect ho jayein.",
                'order'    => 9,
            ],
            [
                'category' => 'Web & Cloud Hosting',
                'question' => 'How do I create a professional email (info@mybrand.com) and login to Webmail? / Professional business email kaise banayein aur Webmail access karein?',
                'answer'   => "Apne custom domain par business email create karne ke steps:\n\n1. cPanel mein login karein aur **Email Accounts** tool open karein.\n2. Blue **+ Create** button par click karein.\n3. Domain choose karein, username dalein (jaise `support`, `info`, `sales`, `contact`) aur strong password enter karein.\n4. Storage limit set karein aur **Create** par click karein.\n\n📬 **Webmail Access:** Browser mein `yourdomain.com/webmail` ya `webmail.yourdomain.com` open karein, pura email ID aur password enter karein aur Roundcube interface se email send/receive karein.",
                'order'    => 10,
            ],
            [
                'category' => 'Web & Cloud Hosting',
                'question' => 'How can I change the PHP version (PHP 7.4 to 8.3) and increase memory limit? / PHP version kaise change karein aur memory limit badhayein?',
                'answer'   => "SaluteHost par modern PHP versions (PHP 7.4, 8.0, 8.1, 8.2, 8.3) fully supported hain:\n\n1. cPanel open karein aur search bar mein **Select PHP Version** (ya MultiPHP Manager) type karein.\n2. Current PHP version dropdown se naya version select karein (e.g. PHP 8.2 ya 8.3) aur **Apply** karein.\n3. **Extensions:** 'Extensions' tab par click karke zaroori modules jaise `curl`, `gd`, `zip`, `fileinfo`, `imagick`, `mysqli`, `intl`, `pdo_mysql` enable karein.\n4. **Memory & Upload Limit:** 'Options' tab mein jayein aur `memory_limit` ko `512M` aur `upload_max_filesize` ko `128M` ya `256M` par set karein.",
                'order'    => 11,
            ],
            [
                'category' => 'Web & Cloud Hosting',
                'question' => 'Can I host Node.js and Python applications on SaluteHost cPanel? / Kya Node.js aur Python apps host kar sakte hain?',
                'answer'   => "Haan! SaluteHost cPanel par Node.js aur Python dono built-in runners ke sath aate hain:\n\n• **Node.js Setup:** cPanel mein **Setup Node.js App** par jayein > **Create Application** par click karein > Node.js version choose karein (v18, v20, v22) > Application root aur startup file (`app.js` ya `server.js`) define karein > Run NPM Install karein.\n• **Python Setup:** cPanel mein **Setup Python App** par jayein > Python version (3.10, 3.11) select karein > WSGI file aur application root specify karein > Run Pip Install karein.\n\nEnvironment variables aur application restart cPanel UI se instantly manage ho jate hain.",
                'order'    => 12,
            ],
            [
                'category' => 'Web & Cloud Hosting',
                'question' => 'How do automatic backups work and how can I restore or download my website? / Automated backups aur 1-click restore kaise kaam karta hai?',
                'answer'   => "SaluteHost par cloud storage par daily automated snapshot backups run hote hain:\n\n1. **JetBackup 5 (Recommended):** cPanel mein **JetBackup 5** tool open karein > 'File Backups', 'Database Backups', ya 'Cron Jobs' select karein > Desired date ka snapshot choose karein aur **Restore** par click karein. Kuch hi minutes mein data restore ho jata hai without any data loss.\n2. **Manual Download:** cPanel ke **Backup Wizard** mein jakar Full Backup ya Home Directory backup `.tar.gz` format mein apne personal computer par download kar sakte hain.",
                'order'    => 13,
            ],

            // SECTION 3: HERMES AI AGENT VPS (Order 14 - 18)
            [
                'category' => 'Hermes AI VPS',
                'question' => 'What is SaluteHost Hermes AI Agent VPS and what are its capabilities? / Hermes AI Agent VPS kya hai aur isme kya features hain?',
                'answer'   => "SaluteHost **Hermes AI Agent VPS** ek dedicated, pre-configured cloud environment hai jo autonomous AI agents ko 24/7 run karne ke liye design kiya gaya hai:\n\n• **Pre-installed Stack:** Hermes Agent workspace, Docker runtime, Node.js, Python 3, Git, aur system dependencies pehle se installed aur configured milti hain.\n• **Dedicated Compute:** Pure KVM virtualization, dedicated high-frequency vCPU cores, aur ultra-fast NVMe storage.\n• **Autonomous Workflows:** Yeh agent continuous web browsing, API integrations, data scraping, code execution, aur automated tasks background mein bina band hue execute karta hai.\n• **Subdomain Included:** Har instance ko ek custom subdomain (e.g. `hermes-xxxxx.salutehost.com`) milta hai with SSL enabled.",
                'order'    => 14,
            ],
            [
                'category' => 'Hermes AI VPS',
                'question' => 'How do I access Hermes Agent Web UI and SSH Terminal? / Hermes Agent Web Dashboard aur SSH Terminal kaise open karein?',
                'answer'   => "Hermes VPS deploy hote hi aapko credentials aur access URLs instant mil jaate hain:\n\n1. **Web UI (Port 3000):** Apne browser mein assigned subdomain open karein (e.g. `https://hermes-xxxxx.salutehost.com:3000` ya standard web proxy port). SaluteHost Client Portal > **AI Agent & n8n VPS** mein jakar direct 1-click **Open Web UI** par click kar sakte hain.\n2. **SSH Terminal Access (Port 22):** PuTTY ya Terminal se connect karein:\n`ssh root@YOUR_SERVER_IP -p 22`\nPassword aapke service activation email aur Client Portal ke 'Login Details' tab mein available hota hai.\n3. **Root Privileges:** Aapke paas full root access hota hai jisse aap koi bhi packages, python libraries ya models install kar sakte hain.",
                'order'    => 15,
            ],
            [
                'category' => 'Hermes AI VPS',
                'question' => 'How do I connect AI LLM APIs (OpenAI, Claude, Gemini, OpenRouter) in Hermes VPS? / Hermes mein AI API keys kaise connect karein?',
                'answer'   => "Hermes Agent sabhi leading AI models aur LLM providers ko support karta hai:\n\n1. Hermes Web Dashboard par jayein aur **Settings > LLM Configuration** open karein (ya SSH terminal mein `.env` file edit karein).\n2. **Supported Providers:**\n• **OpenAI:** GPT-4o, GPT-4 Turbo (Enter `OPENAI_API_KEY`)\n• **Anthropic:** Claude 3.5 Sonnet / Opus (Enter `ANTHROPIC_API_KEY`)\n• **Google Gemini:** Gemini 1.5 Pro / Flash (Enter `GEMINI_API_KEY`)\n• **OpenRouter / DeepSeek:** Custom baseURL aur API key enter karein.\n3. Save configuration karein. Agent turant naye model se connect hokar prompts aur complex agentic loops run karne ke liye ready ho jata hai.",
                'order'    => 16,
            ],
            [
                'category' => 'Hermes AI VPS',
                'question' => 'Does Hermes VPS keep persistent memory and run cron jobs 24/7? / Kya Hermes VPS 24/7 background mein run hota hai aur memory save rehti hai?',
                'answer'   => "Haan! Hermes VPS ki sabse badi khasiyat yahi hai:\n\n• **Persistent Long-Term Memory:** SQLite / PostgreSQL memory vector database instance mein saved rehti hai. Server restart hone ke baad bhi agent purani conversations, tasks aur learned instructions nahi bhulta.\n• **24/7 Uninterrupted Background Run:** Agent background daemons aur systemd services par chalta hai. Aap apna browser ya computer band kar dein, tab bhi agent scheduled tasks, monitoring aur bot operations continuously run karta rehta hai.\n• **Cron Scheduling:** Linux cron jobs aur scheduled agent triggers built-in support hote hain.",
                'order'    => 17,
            ],
            [
                'category' => 'Hermes AI VPS',
                'question' => 'How do I point my own custom domain to Hermes VPS? / Apna custom domain Hermes VPS se kaise bind karein?',
                'answer'   => "Apna custom domain (e.g. `agent.yourdomain.com`) Hermes VPS par point karne ke steps:\n\n1. Apne domain provider (cPanel Zone Editor ya Cloudflare) mein jayein.\n2. Ek naya **A Record** add karein:\n• **Name / Host:** `agent` (ya `@` root ke liye)\n• **Type:** `A`\n• **Value / IP:** Apne Hermes VPS ka Public IP enter karein.\n3. SaluteHost Client Portal > **AI Agent & n8n VPS** > **Manage Instance** mein jakar 'Custom Domain' field mein apna domain enter karke Save karein.\n4. Nginx reverse proxy aur Let's Encrypt SSL certificate automatic configure ho jayega.",
                'order'    => 18,
            ],

            // SECTION 4: N8N AUTOMATION ENGINE VPS (Order 19 - 22)
            [
                'category' => 'n8n Automation VPS',
                'question' => 'What is SaluteHost n8n Automation VPS and how is it deployed? / n8n Automation VPS kya hai aur setup kaise hota hai?',
                'answer'   => "SaluteHost **n8n Automation VPS** ek pre-installed, enterprise-grade workflow automation server hai:\n\n• **Instant 60-Second Setup:** Order karte hi Dockerized n8n engine, PostgreSQL database, aur secure HTTPS reverse proxy automatically deploy ho jate hain.\n• **Zero Execution Limits:** Cloud n8n ki tarah per-execution billing ka koi jhanjhat nahi. Aap jitne chahein workflows, cron jobs aur webhooks run karein bina kisi extra charge ke!\n• **Subdomain Included:** Har plan ke sath instant free subdomain milta hai (e.g. `n8n-cyqyq.salutehost.com`) jo direct browser mein ready-to-use open hota hai.",
                'order'    => 19,
            ],
            [
                'category' => 'n8n Automation VPS',
                'question' => 'How do I access n8n Workflow Editor and set up my admin account? / n8n Web Editor login aur first-time setup kaise karein?',
                'answer'   => "n8n Web Editor open karne ke simple steps:\n\n1. SaluteHost Client Portal > **AI Agent & n8n VPS** section mein jayein.\n2. Apne active n8n service ke samne **Open n8n Editor** button par click karein (ya browser mein apna assigned subdomain `https://n8n-xxxxx.salutehost.com` open karein).\n3. **First-Time Setup:** Pehli baar open karte hi n8n ka owner registration screen aayega. Apna Name, Email, aur strong Password enter karke **Setup** complete karein.\n4. Ab aapka n8n canvas live hai aur aap hazaron pre-built templates aur custom workflows build karna shuru kar sakte hain!",
                'order'    => 20,
            ],
            [
                'category' => 'n8n Automation VPS',
                'question' => 'How do I configure Webhooks, Telegram Bots, and WhatsApp in n8n? / n8n mein Webhooks aur Telegram/WhatsApp bots kaise connect karein?',
                'answer'   => "n8n par webhooks aur chat bots build karna ultra-smooth hai:\n\n• **Webhooks:** Canvas mein **Webhook Trigger** node add karein. SaluteHost n8n VPS par valid SSL pehle se hota hai, isliye production webhook URL instant secure `https://` endpoint provide karta hai jo Stripe, Razorpay, Shopify, aur cPanel events catch kar sakta hai.\n• **Telegram Bot:** Telegram par `@BotFather` se bot token banayein aur n8n ke 'Telegram' node mein credentials paste karke automated alerts aur commands enable karein.\n• **WhatsApp API:** Meta Cloud API ya unofficial WhatsApp gateways ke HTTP Request nodes add karke automated messaging setup karein.",
                'order'    => 21,
            ],
            [
                'category' => 'n8n Automation VPS',
                'question' => 'Can I upgrade my n8n VPS resources (vCPU & RAM) when workflow volume grows? / n8n VPS ko upgrade kaise karein?',
                'answer'   => "Haan! Jaise jaise aapki business automations badhti hain, aap seamlessly upgrade kar sakte hain:\n\n1. Client Portal > **AI Agent & n8n VPS** > Apne plan par **Upgrade / Downgrade** par click karein.\n2. Naya tier select karein (e.g. 2-Core se 4-Core / 8GB RAM Turbo).\n3. Difference amount pay karein. Server resources automatic scale ho jayenge aur aapke purane workflows, credentials aur databases bilkul intact rahenge bina kisi data loss ke.",
                'order'    => 22,
            ],

            // SECTION 5: BILLING, RENEWALS, WALLET & ACCOUNT (Order 23 - 28)
            [
                'category' => 'Billing & Wallet',
                'question' => 'What payment options can I use to pay invoices? / Payment ke kaun se options available hain?',
                'answer'   => "SaluteHost par sabhi popular Indian aur International payment methods supported hain:\n\n1. **UPI:** Google Pay, PhonePe, Paytm, BHIM, Amazon Pay (Instant confirmation with 0% extra gateway fees).\n2. **Dynamic QR Code:** Checkout screen par dynamic QR scan karein aur 10 seconds mein automatic service activation payein.\n3. **SaluteHost Account Wallet:** Instant 1-click renewals aur fast checkouts ke liye pre-funded wallet balance.\n4. **Debit/Credit Cards & Net Banking:** Visa, MasterCard, RuPay cards aur 50+ Indian banks ki Net Banking.\n\nSabhi transactions 256-Bit SSL encrypted aur fully automated hain.",
                'order'    => 23,
            ],
            [
                'category' => 'Billing & Wallet',
                'question' => 'How does SaluteHost Wallet work and how do I add money to it? / SaluteHost Wallet kya hai aur paise kaise add karein?',
                'answer'   => "SaluteHost Wallet ek fast pre-funded account balance hai jo checkout aur renewals ko instant banata hai:\n\n1. **Add Money:** Client Portal mein top bar ya left sidebar se **My Wallet** (ya **+ Add Money**) par click karein.\n2. **Amount:** Minimum ₹10 se lekar apni zaroorat ka amount enter karein.\n3. **Payment:** UPI QR scan karein ya UPI ID se pay karein. Paisa instantly wallet balance mein credit ho jata hai.\n4. **Benefits:** Renewal ke waqt baar baar OTP ya bank login nahi karna padta, 1-click mein invoice paid mark ho jati hai!",
                'order'    => 24,
            ],
            [
                'category' => 'Billing & Wallet',
                'question' => 'How does Service Renewal work and where can I check my Next Due Date? / Service Renewal kaise hoti hai aur Next Due Date kahan dekhein?',
                'answer'   => "SaluteHost par renewal cycle transparent aur automated hai:\n\n1. **Next Due Date Check:** Client Portal ke **Web Hosting**, **My Domains**, ya **AI Agent & n8n VPS** section mein jayein. Har active product ke samne **Billing & Next Due** date clearly mention hoti hai.\n2. **Invoice Generation:** Due date se 7 din pehle automated renewal invoice generate hoti hai aur email notification send hota hai.\n3. **Renew Button:** Service ke samne diye gaye blue **Renew** button par click karke aap tenure (1 Month, 1 Year, etc.) select karke payment complete kar sakte hain.",
                'order'    => 25,
            ],
            [
                'category' => 'Billing & Wallet',
                'question' => 'Why is my service Suspended and how do I use Pay & Unsuspend to restore it? / Service Suspend kyun hui aur Pay & Unsuspend kaise kaam karta hai?',
                'answer'   => "Service suspension ke 2 main reasons hote hain:\n\n1. **Overdue / Unpaid Renewal:** Agar invoice due date par pay nahi hoti, toh system service ko temporarily suspend kar deta hai. Aise case mein service ke samne red color ka **⚡ Pay & Unsuspend** button show hota hai. Jaise hi aap invoice pay karte hain, server automatically seconds mein **ACTIVE** ho jata hai with zero data loss.\n2. **Abuse / Policy Violation:** Agar website par malware, phishing, ya resource abuse detect hota hai, toh admin suspension lagta hai. Is case mein 'Pay & Unsuspend' button nahi dikhega; aapko **Support Ticket** open karke security verification clear karani hogi.",
                'order'    => 26,
            ],
            [
                'category' => 'Billing & Wallet',
                'question' => 'Where can I view or download my official invoices and GST receipts? / Invoice aur payment receipt kaise download karein?',
                'answer'   => "Official GST invoices download karne ke steps:\n\n1. SaluteHost Client Portal (`salutehost.com/login`) par login karein.\n2. Left sidebar se **My Orders** (ya **Payment History**) par click karein.\n3. Apne Order / Invoice number (e.g. `Order #28`) par click karein.\n4. Detail breakdown page par right side me **Print Receipt** / **Download PDF** button par click karein.\n5. Paid invoices par green **COMPLETED / PAID** stamp aur payment transaction reference UTR clearly recorded hota hai.",
                'order'    => 27,
            ],
            [
                'category' => 'Billing & Wallet',
                'question' => 'What is SaluteHost refund policy and 7-Day Money-Back Guarantee? / SaluteHost ki refund policy aur money-back guarantee kya hai?',
                'answer'   => "SaluteHost ki transparent aur customer-friendly refund policy:\n\n• **Web & Cloud Hosting:** Sabhi Web Hosting aur Cloud plans par **7-Day Money-Back Guarantee** milti hai. Agar aap hamari server performance se satisfied nahi hain, toh 7 dinon ke andar ticket raise karke 100% refund claim kar sakte hain.\n• **Domains & Registry Addons:** Domain registration aur registry renewals non-refundable hote hain kyunki registry authority (.in, .com) instantly registration fee lock kar leti hai.\n• **Refund Processing:** Refund approved hone ke baad 2-5 business days mein aapke original payment source ya SaluteHost Wallet mein credit ho jata.",
                'order'    => 28,
            ],

            // SECTION 6: SUPPORT & TROUBLESHOOTING (Order 29 - 32)
            [
                'category' => 'Support & Troubleshooting',
                'question' => 'Why is my website showing 403 Forbidden error and how do I fix it? / 403 Forbidden Error kaise fix karein?',
                'answer'   => "403 Forbidden website par directory permissions ya file access block hone par aata hai. Ise theek karne ke 3 steps:\n\n1. **Check Index File:** cPanel File Manager mein `public_html` directory open karein aur ensure karein ki `index.php` ya `index.html` file maujood hai.\n2. **File Permissions:** Sabhi folders ki permission `755` aur files ki permission `644` honi chahiye. Kisi bhi file par `000` ya `777` permission hone se server 403 error throw karta hai.\n3. **.htaccess Rules:** Agar `.htaccess` mein wrong IP ban ya RewriteRule hai, toh file ko temporarily rename karke `.htaccess.bak` karein aur website refresh karein.",
                'order'    => 29,
            ],
            [
                'category' => 'Support & Troubleshooting',
                'question' => 'How to fix 500 Internal Server Error or Error Establishing Database Connection? / 500 Error ya Database error kaise fix karein?',
                'answer'   => "• **500 Internal Server Error:**\n1. cPanel File Manager mein `public_html/error_log` file open karein. Wahan exact PHP syntax error aur line number mil jayega.\n2. cPanel mein **Select PHP Version > Options** mein jakar `memory_limit` ko `512M` karein.\n\n• **Error Establishing a Database Connection:**\n1. Configuration file check karein (WordPress ke liye `wp-config.php`, Laravel ke liye `.env`).\n2. DB_NAME, DB_USER, aur DB_PASSWORD verify karein.\n3. cPanel > **MySQL Databases** mein jakar ensure karein ki Database User database se linked hai aur **ALL PRIVILEGES** granted hain.",
                'order'    => 30,
            ],
            [
                'category' => 'Support & Troubleshooting',
                'question' => 'How do I open a Support Ticket and link my Related Service (Hosting, Domain, VPS)? / Support Ticket kaise open karein aur service kaise link karein?',
                'answer'   => "Support Ticket submit karne ke steps:\n\n1. Client Portal mein login karein aur left menu se **Support Tickets** par click karein.\n2. Blue **Open Ticket** button dabayein.\n3. **Ticket Subject:** Issue ka brief title enter karein (e.g. SSL Issue, DNS update).\n4. **Related Service:** Dropdown se apna specific server, domain, Hermes VPS ya n8n VPS select karein taaki engineering team direct usi service ko diagnose kar sake.\n5. **Department:** Technical Support, Billing, ya Sales chunein, screenshot attach karein aur **Submit Ticket** par click karein.\n\nAvg ticket response time: Under 15-30 minutes!",
                'order'    => 31,
            ],
            [
                'category' => 'Support & Troubleshooting',
                'question' => 'How can I connect with 24/7 Human Support on WhatsApp or Live Chat? / Human Support ya WhatsApp se direct baat kaise karein?',
                'answer'   => "SaluteHost par real human engineering team 24/7 assist karne ke liye available hai:\n\n1. **WhatsApp Instant Support:** Direct hamari senior technical team se WhatsApp par chat karein: **+91 9709910760** (Average response time < 5 minutes).\n2. **Live Chat Takeover:** ISI chatbox widget mein jab aapko human agent se baat karni ho, bas 'Talk to Agent' ya 'Human Help' type karein ya button click karein. Hamara live operator turant conversation take over kar lega.\n3. **Support Desk:** SaluteHost Client Portal ke **Support Tickets** desk se formal issue logging karein.",
                'order'    => 32,
            ],
        ];
    }
}
