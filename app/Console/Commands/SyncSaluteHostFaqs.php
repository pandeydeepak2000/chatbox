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
                'category'  => 'Domains',
                'question'  => 'How can I buy or register a new domain name on SaluteHost? / SaluteHost पर नया डोमेन कैसे खरीदें?',
                'answer'    => "SaluteHost par domain book karna behad aasan hai:\n\n1. SaluteHost Domain Search page (`salutehost.com/domains`) par apna manpasand domain naam search karein (e.g. `yourbrand.in` ya `yourbrand.com`).\n2. Agar domain available hai, toh **Add to Cart** par click karein.\n3. Checkout page par jayein, billing details fill karein aur payment method chunein (UPI / QR / Cards / Wallet).\n4. Payment complete hote hi domain instantly register ho jata hai aur aapke Client Portal mein active dikhayi dene lagta hai.",
                'order'     => 1,
            ],
            [
                'category'  => 'Domains',
                'question'  => 'How do I transfer my domain from another registrar to SaluteHost? / Kisi dusre registrar se domain SaluteHost par kaise transfer karein?',
                'answer'    => "Kisi dusre registrar (GoDaddy, Hostinger, BigRock etc.) se SaluteHost par domain transfer karne ke steps:\n\n1. **Unlock Domain:** Apne purane registrar ke panel mein jakar domain ka Registrar Lock 'Disabled' karein.\n2. **Get EPP / Auth Code:** Purane registrar se domain ka Authorization/EPP Code prapt karein.\n3. **Initiate Transfer:** SaluteHost Client Area mein **Transfer Domain** section par jayein, apna domain name aur EPP Code enter karein.\n4. **Payment:** 1 Year extension fee pay karein. Transfer process shuru ho jayegi aur 5-7 business days mein bina kisi downtime ke domain SaluteHost par transfer ho jayega.",
                'order'     => 2,
            ],
            [
                'category'  => 'Domains',
                'question'  => 'What are SaluteHost official nameservers and how do I point my domain? / SaluteHost ke nameservers kya hain aur domain kaise point karein?',
                'answer'    => "SaluteHost hosting ke primary official nameservers yeh hain:\n\n• **Nameserver 1:** `ns1.salutehost.com`\n• **Nameserver 2:** `ns2.salutehost.com`\n\n**Kaise Point Karein:**\n1. Jisme aapka domain registered hai us registrar ke DNS/Nameservers settings mein jayein.\n2. 'Custom Nameservers' option chunein.\n3. Upar diye gaye dono nameservers paste karein aur Save karein.\n*Note:* Global DNS propagation mein 10 minute se lekar maximum 24 ghante tak lag sakte hain.",
                'order'     => 3,
            ],
            [
                'category'  => 'Domains',
                'question'  => 'How do I manage DNS records like A record, CNAME, or MX records? / DNS Records (A Record, CNAME) kaise add karein?',
                'answer'    => "SaluteHost par DNS manage karne ke 2 aasan tareeqe hain:\n\n1. **cPanel Zone Editor se (Recommended agar hosting active hai):**\n• cPanel login karein > **Domains** section mein **Zone Editor** open karein.\n• Apne domain ke samne **Manage** par click karein.\n• **+ Add Record** par click karke Record Type chunein (A, CNAME, MX, TXT) aur IP/Value daalkar Save karein.\n\n2. **Client Portal DNS Management se:**\n• Client Area > My Domains > DNS Management mein jakar direct records configure kar sakte hain.",
                'order'     => 4,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'What web hosting plans are available and what are their features? / SaluteHost ke hosting plans aur features kya hain?',
                'answer'    => "SaluteHost provide karta hai ultra-fast NVMe SSD powered hosting solutions:\n\n• **Starter Hosting:** Single website, 10 GB Pure NVMe SSD, Free SSL, Unmetered Bandwidth, 5 Business Emails. Beginners ke liye best.\n• **Advanced / Business Hosting:** Multiple websites, 50 GB NVMe SSD, Free Domain (Annual plans), LiteSpeed Web Server, 2x CPU & RAM.\n• **Cloud VPS Hosting:** Dedicated resources (KVM Virtualization), Full Root Access, Tier-4 Data Centers, 1 Gbps Uplink, DDoS Protection.\n\nSabhi plans ke sath **99.9% Uptime SLA**, **Instant 60-Second Setup**, aur **24/7 Priority Support** milta hai.",
                'order'     => 5,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'How to install WordPress in cPanel using Softaculous? / cPanel mein WordPress kaise install karein?',
                'answer'    => "1-Click WordPress installation steps:\n\n1. Apne cPanel dashboard mein login karein.\n2. Page ke bottom par scroll karein aur **Softaculous Apps Installer** section mein **WordPress** icon par click karein.\n3. **Install Now** button dabayein.\n4. **Software Setup:** Domain chunein (`https://yourdomain.com`). 'In Directory' field ko KHALI (blank) chhod dein agar main website par install karna hai.\n5. **Admin Account:** Apna Admin Username, ek strong Password, aur working Email ID dalein.\n6. Page ke end mein **Install** button par click karein. 30 seconds ke andar aapka WordPress site live ho jayega!",
                'order'     => 6,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'How do I activate Free SSL Certificate and enable HTTPS? / Free SSL Certificate kaise activate karein?',
                'answer'    => "SaluteHost par sabhi active domains ke liye Let's Encrypt / AutoSSL bilkul FREE milta hai:\n\n1. **Auto SSL:** Domain nameservers point hone ke 15-30 minute ke andar cPanel AutoSSL automatic run hokar certificate install kar deta hai.\n2. **Manual Force Run:** cPanel mein login karein > **SSL/TLS Status** par jayein > Domain select karein aur **Run AutoSSL** button par click karein.\n3. **Force HTTPS:** cPanel ke **Domains** tool mein jakar 'Force HTTPS Redirect' toggle ko ON kar dein taaki har visitor automatic secure `https://` par redirect ho.",
                'order'     => 7,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'How do I create a professional email (info@mybrand.com) and login to Webmail? / Business email kaise banayein aur Webmail login kaise karein?',
                'answer'    => "• **Professional Email Create Karna:**\n1. cPanel open karein aur **Email Accounts** par click karein.\n2. **+ Create** button par click karein.\n3. Domain select karein, username dalein (jaise `support`, `info`, `admin`) aur ek strong password set karein.\n4. **Create** button dabayein.\n\n• **Webmail Login Karna:**\nBrowser mein `yourdomain.com/webmail` open karein, apna poora email address aur password enter karke **Roundcube** se emails read/send karein.",
                'order'     => 8,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'How do I access my cPanel dashboard or reset my cPanel password? / cPanel login ya password reset kaise karein?',
                'answer'    => "• **Direct Login:** Browser mein `yourdomain.com:2083` ya `cpanel.yourdomain.com` open karein aur hosting welcome email mein mila username aur password dalein.\n\n• **1-Click Single Sign-On (SSO):** SaluteHost Client Portal (`salutehost.com/login`) par login karein > **Active Services** > Hosting plan par click karein > Left sidebar se **Login to cPanel** par click karein bina kisi password ke!\n\n• **Password Reset:** Client Portal se 'Change Password' option se direct naya cPanel password set kar sakte hain.",
                'order'     => 9,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'How can I change the PHP version (PHP 8.1, 8.2, 8.3) and enable PHP modules? / PHP version kaise change karein?',
                'answer'    => "SaluteHost modern PHP versions support karta hai:\n\n1. cPanel mein jayein aur **Select PHP Version** (ya **MultiPHP Manager**) tool open karein.\n2. Dropdown se apna manpasand version select karein (e.g. **PHP 8.2** ya **PHP 8.3**).\n3. 'Apply' par click karein.\n4. **Extensions / Modules:** 'Extensions' tab mein jakar required extensions jaise `pdo_mysql`, `curl`, `gd`, `zip`, `fileinfo`, `sodium`, `imagick` ko checkbox tick karke enable karein.\n5. **Options:** Memory limit (e.g. 512M) aur `upload_max_filesize` (e.g. 128M) bhi yahin se adjust kar sakte hain.",
                'order'     => 10,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'Can I host Node.js and Python applications on SaluteHost? / Kya Node.js aur Python apps host kar sakte hain?',
                'answer'    => "Haan! SaluteHost Cloud & Business plans mein built-in application runners available hain:\n\n• **Node.js:** cPanel ke **Setup Node.js App** tool mein jakar Node version (v18, v20, v22) chunein, App root aur startup file specify karein aur `npm install` run karein.\n• **Python (Django / Flask):** cPanel ke **Setup Python App** tool se Python 3.10/3.11 environment create karein.\n• **Full Flexibility ke liye:** Hamara Cloud VPS plan use karein jisme full root access ke sath Docker, PM2, Redis, aur koi bhi custom stack run kiya ja sakta hai.",
                'order'     => 11,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'How does website backup work and how can I restore or download my files? / Website backup kaise download ya restore karein?',
                'answer'    => "SaluteHost par automated daily aur weekly backup system active rehta hai:\n\n1. **JetBackup / cPanel Backup:** cPanel login karein aur **Backup** ya **JetBackup 5** icon par click karein.\n2. **Download Backup:** 'Download a Full Account Backup' par click karke backup archive apne computer par download kar sakte hain.\n3. **1-Click Restore:** JetBackup tool se aap Files, Cron Jobs, DNS Zones, ya MySQL Databases ko kisi bhi previous date par 1-click mein restore kar sakte hain bina kisi data loss ke.",
                'order'     => 12,
            ],
            [
                'category'  => 'Technical Support',
                'question'  => 'Why is my website showing 403 Forbidden error and how do I fix it? / 403 Forbidden Error kaise fix karein?',
                'answer'    => "403 Forbidden error aane ke mukhya kaaran aur solutions:\n\n1. **Index File Missing:** Ensure karein ki aapke `public_html` directory mein `index.html` ya `index.php` file maujood ho.\n2. **File Permissions:** Sabhi folders ki permission `755` aur sabhi files ki permission `644` honi chahiye. cPanel File Manager mein right click karke 'Change Permissions' check karein.\n3. **.htaccess Rule Conflict:** `public_html/.htaccess` file ka naam temporarily rename karke `.htaccess.bak` karein. Agar site chal jaye toh purani htaccess file ke deny rules check karein.",
                'order'     => 13,
            ],
            [
                'category'  => 'Technical Support',
                'question'  => 'How to fix 500 Internal Server Error or Error Establishing Database Connection? / 500 Error ya Database error kaise fix karein?',
                'answer'    => "• **500 Internal Server Error:**\n1. cPanel File Manager mein `public_html/error_log` file open karein. Wahan exact PHP error line number ke sath likha hoga.\n2. `.htaccess` file mein syntax error check karein.\n3. PHP Memory Limit ko cPanel ke 'Select PHP Version > Options' mein jakar `512M` par increase karein.\n\n• **Error Establishing a Database Connection:**\n1. WordPress ki `wp-config.php` (ya Laravel ki `.env`) file check karein.\n2. DB_NAME, DB_USER, aur DB_PASSWORD verify karein.\n3. cPanel ke 'MySQL Databases' mein jakar confirm karein ki DB User us Database se linked hai aur uske paas **ALL PRIVILEGES** granted hain.",
                'order'     => 14,
            ],
            [
                'category'  => 'Billing & Payments',
                'question'  => 'How does SaluteHost Wallet work and how do I add money to it? / SaluteHost Wallet kya hai aur paise kaise dalein?',
                'answer'    => "SaluteHost Wallet ek fast, pre-funded digital balance hai jo checkout aur renewals ko instant banata hai:\n\n1. **Add Funds:** Client Portal mein login karein > **Wallet & Funds** > **Add Money** par click karein.\n2. **Amount:** Minimum ₹10 se lekar apni zaroorat ka amount enter karein.\n3. **Pay via UPI/QR:** Dynamic QR code scan karein ya PhonePe/GPay se pay karein. Paisa turant aapke wallet mein credit ho jata hai.\n4. **1-Click Checkout:** Agli baar koi bhi domain, hosting plan khareedte waqt ya invoice renew karte waqt aap direct 'Pay with Wallet' choose kar sakte hain bina OTP ya bank login ke!",
                'order'     => 15,
            ],
            [
                'category'  => 'Billing & Payments',
                'question'  => 'What payment options can I use to pay invoices? / Payment ke kaun se options available hain?',
                'answer'    => "SaluteHost sabhi popular Indian aur International payment methods support karta hai:\n\n1. **UPI:** Google Pay, PhonePe, Paytm, BHIM, Amazon Pay (Zero extra transaction fees).\n2. **Dynamic QR Code:** Checkout screen par QR code scan karke 10 second mein payment complete karein.\n3. **SaluteHost Wallet:** Instant one-click checkout ke liye pre-loaded wallet balance.\n4. **Net Banking & Cards:** Sabhi major Indian banks ki Net Banking, Visa, MasterCard, aur RuPay debit/credit cards.\n\nSabhi payments fully automated hain aur invoices payment hote hi turant 'Paid' mark ho jaate hain!",
                'order'     => 16,
            ],
            [
                'category'  => 'Technical Support',
                'question'  => 'What is SaluteHost refund policy and money-back guarantee? / SaluteHost ki refund policy kya hai?',
                'answer'    => "SaluteHost ki transparent refund policy:\n\n• **Web Hosting:** Sabhi Web Hosting aur Cloud Hosting plans par **7-Day Money-Back Guarantee** milti hai. Agar aap hamari hosting performance se santusht nahi hain, toh 7 dinon ke andar ticket raise karke 100% refund claim kar sakte hain.\n• **Domains & Licenses:** Domain name registration aur registry renewals non-refundable hote hain, kyunki registry authority (.in registry, Verisign .com) turant fees collect kar leti hai aur domain aapke naam par 1 year ke liye lock ho jata hai.\n\nRefund amount aapke original payment source ya SaluteHost Wallet mein 2-5 business days mein transfer ho jata.",
                'order'     => 17,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'How do I add a subdomain or host multiple websites on my hosting? / Subdomain ya Addon domain kaise banayein?',
                'answer'    => "• **Subdomain Banana (jaise blog.mybrand.com):**\n1. cPanel mein login karein aur **Domains** tool open karein.\n2. 'Create A New Domain' par click karein.\n3. Subdomain naam enter karein (e.g. `blog.yourdomain.com`).\n4. Document root automatic set ho jayega (e.g. `public_html/blog`). Submit par click karein.\n\n• **Addon Domain (Multiple Websites):**\nAgar aapka plan multi-site allowed karta hai, toh 'Create A New Domain' mein jakar apna naya domain naam enter karein, 'Share document root' ko UNCHECK karein, aur alag folder specify karein.",
                'order'     => 18,
            ],
            [
                'category'  => 'Web Hosting',
                'question'  => 'How do I upload my website files using cPanel File Manager or FileZilla FTP? / Website files kaise upload karein?',
                'answer'    => "Website files upload karne ke 2 methods:\n\n1. **cPanel File Manager (Easiest):** cPanel > 'File Manager' open karein > `public_html` directory mein jayein > Upar diye gaye **Upload** button par click karein > Apni website ki `.zip` file select karein > Upload hone ke baad right click karke **Extract** karein.\n2. **FTP (FileZilla):**\n• Host: Aapka domain ya Server IP\n• Username: Aapka cPanel username\n• Password: Aapka cPanel password\n• Port: `21`\nConnect hone ke baad files ko `public_html` folder mein drag & drop karein.",
                'order'     => 19,
            ],
            [
                'category'  => 'Technical Support',
                'question'  => 'How can I connect with a real human support engineer at SaluteHost? / Customer Care ya Human Support se baat kaise karein?',
                'answer'    => "SaluteHost par human assistance 24/7 uplabdh hai:\n\n1. **WhatsApp Instant Support:** Direct hamari senior technical team se WhatsApp par chat karein: **+91 9709910760** (Average response time < 5 minutes).\n2. **Support Ticket:** SaluteHost Client Portal (`salutehost.com/login`) par jayein > **Support Tickets** > **Open Ticket** par click karein > Department chunein (Billing, Technical, Sales) aur issue submit karein.\n3. **Live Chat Takeover:** ISI chatbox mein jab aapko human help chahiye ho, upar diye gaye 'Human Help' link par click karein ya agent ke judne ka wait karein.",
                'order'     => 20,
            ],
        ];
    }
}
