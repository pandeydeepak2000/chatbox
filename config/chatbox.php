<?php

return [

    /*
    | Allow anyone to self-register a new company workspace from the login page.
    | Off by default: there is no e-mail verification for new companies yet (audit M6).
    | Platform super admins can always create companies from the platform panel.
    */
    'public_registration' => (bool) env('CHATBOX_PUBLIC_REGISTRATION', false),

    // The old Blade dashboard (/legacy-dashboard). Off by default: the new dashboard (/app) replaces
    // it, and every extra admin page is extra attack surface. Set true only as a temporary fallback.
    'legacy_dashboard' => (bool) env('CHATBOX_LEGACY_DASHBOARD', false),

    // Behind Cloudflare or another reverse proxy, list its IP ranges (comma separated) so rate
    // limits see the real visitor IP. Never use "*" — then anyone could fake their IP.
    'trusted_proxies' => env('CHATBOX_TRUSTED_PROXIES', ''),

    // Plan given to self-registered companies (plans are managed in the Super Admin panel).
    'default_plan' => env('CHATBOX_DEFAULT_PLAN', 'pro'),

    /*
    | Origins (scheme://host[:port]) allowed to call the STAFF API cross-origin,
    | e.g. a Next.js dev server. The dashboard served by Laravel itself is same-origin
    | and needs nothing here. Widget endpoints are always cross-origin enabled.
    */
    'dashboard_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CHATBOX_DASHBOARD_ORIGINS', ''))))),

    // Website whose widget is shown on the ChatBox home/demo page (public key). Empty = first active website.
    'demo_property_key' => env('CHATBOX_DEMO_PROPERTY_KEY', ''),

    'attachments' => [
        // Anonymous visitors: files per visitor per day and total MB per website per day.
        'visitor_daily_files' => (int) env('CHATBOX_UPLOADS_PER_VISITOR_DAY', 20),
        'website_daily_mb' => (int) env('CHATBOX_UPLOAD_MB_PER_WEBSITE_DAY', 300),

        // Sniffed MIME type => stored extension. Anything else is rejected.
        'allowed' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/zip' => 'zip',
        ],
        'inline_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'max_kb' => (int) env('CHATBOX_ATTACHMENT_MAX_KB', 10240),
        // Lifetime of signed download links handed to browsers (minutes).
        'link_ttl' => (int) env('CHATBOX_ATTACHMENT_LINK_TTL', 1440),
    ],

    'transcripts' => [
        'per_conversation_per_hour' => 3,
        'per_ip_per_day' => 10,
    ],

    // Telegram reminders every 30 minutes for tickets waiting for SUPPORT action. A ticket that waits only for the
    // customer is not repeated to the team unless this is switched on.
    'reminders' => [
        'include_waiting_customer' => (bool) env('CHATBOX_REMIND_WAITING_CUSTOMER', false),
    ],
];
