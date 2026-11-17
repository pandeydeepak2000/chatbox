# ChatBox: architecture and developer handbook

Read this first if you are new to the project. It explains how the system is put together, where each feature lives in the code, which rules must not be broken, and how to build, test and deploy safely.

Last updated: 17 Nov 2026 (`main` at `1141cc0`). Deeper notes: [PREMIUM_SUPPORT_FLOW.md](PREMIUM_SUPPORT_FLOW.md) (support workflow, AI providers), [WORKFLOW_GUIDE.md](WORKFLOW_GUIDE.md) (end-user workflow), [CPANEL_DEPLOYMENT.md](CPANEL_DEPLOYMENT.md), [SECURITY_REVIEW.md](SECURITY_REVIEW.md), and the phase reports `PHASE1_AUDIT.md` … `PHASE4_COMPLETE_REPORT.md` (history).

---

## 1. What the product is

ChatBox is a **multi-company (multi-tenant) SaaS** for customer support:

- A **chat widget** (`public/widget.js`) that a company embeds on its websites.
- A **staff dashboard** where each company's team answers chats, works tickets and leads, manages knowledge and AI.
- An **assistant** that answers from the company's approved FAQs and knowledge, using the company's own AI provider key, and hands over to humans.
- **Telegram alerts** for the internal team.
- A **platform super admin** who manages companies and plans, but cannot see any company's private data.

Production: `https://chat.salutehost.com`. Tenants include SaluteHost and AmbitionHost. They are ordinary customers of the platform: neither is "the platform".

## 2. Hard constraints (read before changing anything)

| Constraint | Why | What it means for you |
|---|---|---|
| **cPanel shared hosting only** | That is the production host | No Redis, no WebSocket server (Reverb/Pusher), no Supervisor, no Node.js at runtime. Real-time = **polling**. Background work = **one cron line** running `php artisan schedule:run` every minute, or `dispatch()->afterResponse()`. |
| **Dashboard is a static export** | No Node on the server | The Next.js app in `frontend/` is built locally into `public/app/` and **committed**. Laravel serves `public/app/index.html`. |
| **Every migration is additive and reversible** | Live data | Add columns/tables, backfill by copying, never drop or rewrite customer data in `up()`. Every migration has a working `down()`. Test on MariaDB 10.4 (production engine), not only SQLite. |
| **Tenant isolation** | Many companies share one DB | Every query on company data is scoped by `tenant_id` (directly or through `properties.tenant_id`). Never trust an id from the request without checking it belongs to the user's company. |
| **Platform admin ≠ company data** | Privacy | Company-data routes use the `company.data` middleware (`DenyPlatformAdmin`) and return 403 for the super admin. |
| **Secrets never leave the server** | Security | API keys and bot tokens are encrypted model attributes, hidden from JSON, never logged, never returned. |
| **Git workflow** | Production pulls `main` | Work on a feature branch; merge to `main` only with the owner's approval; no force-push. Production deploys with `git pull origin main`. |

## 3. Technology

| Layer | Technology |
|---|---|
| Backend | Laravel 12, PHP 8.2 |
| Database | MariaDB 10.4 / MySQL in production; SQLite in-memory for tests |
| Auth (staff) | Laravel Sanctum bearer tokens (7-day expiry, `SANCTUM_EXPIRATION`) |
| Auth (customers) | Own e-mail + PIN accounts per website (`CustomerAuthService`), OTP for recovery |
| Dashboard | Next.js (static export, React, `lucide-react` icons), source in `frontend/` |
| Widget | Plain JavaScript, `public/widget.js`, no build step |
| Queue | `database` queue drained by cron (`queue:work --stop-when-empty`) |
| AI | OpenAI-compatible HTTP client (OpenAI, Gemini, OrvAPI, custom) and the Anthropic SDK |
| Spreadsheets | OpenSpout (XLSX), own CSV writer with formula-injection protection |

> **Next.js note:** `frontend/` uses a recent Next.js with breaking changes. Read `frontend/AGENTS.md` and the docs in `frontend/node_modules/next/dist/docs/` before changing framework-level code.

## 4. Repository map

```
app/
  Console/Commands/      artisan commands (class based)
  Http/Controllers/Api/  all JSON endpoints (one controller per area)
  Http/Middleware/       EnsureRole (role:), DenyPlatformAdmin (company.data), SecurityHeaders (CSP), AntiBotShield
  Jobs/                  ProcessKnowledgeVersion (document → chunks)
  Models/                Eloquent models (UUID primary keys)
  Services/              business logic (controllers stay thin)
    Ai/                  assistant, providers, budgets, review
    Knowledge/           document extraction, chunking, search
  Support/               small pure helpers (safe text, URL guard, origin checks, spreadsheets)
bootstrap/app.php        middleware aliases (role, company.data), exception handling
config/chatbox.php       all ChatBox-specific settings (env driven)
database/migrations/     schema history (additive)
frontend/                Next.js dashboard source (built into public/app)
  app/                   Next.js entry (page.js renders views/App.js)
  views/                 one file per dashboard page
  components/ui.js       shared UI kit (Button, Field, Select, useApi, useAction, toasts…)
  lib/api.js             fetch wrapper + token storage
  scripts/copy-build.mjs copies the export to public/app and records CSP hashes
public/
  app/                   BUILT dashboard (committed)
  widget.js              customer widget
  demo.html              demo page used by "/"
routes/
  api.php                REST API under /api/v1
  web.php                dashboard, company addresses, demo
  console.php            closure commands + the schedule
tests/
  Feature/               HTTP-level tests per feature
  Feature/Security/      audit regression tests
  Support/ChatboxFixtures.php  shared fixtures (two companies, sites, staff, startChat())
  TestCase.php           freezes the clock (2026-11-05 22:30 IST, outside working hours)
docs/                    this file and the other guides
```

## 5. Data model

All main tables use UUID keys. The tenant boundary is `tenants`.

```
tenants (company: name, slug, ticket_prefix, ticket_counter, lead_counter, settings.working_hours, status)
 ├─ users (staff; role = admin | agent; super_admin has tenant_id NULL)
 │    └─ property_user (which websites a restricted agent may see)
 ├─ properties (websites: public_key, slug, allowed_domains, widget_config, identity_mode)
 │    ├─ departments ─ department_user
 │    ├─ faqs, canned_responses
 │    ├─ visitors (widget visitors; optional customer_account_id)
 │    │    └─ conversations (status, handler = ai|human, support_state, chat_reference)
 │    │         ├─ messages (seq strictly increasing per conversation)
 │    │         ├─ conversation_state_logs, escalations, attachments
 │    │         └─ lead_conversation ─ leads ─ lead_notes
 │    └─ customer_accounts (e-mail + PIN) ─ customer_otps, customer_sessions
 ├─ chat_sessions (CH-<PREFIX>-XXXXXX, one per opened chat)
 ├─ tickets (reference <PREFIX>-000001) ─ ticket_events, ticket_assignments, ticket_reminders
 ├─ tasks ─ task_reminders (sales follow-ups)
 ├─ notification_channels (Telegram) ─ notification_deliveries (durable, deduplicated)
 ├─ staff_notifications (dashboard bell)
 ├─ knowledge_documents ─ knowledge_versions ─ knowledge_chunks
 ├─ ai_settings (active provider/model/key mirror), ai_providers ─ ai_models, ai_budgets, ai_usage_counters, ai_usage_logs
 ├─ ai_answer_reviews, ai_regression_cases
 └─ subscriptions ─ plans
Platform-wide: plans, platform_settings, ai_model_prices, audit_logs, personal_access_tokens, jobs, cache, sessions
```

## 6. Request flows

### 6.1 Customer widget

```
widget.js on customer site
  POST /api/v1/widget/init      → website config (public fields only), visitor token
  POST /api/v1/widget/session   → chat_session + Telegram "chat started" (before PIN/e-mail)
  POST /api/v1/widget/start     → conversation + first message
  POST /api/v1/widget/messages  → message; SupportBot::handle() decides the reply
  GET  /api/v1/widget/conversations/{id}/sync?after_seq=N   ← polling (2.5 s while waiting, slower idle)
```
Every widget endpoint checks the **`Origin` header** against the website's `allowed_domains` (`Support/WidgetOrigin`) and has per-visitor and per-IP rate limits (`AppServiceProvider`). The widget never receives internal state names, secrets or Telegram data.

### 6.2 Assistant decision order (`Services/Ai/SupportBot::handle`)

1. Conversation already with a human (`handler = human`)? → bot stays silent.
2. Greeting / thanks / bye / "how are you" → fixed free reply.
3. Customer wants a person, or a restricted action (renewal, refund…) → **escalation** (6.3).
4. FAQ match (`faqMatch`) → FAQ answer (free).
5. Clearly off-topic (`ServiceScope`) → fixed polite redirect, no AI call, no ticket.
6. Otherwise → **AI**: `ai_pending_since` is set and `AiResponder::run()` runs after the HTTP response (`dispatch()->afterResponse()`); the cron command `chatbox:process-ai` picks up anything still pending after 90 s.

`AiResponder`: searches approved knowledge (`KnowledgeSearch::search`, scoped to tenant + website), checks budget/price/provider approval (`AiBudgetService`), calls the provider, validates the JSON answer, blocks answers that contain e-mails/phones/card-like numbers not already in the chat or sources, and either replies, says `out_of_scope` with fixed text, or escalates.

### 6.3 Escalation and the 60-second window

`EscalationService` + `ConversationStateMachine`:

```
AUTO_HANDLING → ESCALATION_REQUESTED → QUEUED_FOR_TEAM → AGENT_ASSIGNED → AGENT_ACTIVE ⇄ WAITING_FOR_CUSTOMER → RESOLVED → CLOSED
```
- Escalation row with `deadline_at = now + 60 s` (unique open escalation per chat; locks: conversation row → escalation row).
- Agent takes over in time → joined, no ticket. Nobody → exactly one ticket (`idempotency_key`).
- Finalised by the widget's `/sync` poll or the cron `chatbox:escalations` (every minute).
- Outside working hours (`BusinessHours`): no countdown, ticket at once.

### 6.4 Tickets, reminders, Telegram

- `TicketService` creates/assigns/changes status and moves the conversation state.
- `Notifier::telegram()` writes a `notification_deliveries` row **before** sending, with a unique `dedupe_key`; failures are retried with back-off (max 5) by `chatbox:reminders`.
- 30-minute reminders per pending period (`tickets.team_pending_since`, `reminder_cycle`, `ticket_reminders`).

### 6.5 Staff dashboard

- Served at `/app/...`, `/{website}/admin/...` (website-scoped sign-in) and `/{company}/...` (company address, `tenants.slug`). All three are the same static app; routing is client-side by path (`frontend/views/App.js`: `pathContext`, `parseRoute`, `navigate`).
- After sign-in the browser is moved to the person's own address (`moveToOwnAddress`): company staff → `/{their company}`, platform admin → `/app`. This is cosmetic; access is enforced by the API.
- Sign-in at `/{company}` sends `tenant_slug`; the server refuses other companies' staff with the generic credentials error.

## 7. Security model

| Layer | Implementation |
|---|---|
| Staff auth | Sanctum token, 7-day expiry, login throttling + account lockout, password reset via single-use e-mailed token |
| Roles | `role:` middleware (`EnsureRole`): `admin`, `agent`, `super_admin`; website-restricted agents via `property_user` (`User::canAccessProperty`) |
| Tenant scope | Controllers query through the user's tenant; `Lead::visibleTo()`, conversation/ticket lookups are tenant-scoped (cross-company id → 404) |
| Platform admin isolation | `company.data` middleware → 403 on chats, tickets, leads, customers, knowledge, AI, Telegram, notifications, analytics |
| Widget | Origin check, rate limits, visitor token format, `AntiBotShield` |
| Output | Plain-text storage (`SafeText`), escaping in widget/dashboard, CSP (`SecurityHeaders`, hashes from `public/app/csp-hashes.json`) |
| Files | Content-sniffed allow-list, private storage (`storage/app/private`), signed expiring download URLs |
| Outbound HTTP | `OutboundUrlGuard`: https/public IPs only, IP pinned, no redirects (webhooks, custom AI providers) |
| Secrets | Encrypted casts, `$hidden`, never in logs/audit; custom AI providers need platform approval |
| Audit | `Audit::record()` → `audit_logs` (logins, staff changes, exports, settings, data clears) |
| Spreadsheets | Formula injection neutralised (`Support/Spreadsheet`) |

When you add an endpoint: put it in the right middleware group in `routes/api.php`, add `company.data` if it reads company data, scope every query by tenant, validate input, and write a test that **another company and the super admin get 403/404**.

## 8. Background work (cron)

One cPanel cron line: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`

| Command | Schedule | Does |
|---|---|---|
| `chatbox:escalations` | every minute (first) | Finalise expired 60-second windows (tickets) |
| `chatbox:reminders` | every minute | Ticket 30-min reminders, task/sales reminders, Telegram retries |
| `chatbox:process-ai` | every minute | AI replies still pending after 90 s |
| `queue:work --stop-when-empty` | every minute | Knowledge document processing etc. |
| `chatbox:maintenance` | hourly | Orphan attachments, stuck knowledge versions |
| `chatbox:ai-regression` | daily 03:30 IST | Approved corrections still retrievable (no AI call) |
| `chatbox:daily-digest` | daily 10:00 IST | Daily summary |
| `sanctum:prune-expired`, `queue:prune-failed` | daily | Cleanup |

Manual commands: `chatbox:env-check` (health), `chatbox:clear-chats {company}` (delete one company's chats/tickets/leads/customers, keeps company/staff/settings), `chatbox:seed-platform`, `chatbox:clean-accounts`.

## 9. Configuration

All in `.env` (see `.env.example`) and `config/chatbox.php`. Important keys:

| Key | Purpose |
|---|---|
| `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` | Laravel basics (APP_KEY also decrypts stored API keys; never rotate without re-entering secrets) |
| `DB_*` | MariaDB connection |
| `BROADCAST_CONNECTION=null` | cPanel: polling only |
| `SANCTUM_EXPIRATION` | staff token lifetime (minutes) |
| `CHATBOX_PUBLIC_REGISTRATION` | self sign-up for new companies (default off) |
| `CHATBOX_DASHBOARD_ORIGINS` | CORS for staff API (dev server only) |
| `CHATBOX_ATTACHMENT_MAX_KB`, `CHATBOX_ATTACHMENT_LINK_TTL` | uploads |
| `CHATBOX_AI_CUSTOM_HOSTS` | custom AI hosts approved automatically (optional) |
| `CHATBOX_REMIND_WAITING_CUSTOMER` | also remind on tickets waiting for the customer |

## 10. Local development

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan serve                       # API + built dashboard at http://127.0.0.1:8000/app

cd frontend && npm ci
npm run dev                             # dashboard dev server (set CHATBOX_DASHBOARD_ORIGINS=http://localhost:3000)
```

Build the dashboard for production (required after any change in `frontend/`):

```bash
cd frontend
npx eslint views
npm run build && npm run deploy:local   # writes public/app and public/app/csp-hashes.json
```
Commit `frontend/` **and** `public/app/` together.

## 11. Testing

```bash
php artisan test                        # whole suite (SQLite in memory), ~256 tests
php artisan test --filter=TenantRoutesTest
```
- `tests/TestCase.php` freezes time outside working hours; tests that need in-hours behaviour freeze their own time.
- `ChatboxFixtures` gives two companies (A, B), websites, staff and `startChat()`, `runOutEscalationWindow()`.
- **Before merging schema changes**, also run the suite against a disposable MariaDB 10.4 database (never the dev or production database).
- Fake AI clients are injected through `LlmClient`; no test calls a real provider.

What a feature PR must include: tests for the happy path, for another company (isolation), for the super admin (403 on company data), and for repeated/duplicate requests where state changes.

## 12. Deployment (cPanel)

On the server, in the app directory:

```bash
# 1. backup the database first (see CPANEL_DEPLOYMENT.md)
php artisan down --retry=60
git pull origin main
php artisan migrate --force             # only when there are new migrations
php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```
Then hard-refresh the browser (new dashboard build). Rollback: `git reset --hard <previous commit>` and `php artisan migrate:rollback --step=<n> --force` if migrations ran, or restore the backup.

## 13. How to add things (recipes)

**A new dashboard page:** create `frontend/views/MyPage.js`, add it to `NAV_SECTIONS` and the view map in `Shell` (`App.js`, `overview: Overview, inbox: Inbox, …`); if it should open at a company address, add the view name to `Tenant::DASHBOARD_VIEWS` (server route) and `COMPANY_VIEWS` (App.js). Rebuild and commit `public/app`.

**A new API endpoint:** controller method in `app/Http/Controllers/Api`, logic in a service, route in `routes/api.php` inside the `auth:sanctum` group with `company.data` (company data) or `role:super_admin` (platform). Scope by tenant. Add a feature test.

**A new column/table:** new migration with `up()` + `down()`, nullable or defaulted columns, backfill by copying. Run on SQLite and MariaDB.

**A new Telegram event:** add the event key to `NotificationChannel::EVENTS` (and the checkbox in `frontend/views/Notifications.js`), call `Notifier::telegram($tenant, $event, $text, $dedupeKey)` with a stable dedupe key.

**A new AI provider:** add it to `AiProviderCatalog` (endpoint, discovery path, JSON-mode support); the OpenAI-compatible client handles most providers; classify its error responses in `LlmResult::kindOf()`.

**A new scheduled job:** closure or class command, schedule it in `routes/console.php`, make it idempotent and safe to run every minute with `withoutOverlapping()`.

## 14. Known limitations / open items

- Real-time is polling; cron granularity is one minute (tickets for visitors who left appear up to ~60 s after the deadline).
- Staff tokens are stored in `localStorage` (mitigated by CSP and output escaping; HttpOnly cookies would be stronger).
- Every opened chat sends a Telegram alert; noisy under bot traffic.
- Not yet verified end-to-end with a real AI provider key or a real Telegram bot in production; no independent penetration test yet.
- Lead detection (service, website) is keyword based.
- A legacy Blade dashboard (`resources/views/dashboard.blade.php`) remains as a fallback only (`CHATBOX_LEGACY_DASHBOARD`); new work goes into `frontend/`.
