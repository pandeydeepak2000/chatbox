# ChatBox — Phase 1 Audit Report

**Date:** 2026-11-04 (IST) **Scope:** Laravel API, models, migrations, widget.js, realtime events/channels, Blade dashboard, Next.js dashboard, CRM, config, tests, deployment docs, scratch scripts.
**Status:** Audit only. No application code was changed. One new test file was added: `tests/Feature/Security/Phase1AuditTest.php`.

---

## सारांश (Hindi summary)

- Project ka base theek hai: multi-tenant models, Sanctum login, widget, live chat, CRM leads, FAQ bot sab bana hua hai. Purane 17 tests mein se **16 pass** hote hain.
- **Abhi ise live/public use karna safe nahi hai.** 6 critical problems mili hain. Sabse badi: koi bhi sirf email daalkar kisi ka bhi password badal sakta hai, aur visitor apne naam mein code daalkar agent/admin ka login token chura sakta hai.
- Har security problem ke liye ek automated test likha gaya hai (**16 tests, sab abhi fail ho rahe hain**, yaani problem confirm hai). Phase 3 ke fixes ke baad yeh pass hone chahiye.
- Do dashboard bane hue hain (Blade aur Next.js). Sirf ek rakhna chahiye; recommendation section 5 mein hai.
- Code fix se pehle aapko khud kuch kaam karne hain (section 8): passwords/keys badalna, server se SQL dump aur `scratch/` hatana.

---

## 1. Method

| Step | Result |
|---|---|
| Read all backend source (controllers, models, middleware, events, services, channels, routes, migrations, config, commands) | Done |
| Read widget.js, Blade dashboard, Next.js `page.js` (targeted review, not line-by-line) | Done (partial) |
| `composer install` + run existing suite (SQLite in-memory) | 16 pass / 1 fail |
| Wrote 16 regression tests reproducing each defect | 16/16 fail as expected (defects confirmed) |
| Apache `.htaccess` behaviour, live server, Next.js build, Reverb server | **Not executed** — findings marked *code review* |

Run the audit tests: `php artisan test --group=security-audit`
Note: until Phase 3, the full suite (`php artisan test`) will show these 16 as failing — that is intentional.

---

## 2. Confirmed working (by existing passing tests)

| Feature | Evidence |
|---|---|
| Tenant registration creates tenant, owner, property, token | `AuthAndTenantTest` |
| Login + profile, admin invites agents, property create + key regeneration | `AuthAndTenantTest` |
| Dashboard page loads, stats API, departments + canned responses CRUD | `DashboardTest` |
| Widget init returns config + visitor token; rejects un-whitelisted **header** origin | `RealTimeChatTest` |
| Visitor starts conversation, events broadcast, message XSS-stripped on store | `RealTimeChatTest` |
| Agent views conversations, replies, closes | `RealTimeChatTest` |
| widget.js uses Shadow DOM; `/demo` redirect | `WidgetEmbedTest` |

Code-review only (no test): polling fallback (3 s), heartbeat/leave presence, FAQ keyword matcher with Hinglish stop-words, CSAT rating, transcript e-mail, webhooks (HMAC signed), visitor block, CSV export, super-admin tenant/user management, login brute-force lockout (5 tries / 15 min per email+IP).

**Existing failing test:** `Tests\Feature\ExampleTest` — `/` route queries `properties` but the test has no `RefreshDatabase`. Low impact; the home route will also 500 on a fresh install before migration.

---

## 3. Security findings

Severity: **Critical** = account/platform takeover or data leak by an unauthenticated person. **High** = cross-tenant access, bypass of a stated control, or server-side abuse. **Medium** = abuse/hardening.

### Critical

| ID | Finding | Location | Evidence |
|---|---|---|---|
| C1 | **Password reset with no token.** `forgot-password` takes `email` + `new_password` and sets it. Anyone can take over any account, including super admin (email is published in README/EXPLAINED). | `AuthController::forgotPassword` | Test `forgot_password_requires_a_verified_reset_token` |
| C2 | **Stored XSS in staff dashboard → token theft.** Visitor `name`, `phone`, `browser`, `os` from `widget/init` are stored raw (only messages are sanitised). Blade dashboard renders them with `innerHTML` (inbox card, CRM table). Staff token is in `localStorage('jix_token')`, so a visitor can steal an agent/admin/super-admin session. | `WidgetChatController::init`; `dashboard.blade.php` ~L2324, ~L3333 | Test `visitor_profile_fields_are_not_returned_as_raw_html_to_staff` |
| C3 | **Webhook secret leaked publicly.** `widget/init` returns the whole `widget_config`, which contains `webhook_url` and `webhook_secret`. Anyone with the public key (every website visitor) can forge signed webhooks. | `WidgetChatController::init` | Test `widget_init_does_not_expose_webhook_secret_or_url` |
| C4 | **Attachment upload → same-origin script.** Extension is taken from the client filename; a real PNG named `evil.html` is saved as `.html` in `public/uploads/attachments`. SVG is allowed. Files are public, served from the dashboard origin. Same code in agent upload. | `WidgetChatController::uploadAttachment`, `AgentChatController::uploadAttachment` | Tests `widget_upload_does_not_trust_client_extension`, `widget_upload_rejects_svg` |
| C5 | **Sensitive files web-reachable in "Option B" (public_html) deploy.** Root `.htaccess` serves any existing file not in its block-list. Not blocked: `salutehost_chatbox.sql` (password hashes, `secret_key`s, visitor PII), `scratch/*.php` (would **execute**, some contain live credentials and call live URLs), `vendor/`, `README.md`/`EXPLAINED.md`, `.phpunit.result.cache`. `*.sql` is also not in `.gitignore`. | `/.htaccess`, `/.gitignore` | Code review (Apache not run) |
| C6 | **Secrets and default credentials in the repository.** Same hard-coded `APP_KEY` in `.env.example`, `.env.production` and `CPANEL_DEPLOYMENT_GUIDE.md`. `.env.example` (tracked) holds non-placeholder DB, mail and Reverb values. Plain-text passwords in `CleanDemoAccountsCommand.php` and `scratch/`. README lists `password123` logins with 1-click login buttons. | listed files | Code review (values not reproduced here) |

### High

| ID | Finding | Location | Evidence |
|---|---|---|---|
| H1 | **Domain whitelist bypass.** Server prefers the body field `origin` over the real `Origin` header; only `init` checks it (start/message/upload don't). Super-admin-created tenants default to `allowed_domains: ['*']`. | `WidgetChatController::init`, `AuthController::platformCreateTenant` | Test `domain_whitelist_cannot_be_bypassed_with_body_origin` |
| H2 | **Cross-tenant read of canned responses** when `property_id` is passed. | `AgentChatController::cannedResponses` | Test `canned_responses_are_tenant_isolated` |
| H3 | **Cross-tenant references.** `assign`/`transfer` accept any `users.id` / `departments.id`; `createAgent`/`updateAgent` accept any department ids; `widget/start` accepts any department id. | `AgentChatController`, `AuthController`, `WidgetChatController::startConversation` | Test `conversation_cannot_be_assigned_to_other_tenant_agent` (+ code review for the rest) |
| H4 | **SSRF.** Webhook test and live dispatch POST to any URL incl. `169.254.169.254`, `127.0.0.1`, LAN. | `WebhookService` | Test `webhook_test_blocks_internal_addresses` |
| H5 | **Agents receive `secret_key`** of properties (`/properties`, `/auth/me`, login response). | `PropertyController`, `AuthController` | Test `agents_do_not_receive_property_secret_key` |
| H6 | **No rate limit on most endpoints.** The `api` limiter is defined but never attached (`api` group = SubstituteBindings + AntiBotShield only). `widget-messages` limiter is keyed by the attacker-supplied `visitor_token`, so rotating tokens bypasses it. `init` can create unlimited visitor rows. | `bootstrap/app.php`, `AppServiceProvider` | Test `public_widget_endpoints_are_rate_limited` |
| H7 | **Staff tokens never expire** (`sanctum.expiration = null`) and live in `localStorage`. Combined with C2 a stolen token is valid forever. | `config/sanctum.php`, dashboards | Code review |
| H8 | **Visitor identity = bearer token only.** Whoever has a `visitor_token` gets the visitor's name/email and open conversation. No verified ownership for returning/cross-device customers (brief requirement). | Widget API | Code review |
| H9 | **Public attachments.** All uploads are public URLs with no auth/expiry (brief requires authenticated download). | Upload methods | Code review |
| H10 | **Transcript abuse.** A visitor can e-mail a transcript to any address (spam relay from your domain). Service returns `success: true` even when sending failed. | `WidgetChatController::requestTranscript`, `TranscriptService` | Code review |

### Medium

| ID | Finding | Location | Evidence |
|---|---|---|---|
| M1 | CSV formula injection (`=HYPERLINK(...)` in visitor name). | `AgentChatController::exportLeads` | Test `csv_export_neutralises_formulas` |
| M2 | Webhooks are sent synchronously inside the visitor request (up to 4 s delay per message). | `WebhookService::dispatch` | Code review |
| M3 | No audit log for staff actions (role/password changes, deletes, key regeneration, exports). | — | Code review |
| M4 | `CORS: *` on every `/api/*` route including staff API; no CSP. Risk is limited because auth is Bearer, but should be split. | `SecurityHeaders` | Code review |
| M5 | `leads?per_page=` unbounded; status filter applied **after** pagination (wrong counts/pages). | `AgentChatController::leads` | Code review |
| M6 | Open tenant self-registration with no e-mail verification. | `AuthController::registerTenant` | Code review |
| M7 | Transcript HTML inserts `site_name` unescaped. | `TranscriptService::renderHtml` | Code review |

---

## 4. Reliability / functional defects

| ID | Defect | Evidence |
|---|---|---|
| B1 | Bot greeting hard-codes "SaluteHost Support" for every tenant; stop-words also include `salutehost`. | Test `bot_greeting_is_not_hardcoded_to_one_brand` |
| B2 | Text is HTML-encoded before storage, then escaped again in widget → users see `don&#039;t`, `&amp;`. Data in DB is not plain text (bad for AI/search/export). | Test `message_text_is_stored_as_plain_text` |
| B3 | Agent typing endpoint has no try/catch → HTTP 500 when Reverb is down (always the case on shared cPanel). | Test `agent_typing_survives_realtime_server_outage` |
| B4 | Polling sync uses `created_at > last.created_at` (1-second precision) → messages sent in the same second are lost permanently. | Test `polling_sync_does_not_skip_messages_sent_in_the_same_second` |
| B5 | README/EXPLAINED embed snippet (`data-property-key`) does not work; widget.js only reads `window.ChatBoxConfig` / `window.JixChatConfig`. Branding is mixed (ChatBox / JixChat). | Code review |
| B6 | Operating hours fail for overnight ranges (e.g. 22:00–06:00). | Code review |
| B7 | `ExampleTest` fails; home route depends on a hard-coded public key. | Existing suite |
| B8 | Deployment guide tells users to run `db:seed --force`, which seeds demo accounts; super-admin password differs between README (`password123`) and guide (`password`). | Code review |

---

## 5. Duplicate dashboards — recommendation

| | Blade `resources/views/dashboard.blade.php` | Next.js `frontend/app/page.js` |
|---|---|---|
| Size | 4,232 lines, one file, vanilla JS + `innerHTML` (49 uses) | 1,986 lines, one React component |
| Features | More complete: departments, uploads, webhook test, transcripts, canned CRUD | Missing departments, uploads, webhook test, transcripts |
| XSS safety | Unsafe by default (C2) | React escapes by default |
| Deploy on cPanel | Works as-is | Needs `output: 'export'` static build (no Node server) |
| API URL | Same origin | Hard-coded `127.0.0.1:8000` fallback |

**Recommendation:** keep **one** production UI on the **Next.js** app, built as a static export and served by Laravel (works on cPanel without Node). Port the missing features from Blade, split `page.js` into components/routes, then delete the Blade dashboard. Until parity, patch C2 in Blade (escape all output) because it is the one currently in use.

---

## 6. Gaps between current schema and the brief (input for Phase 2)

Missing entirely: tickets (+ history, notes, idempotency key), audit logs, knowledge documents (upload → extract → approve → index → version), knowledge scope (company vs website), AI usage/cost ledger + budgets, AI takeover state on conversation, Telegram channel settings + notification log, customer identities with verified ownership, private attachment storage, staff permissions beyond 3 fixed roles, subscription/plan limits.

Structural notes: user e-mail is globally unique (one person cannot belong to two companies); `message_type`/`status` are DB enums (painful to extend on MySQL); no `tenant_id` on child tables, so every isolation check depends on a `whereHas` join — Phase 3 should add a central tenant scope/policy layer instead of per-method checks.

---

## 7. Gaps in the brief itself (to decide before Phase 2)

1. **Hosting target.** cPanel shared hosting has no long-running workers: Reverb, queue workers (AI calls, document extraction, Telegram, webhooks) need a VPS or a cron-driven queue. Decide VPS vs cPanel for internal deployment.
2. **LLM provider and budget numbers** — which provider(s), default monthly cap per company, what happens at cap (FAQ-only mode vs human-only).
3. **Vector search** — MySQL has no native vectors; choose: MySQL full-text/BM25 only (cheapest), or an external/embedded vector store.
4. **Customer verification method** for "verified ownership" (e-mail OTP is simplest; integration with your WHMCS/billing login is better for hosting customers).
5. **Data retention & privacy** (chat/PII retention period, deletion requests, India DPDP Act) — not mentioned.
6. **Backups and monitoring** — not mentioned.
7. **Existing live data** — is `chat.salutehost.com` already live with real customers? This changes whether Phase 3 needs a data migration and immediate hot-fixes.

---

## 8. Do now (operational, no code needed)

1. If any copy is deployed: delete `salutehost_chatbox.sql`, `scratch/`, `README.md`, `EXPLAINED.md` from the server; prefer the subdomain setup (document root = `public/`).
2. Rotate: `APP_KEY` (`php artisan key:generate` per environment), DB password, mail password, Reverb key/secret, every property `secret_key`, and every staff password — especially super admin.
3. Check git history for `.env*`, `*.sql`, `scratch/`; if pushed to any remote, treat those secrets as public.
4. Do not enable `forgot-password` publicly until C1 is fixed (block the route at the web server if live).

---

## 9. Known limitations of this audit

- Apache rewrite behaviour (C5) and live server state were not tested.
- widget.js, Blade and Next.js were reviewed by targeted search, not line-by-line; more UI-level issues may exist.
- No load/performance testing; MySQL-specific behaviour was not tested (suite runs on SQLite).
- Secret values were deliberately not printed or copied into this report.

## 10. Next step

On approval: **Phase 2 — updated architecture + database migration plan** (tenant scoping layer, tickets, knowledge pipeline, AI usage ledger, audit log, notifications), then Phase 3 fixes C1–C6, H1–H10 with these tests turning green.
