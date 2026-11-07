# ChatBox — cPanel Shared Hosting Deployment (full release)

This supersedes the credential and seeding steps in `CPANEL_DEPLOYMENT_GUIDE.md`. ChatBox needs **no VPS, Redis, WebSocket server, Node.js runtime or SSH daemon**: chat uses AJAX polling, background work uses the database queue driven by one cron job, and the admin dashboard is a pre-built static site served by Laravel.

> Nothing in this guide has been run against the live server yet. Do the first run on a staging subdomain with a copy of the database.

## 0. Before you start

- [ ] **Back up** the live database (phpMyAdmin → Export) and the current files.
- [ ] Create a **staging subdomain** and import the database copy there.
- [ ] Have new values ready for every secret listed in `docs/PHASE3_REPORT.md` §8. Old secrets are in git history and must be rotated.

## 1. Check the hosting environment

| Requirement | How to check in cPanel |
|---|---|
| PHP ≥ 8.2 for both web and **CLI** (cron uses the CLI binary) | *Select PHP Version* / *MultiPHP Manager*; in Terminal run `php -v` and `/usr/local/bin/php -v` (the path differs per host; note the one that shows 8.2+) |
| Extensions: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `zip`, `xmlwriter`, `dom`, `ctype`, `curl`, `bcmath` | *Select PHP Version → Extensions* |
| MySQL ≥ 5.7 / MariaDB ≥ 10.3 (InnoDB FULLTEXT is used for knowledge search) | *MySQL Databases* page |
| `upload_max_filesize` and `post_max_size` ≥ 12M, `memory_limit` ≥ 256M | *MultiPHP INI Editor* |
| Cron jobs allowed | *Cron Jobs* page is visible |
| Outbound HTTPS allowed (Telegram, the AI provider, webhooks) | Ask the host if unsure |

After uploading (step 2) and creating `.env` (step 3), run the built-in check, which prints every item above with OK / MISSING:

```bash
php artisan chatbox:env-check
```

## 2. Upload the code

The repository already contains `vendor/` (OpenSpout, smalot/pdfparser, the Anthropic PHP SDK and the Symfony security patches), so Composer is not needed on the server. The committed `vendor/` also contains development tools (PHPUnit, Faker, Pint). They are not reachable from the web, but if the host offers Composer in Terminal, run `composer install --no-dev --optimize-autoloader` for a leaner install. It also contains the **built dashboard in `public/app/`**, so Node.js is not needed on the server either.

**Recommended (Option A):** create a subdomain, e.g. `chat.example.com`, with **document root = `chatbox/public`**. Upload the project to `~/chatbox`.

**Option B (document root = project root):** the root `.htaccess` only serves files from `public/` and sends everything else to Laravel. After deploying, verify (step 8). This option has not been tested on Apache yet.

Do **not** upload `salutehost_chatbox.sql`, `scratch/`, `.env.production`, `tests/`, `node_modules/` or `frontend/` (the dashboard source; only `public/app/` is needed).

### Rebuilding the dashboard (on your own computer, only after changing `frontend/`)

```bash
cd frontend
npm ci
npm run build
npm run deploy:local     # copies frontend/out → public/app
```

Then upload `public/app/` again.

## 3. Configure `.env`

```bash
cd ~/chatbox
cp .env.example .env
php artisan key:generate        # unique key for this server (re-enter webhook secrets, Telegram tokens and AI keys afterwards)
```

Fill in `APP_URL`, `DB_*` and `MAIL_*`. Keep these values:

| Key | Value | Why |
|---|---|---|
| `BROADCAST_CONNECTION` | `null` | No WebSocket server on cPanel; the widget and dashboard poll |
| `QUEUE_CONNECTION` | `database` | Processed by the cron job |
| `CACHE_STORE` | `database` | No Redis |
| `CHATBOX_PUBLIC_REGISTRATION` | `false` | Companies are created by the super admin |
| `CHATBOX_DEFAULT_PLAN` | `pro` (or `internal` for AmbitionHost itself) | Plan for companies created without a subscription |
| `CHATBOX_DASHBOARD_ORIGINS` | empty when the dashboard is on the same domain | Extra origins allowed to call the staff API |
| `CHATBOX_DEMO_PROPERTY_KEY` | optional | Widget shown on the home page |
| `CHATBOX_LEGACY_DASHBOARD` | `false` | Old dashboard off (security) |
| `CHATBOX_TRUSTED_PROXIES` | empty, or Cloudflare IP ranges | Real visitor IPs for rate limits; never `*` |

AI provider keys and Telegram bot tokens are **not** in `.env`. Each company enters them in the dashboard (*AI Settings*, *Notifications*), and they are stored encrypted with `APP_KEY`.

## 4. Migrate (additive; backup first)

```bash
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

All 13 new migrations only add tables and columns (the last two, from the security review, add `users.website_access` and `ai_budgets.reserved_usd`). No existing rows are changed, except that:
- existing websites get `identity_mode = guest_only`, so live widgets behave as before until you switch on the Support PIN;
- leads are backfilled from existing visitors;
- subscriptions are created from each company's current `plan`.

Make sure `storage/` and `bootstrap/cache/` are writable (755 directories / 644 files). Attachments and knowledge uploads are stored in `storage/app/private`, outside the web root.

First install only: run `php artisan chatbox:clean-accounts --force` to create the master accounts. Random passwords are printed **once**; store them in a password manager. **Do not run it on an existing installation unless you intend to**: it removes the known demo accounts (`admin@chatbox.com`, `rahul@chatbox.com`, `priya@chatbox.com`).

## 5. One cron job (cPanel → Cron Jobs → every minute)

```
* * * * * cd /home/USERNAME/chatbox && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

Use the CLI PHP path you noted in step 1. This single line runs:

| Every | Task |
|---|---|
| minute | `queue:work --stop-when-empty --max-time=50` (knowledge file processing, deliveries) |
| minute | `chatbox:reminders`: quotation reminders (10 m / 30 m / 2 h), 30-working-minute sales alert, Telegram retries |
| minute | `chatbox:process-ai`: AI replies that could not finish during the web request |
| 10:00 IST daily | `chatbox:daily-digest`: unresolved tickets and pending tasks |
| hourly | `chatbox:maintenance`: deletes uploads never sent in a chat (after 24 h), marks stuck document processing as failed |
| daily | expired staff tokens and old failed jobs are pruned |

`chatbox:env-check` shows when the cron last ran.

## 6. Configure each company (in the dashboard)

1. **Company Settings**: working hours (default 09:00–18:00 IST, every day), ticket prefix, sales follow-up person.
2. **Websites & Widget**: admin URL slug, allowed domains, customer sign-in mode (guest only / optional Support PIN / PIN required), widget colours. Copy the embed code to the website.
3. **Team & Departments**: staff, which websites each may access, and their role per website.
4. **Notifications**: add a Telegram bot token + chat ID, then press *Send test*.
5. **Knowledge Base**: add or upload documents, then **approve** them. Drafts are never used for answers.
6. **AI Settings** (optional): provider, model, API key, monthly / daily budget. AI stays off until switched on. FAQs and human hand-off work without it.

## 7. Rotate secrets

Follow `docs/PHASE3_REPORT.md` §8: new DB and mail passwords, **Regenerate Keys** for every website (then update the embed code on each site), new staff passwords, and webhook secrets re-entered in *Websites & Widget → Webhook*.

## 8. Verify after deployment

| Check | Expected |
|---|---|
| `php artisan chatbox:env-check` | Everything OK, `BROADCAST_CONNECTION` = null, cron ran within the last minutes |
| `/salutehost_chatbox.sql`, `/.env`, `/vendor/autoload.php`, `/scratch/` | 403 or 404 |
| `/uploads/attachments/anything.html` | 403 |
| `/app` | Dashboard sign-in page |
| `/{website-slug}/admin` | Sign-in page branded with that website; staff without access to it are refused |
| Website → chat → ask an FAQ question | Instant answer |
| Website → chat → *Talk to a human* outside working hours | Ticket reference (e.g. `AMB-000001`) shown; Telegram alert arrives |
| Reply from *Support Tickets* | Customer sees it in the widget within a few seconds; internal notes never appear |
| *Continue Previous Support* in another browser with e-mail + PIN | Same conversations and tickets |
| *Leads* → Download Excel / CSV | Files contain only the websites you can access |

## 9. Rollback

```bash
php artisan migrate:rollback --step=13 --force   # removes only the tables/columns added by this release
```

Then restore the previous files. Leads, tickets, customer accounts and knowledge created after the upgrade are lost on rollback, so restore from the backup if needed.
