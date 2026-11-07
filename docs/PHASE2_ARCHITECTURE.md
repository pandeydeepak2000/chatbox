# ChatBox — Phase 2: Architecture & Database Migration Plan

**Date:** 2026-11-05 (IST) **Status:** Plan only. No application code changed in this phase.
**Inputs:** `docs/PHASE1_AUDIT.md`, the SaaS developer brief, the "Automatic Ticket Generation" brief, the "Website Admin Login / Support PIN" brief, and the AmbitionHost chatbot instructions (Aarav).
**Baseline re-checked today:** `php artisan test` → 16 pass, 17 fail (the 16 audit tests + `ExampleTest`). The audit findings are still valid on `4523d3c`.

---

## सारांश (Hindi summary)

- Project ko dobara nahi banayenge. Purane tables (tenants, properties, conversations, messages) rahenge; unme naye columns aur naye tables judenge.
- **Company = `tenant`, Website = `property`** — UI mein "Company/Website" likhenge, database ke naam same rahenge taaki purana code na tute.
- Customer login: email + 6-digit PIN (signup par OTP nahi). PIN bhoolne par email OTP (10 minute, ek baar). PIN reset hote hi purane sab login band.
- Sirf PIN banane se email "verified" nahi maana jayega. Billing/WHMCS jaisi jaankari ke liye alag se email OTP verification zaroori hoga.
- Ticket system naya banega: ek conversation par ek hi khula ticket (database level par guarantee), private notes, history, reminders.
- AI pehle FAQ se jawab dega (free), phir approved knowledge se LLM, aur jawab na mile to ticket. Staff ke takeover ke baad AI band.
- Hosting sirf cPanel shared hosting par hogi (VPS nahi). Live chat AJAX polling se, background kaam cron + database queue se (section 15).
- Leads ke liye alag table aur dashboard banega, Excel/CSV export ke saath (section 16).
- Kuch faisle abhi baaki hain (section 12) — tab tak yeh plan safe defaults maan kar chalega.

---

## 1. Guiding decisions

| # | Decision | Reason |
|---|---|---|
| D1 | Keep `tenants` / `properties` table names; call them **Company** / **Website** in UI and new code docs. | Renaming touches every controller, test and the widget for no functional gain. |
| D2 | Add `tenant_id` to every tenant-owned table and enforce isolation centrally (global scope + policies), not per-method `whereHas`. | Phase 1 H2/H3 came from per-method checks being forgotten. |
| D3 | One production dashboard: **Next.js static export served by Laravel**. Blade dashboard gets the C2 escaping hot-fix only, then is retired after parity. | Phase 1 §5. |
| D4 | Staff auth moves from `localStorage` bearer tokens to **Sanctum SPA cookie auth** (HttpOnly, SameSite=Lax, CSRF) because the dashboard is same-origin. Tokens remain only for API integrations, with expiry. | Removes the C2→token-theft chain and H7. |
| D5 | Customer (widget) identity is a **separate guard and separate tables** from staff. No customer can ever hold a staff token. | Brief: "Keep customer login separate from staff login." |
| D6 | All slow or external work (LLM, extraction, Telegram, webhooks, e-mail) runs in **queued jobs**. Runs on cPanel via a cron-driven database queue (see §15). | Phase 1 M2; cPanel has no long-running workers. |
| D7 | Message ordering uses a **per-conversation sequence number**, not timestamps. | Fixes B4 (lost same-second messages). |
| D8 | Messages are stored as **plain text**; escaping happens only at render time (`textContent` in widget, React in dashboard). | Fixes B2; needed for AI/search/export. |
| D9 | AI may only *read and answer*. A fixed allow-list of AI "actions" exists, and none of them changes money, domains, DNS, services or credentials. | Brief §G / Security. |
| D10 | Nothing is reported to a customer or staff member as done (ticket created, notification sent, e-mail sent) until the database write / API call returned success. | Explicit in all briefs. |

---

## 2. Tenancy & permission model

### 2.1 Actors

| Actor | Scope | Auth |
|---|---|---|
| Platform super admin | All companies | Staff guard + `users.is_platform_admin` flag (no `tenant_id`) |
| Company owner / admin | All websites of their company | Staff guard |
| Website admin | Assigned websites only | Staff guard + `property_user.role = admin` |
| Agent | Assigned websites; within them, assigned departments | Staff guard + `property_user.role = agent` |
| Customer | Their own account on one website | Customer guard (opaque session token) |
| Anonymous visitor | Only the guest conversation bound to their device token | Visitor token (as today) |

`users.role` stays (`super_admin|admin|agent`) for backward compatibility and becomes the *company-level* role. Website-level access comes from the new `property_user` pivot. The company owner is `tenants.owner_user_id`.

### 2.2 Enforcement layers (all server-side)

1. **`ResolveTenantContext` middleware.** For staff, sets the tenant from the authenticated user. For widget calls, sets it from the website `public_key` *and* checks the real `Origin` header against `allowed_domains`. The body `origin` field is ignored (fixes H1). Stored in a request-scoped `TenantContext` singleton.
2. **`BelongsToTenant` trait + global scope** on every tenant-owned model. It filters every query by `tenant_id` and stamps `tenant_id` on create. Super admin bypasses only through an explicit `withoutTenantScope()` used in platform controllers.
3. **Policies** (`ConversationPolicy`, `TicketPolicy`, `AttachmentPolicy`, `KnowledgeDocumentPolicy`, `PropertyPolicy`, `UserPolicy`) check website membership and department rules. Every controller action calls `$this->authorize()`. Cross-tenant references in requests (`department_id`, `assignee_id`) are validated with `Rule::exists(...)->where('tenant_id', ...)` (fixes H3).
4. **Response DTOs / API Resources.** Secrets (`secret_key`, webhook secret, API keys) live only in resources served to company admins (fixes C3, H5).
5. **Shared task board** returns a reduced `TaskSummaryResource` (reference, status, owner, due time, department), with no messages, customer e-mail or account data, to staff who lack access to the underlying conversation.

### 2.3 Website-specific admin URLs

- New `properties.slug` (unique, lowercase, `[a-z0-9-]{3,40}`, reserved words blocked: `admin`, `api`, `app`, `widget`, `login`, `platform`, …).
- `GET /{slug}/admin` serves the same dashboard SPA, pre-scoped to that website.
- `POST /api/v1/auth/login` accepts an optional `website_slug`. If it is given, login **fails with the same generic error** unless the user has access to that website. A wrong slug and a wrong password look identical.
- After login, the active website is only a UI default. Every API call is still authorised by membership, so editing the URL or a website ID never grants access.
- Custom admin domains: `property_domains` table reserved for later; not built in Phase 3.

---

## 3. Customer identity (Support PIN)

### 3.1 Scope decision

Customer accounts are **per website**: `unique(property_id, email_normalized)`. This follows the "Website Admin Login" brief ("each website must have its own … customers"). If AmbitionHost wants one login across its 3–4 sites, a company-level option can be added later (see §12, Q3).

### 3.2 Widget identity modes (per-website setting `identity_mode`)

| Mode | Behaviour | Default for |
|---|---|---|
| `pin_required` | Email + PIN before chatting | AmbitionHost websites (Aarav's instructions) |
| `pin_optional` | Guest chat allowed; "Save my chats" / "Continue previous support" offered | New SaaS companies |
| `guest_only` | Today's behaviour | — |

This resolves a conflict between the briefs: the SaaS brief allows guest chat without signup, while the other two briefs ask for email + PIN on first visit.

### 3.3 Flows

| Flow | Server behaviour |
|---|---|
| **Sign up** (email + new PIN, no OTP) | Validates the PIN as `^\d{6}$` and rejects trivial PINs (`000000`, `123456`, `111111` …). Stores `Hash::make(pin)` (bcrypt/argon per `config/hashing.php`). If the email already exists on this website, **nothing is overwritten** and no session is issued (see 3.5). The new account starts with `email_verified_at = null`. If the request comes from a device that already has a guest conversation, that conversation (and only that one) is attached. |
| **Log in** (email + PIN) | A constant-time path for unknown e-mails (a dummy `Hash::check`). The single generic error is "Email or PIN is incorrect." On success it issues a customer session. |
| **Forgot PIN** | Always responds "If an account exists, we've sent a code." It creates a 6-digit OTP (hashed, 10-minute expiry, single use, max 5 verification attempts) and e-mails code + instructions, **no links**. |
| **Verify OTP → new PIN** | Consumes the OTP. Sets the new PIN hash and sets `email_verified_at` (the OTP proves ownership). Increments `session_version` and revokes all `customer_sessions`, then issues a fresh session. |
| **Verify email** (optional, later step) | The same OTP mechanism with purpose `email_verify`. It is required before any account-specific data (WHMCS services, invoices) is shown or used by the AI. |
| **Log out** | Revokes the current session. |

### 3.4 Brute-force protection

- **Per account:** after 5 consecutive failures the account locks for 15 minutes. Repeat lockouts escalate to 1 h, then 24 h (`lockout_level`). A correct PIN resets the counter. Lock state is **not** revealed in the response, which keeps the generic error. The owner gets an e-mail: "several failed attempts, use Forgot PIN if this wasn't you."
- **Per IP / per website:** named rate limiters `customer-signup` (5/h/IP), `customer-login` (10/min/IP), `otp-request` (3/15 min/email + 10/h/IP), `otp-verify` (10/15 min/IP).
- PINs, OTPs and session tokens are never logged. A `$hidden` + log-redaction processor covers them.

### 3.5 E-mail enumeration — honest limitation

Signup without OTP means the signup endpoint *cannot* hide whether an e-mail is taken: a new user gets a session, an existing one does not. Mitigations:

1. Login and forgot-PIN responses are uniform (above).
2. The signup "already registered" reply is generic: "Use Continue Previous Support or Forgot PIN." It is rate limited per IP.
3. The real owner is e-mailed when someone tries to register their address.

Fully preventing enumeration would require signup OTP, which the brief rules out. **This trade-off needs sign-off (§12, Q5).**

### 3.6 Ownership disputes

Someone may create an account with another person's e-mail. They gain nothing beyond their own chats, because account data needs `email_verified_at`. The real owner runs Forgot PIN. The OTP proves ownership, which revokes the squatter's sessions and marks the account verified. Conversations created before the first verification are flagged `pre_verification = true`, and staff see a "possible ownership dispute" badge. Records are never merged automatically. Staff can move a conversation to "hidden from customer" through an audited action.

### 3.7 Session token

The widget runs inside third-party websites, so third-party cookies are unreliable. The customer session is therefore an opaque random 40-byte token. It is stored **hashed** in `customer_sessions`, kept in the widget's `localStorage` on the host site, sent as an `Authorization` header, and expires after 30 days with sliding renewal. The token is revocable server-side and bound to `property_id`. Residual risk: an XSS hole on the *host* website could read it (documented limitation).

---

## 4. Conversations, AI/human handling and message sync

New columns on `conversations`:

| Column | Purpose |
|---|---|
| `tenant_id` | Isolation |
| `customer_account_id` (nullable) | Owner when logged in |
| `handler` (`ai` \| `human`) | Who replies |
| `handover_at`, `handover_by`, `ai_resumed_at`, `ai_resumed_by` | Takeover audit |
| `last_seq` (int) | Per-conversation message counter |
| `language` (`en`, `hi`, `hi-Latn`, …) | Last detected / explicitly chosen reply language |
| `clarifications_asked` (tinyint) | Caps clarification questions before escalation |
| `first_human_response_at` | Sales SLA (greetings and bot messages excluded) |
| `pre_verification` (bool) | See §3.6 |

New columns on `messages`: `tenant_id`, `seq` (unique with `conversation_id`), `visibility` (`public` \| `internal`), `ticket_id` (nullable), `is_automated` (greetings, system notices), `client_message_id` (unique with `conversation_id`; idempotent sends on retry).

**Rules**

- **Sync:** `GET …/sync?after_seq=N` returns `seq > N`. The `seq` value is assigned inside a transaction that locks the conversation row (`lockForUpdate`), so it is gap-free and strictly ordered (fixes B4).
- **Internal notes:** widget queries hard-filter `visibility = public` in one model scope (`Message::visibleToCustomer()`), and a test asserts that notes never leak.
- **Takeover:** when an agent sends a reply, uses "Take over", or a ticket is assigned, `handler` becomes `human`. The AI job re-reads `handler` **under lock just before saving its reply** and discards the reply if a human has taken over. This prevents simultaneous bot and agent replies even when an LLM call is in flight. AI resumes only through `POST …/resume-ai`, which is policy-checked and audit-logged.

---

## 5. Tickets

| Table | Key columns |
|---|---|
| `tickets` | `id`, `tenant_id`, `property_id`, `reference` (e.g. `AMB-000123`, unique per tenant), `conversation_id`, `customer_account_id`, `visitor_id`, `department_id`, `assigned_to`, `status` (`open`, `pending_team`, `pending_customer`, `resolved`, `closed`), `priority`, `subject`, `summary` (JSON: issue, affected_service, steps_tried, results, unresolved), `source` (`ai_escalation`, `customer_request`, `staff`), `idempotency_key`, `open_conversation_id` (nullable, **unique**), `resolved_at`, `resolved_by`, timestamps |
| `ticket_issues` | `ticket_id`, `description`, `status` (`open`/`resolved`), `resolved_by`, `resolved_at`: so one part of a multi-issue request is not silently closed. A ticket can only be resolved when every issue is resolved, or by an explicit "close remaining" with a reason. |
| `ticket_assignments` | `ticket_id`, `user_id`, `assigned_by`, `assigned_at`, `unassigned_at`: previous and current owners keep visibility; the current one is the row with `unassigned_at = null`. |
| `ticket_events` | Immutable history: created, status/priority/department changes, assignment, AI paused/resumed, reopened. |
| `ticket_sequences` | `tenant_id`, `next_number`: row-locked counter for references. |

**Duplicate prevention** works on two levels:

1. `unique(property_id, idempotency_key)`. The widget sends a UUID per "create ticket" intent, so double clicks and network retries return the same ticket.
2. `open_conversation_id` equals `conversation_id` while the ticket is unresolved and becomes `null` on resolve. Because of the unique index, the **database** refuses a second open ticket for the same conversation, and the service returns the existing one.

A customer reply on a resolved ticket either reopens it (sets `open_conversation_id` again → `pending_team`), or creates a new linked ticket if more than *N* days have passed (configurable, default 7).

**Customer view:** the "Support Tickets" tab lists the customer's tickets (reference, subject, status, last update). Opening one shows the public messages of the linked conversation.

**Not supported:** creating tickets in WHMCS. Tickets are local only. A WHMCS write integration is a separate, later decision.

---

## 6. AI support engine

### 6.1 Pipeline (queued job `ProcessCustomerMessage`)

```
customer message
  │
  ├─ guards: handler == ai? website AI enabled? budget left? visitor rate ok?   ── no → (human mode: notify staff only)
  ├─ deterministic intent: "talk to human" (en/hi/Hinglish) ─────────────────────→ escalate
  ├─ deterministic restricted-action intent (refund, mark paid, DNS, renew…) ───→ fixed reply + escalate
  ├─ FAQ matcher (existing, fixed: no hard-coded brand) high confidence ────────→ answer (no LLM cost)
  ├─ retrieval: approved knowledge chunks for this website + company-shared
  │     └─ none relevant → clarify once (if ambiguous) else escalate
  └─ LLM call (structured JSON output)
        { action: answer|clarify|escalate, reply, language, confidence,
          cited_chunk_ids[], ticket_summary{issue, affected_service, steps_tried, results, unresolved} }
        │
        validators: answer without citations → escalate
                    forbidden-claim filter (payment received, refund issued, service is up, "I have fixed") → escalate
                    re-check handler under lock → save or discard
```

**Escalation** calls `TicketService::createOrReuse()`. Only after a successful commit does the bot say: "I can't reliably resolve this here. I've created support ticket **AMB-000123** for our team." Our team works **9 AM–6 PM IST every day**. Outside hours the reply says when the team will be back and does **not** promise overnight support. If creation fails, the bot shows a failure message and a **Retry** button (same idempotency key). It never shows a false reference.

### 6.2 Prompt-injection and safety

- System rules live only in the system prompt. Customer text and attachment text go into a delimited user block that is labelled untrusted.
- Only the citation-checked knowledge chunks of *this* tenant/website are included. Account data is included only when the customer's email is verified and the data comes from the read-only integration.
- The model has **no tools that change anything**. The output is data that our code validates; the model's text never triggers an action.
- Historical chats are never used automatically. An admin can "promote" a cleaned transcript into a **draft** knowledge document after automatic PII/credential redaction. It needs approval like any other document.

### 6.3 Language

The script is detected (Devanagari → `hi`, Latin with Hindi lexicon → `hi-Latn`, else `en`), and the LLM is told to answer in the customer's language and script. An explicit request ("reply in English", "Hindi mein batao") updates `conversations.language`. FAQ answers are served in the stored language; translation by LLM costs tokens and counts toward the budget.

### 6.4 Provider abstraction

`App\Services\Ai\LlmProvider` interface with two drivers:

- `OpenAiCompatibleProvider` (OpenAI, Groq, DeepSeek, OpenRouter, Gemini's OpenAI-compatible endpoint)
- `AnthropicProvider`

The platform default comes from `.env`. A company can override provider, model and API key in `ai_settings` (key stored with Laravel's `encrypted` cast, never returned to the browser; it is shown as `sk-…abcd`).

### 6.5 Usage, cost and limits

| Table | Purpose |
|---|---|
| `ai_model_prices` | provider, model, input/output price per 1M tokens (editable by platform admin) |
| `ai_usage_logs` | tenant, website, conversation, customer, provider, model, tokens in/out, cost (integer micro-USD), latency, outcome (`ok`, `error`, `blocked_budget`, `blocked_rate`). No prompt text by default; an optional redacted excerpt is kept for 30 days for debugging. |
| `ai_usage_counters` | tenant × period (`day:2026-11-05`, `month:2026-11`): spend and request count, updated atomically |
| `ai_budgets` | tenant: daily cap, monthly cap, per-visitor daily message cap, alert threshold (default 80%), `alert_sent_period` |

Before each call, the estimated cost is **reserved** against the counters under row lock, then settled with the real usage. At 80% the company admins get one alert per period (dashboard + Telegram). At 100% (hard cap) the company runs in **FAQ-only + human escalation** mode until the next period or a cap increase.

---

## 7. Knowledge base

| Table | Key columns |
|---|---|
| `knowledge_documents` | `tenant_id`, `property_id` (**null = shared by the whole company**), `title`, `source_type` (`faq`, `file`, `manual`, `transcript`), `status` (`draft`, `processing`, `pending_approval`, `approved`, `rejected`, `archived`), `current_version_id` |
| `knowledge_versions` | `document_id`, `version`, `file_path` (private disk), `mime`, `sha256`, `extracted_text`, `extraction_error`, `created_by`, `approved_by`, `approved_at` |
| `knowledge_chunks` | `tenant_id`, `property_id`, `version_id`, `ordinal`, `content`, `token_count`, **FULLTEXT(content)**, `embedding` (nullable, future) |

**Pipeline:** upload (TXT/CSV/XLSX/PDF/DOCX, ≤ 10 MB, MIME sniffed with `finfo`) → stored on the private disk → `ExtractKnowledgeVersion` job:

- PDF: `smalot/pdfparser`
- DOCX: `phpoffice/phpword`
- XLSX/CSV: `openspout/openspout`

The job enforces limits on pages, rows, characters and zip-entry ratio against zip bombs. The admin reviews the extracted text, then **approves**. Only then are chunks indexed and the version becomes current. Older versions remain for rollback. Retrieval only joins chunks whose document is `approved` *and* whose version is current.

**Retrieval v1:** MySQL FULLTEXT (natural-language mode) over approved chunks, which costs nothing extra on cPanel. Hinglish or Hindi questions against English documents have low keyword recall, so the cheap model rewrites the query into English keywords first (counted in usage). Embedding search is a later upgrade (decision §12, Q4).

**Existing FAQs** remain in `faqs` (the deterministic matcher). Each FAQ is also mirrored as an approved `knowledge_document` with `source_type = faq`, so the LLM can cite it.

---

## 8. Attachments (private)

The `attachments` table holds `tenant_id`, `property_id`, `conversation_id`, `message_id`, `uploader_type`/`uploader_id`, `disk` (`private`), `path`, `original_name`, `mime` (sniffed), `size`, `sha256`, plus timestamps.

- Allow-list: jpg, png, webp, gif, pdf, txt. The extension is **derived from the sniffed MIME type**, never from the client filename. SVG and HTML are rejected (fixes C4). Max size is 10 MB.
- Downloads go through `GET /api/v1/widget/attachments/{id}` (customer/visitor ownership check) and `GET /api/v1/chat/attachments/{id}` (policy). Responses carry `X-Content-Type-Options: nosniff` and `Content-Disposition: attachment` except for raster images (fixes H9).
- Migration: existing files in `public/uploads/attachments` move to the private disk, and `messages.attachment_url` is rewritten to the authorised route.

---

## 9. Notifications, tasks and operational rules

### 9.1 Tables

| Table | Purpose |
|---|---|
| `notifications` (Laravel database notifications) | Per-user dashboard inbox. **Reading ≠ resolving.** |
| `tasks` | `tenant_id`, `type` (`sales_first_response`, `quotation_followup`, `ticket_action`, …), `ticket_id`/`conversation_id`, `owner_id`, `status` (`pending`, `done`, `cancelled`), `due_at`, `completed_at/by`. Completing a task does **not** resolve the ticket or mark a sale won. |
| `task_reminders` | `task_id`, `remind_at`, `sent_at`, `cancelled_at`, `channel` |
| `notification_channels` | Per company: `type` = `telegram`, encrypted bot token, chat ID(s), subscribed events |
| `notification_deliveries` | Every external send: channel, event, status, attempts, last error, sent_at. A send counts as successful only on HTTP 2xx from Telegram. |
| `business_hours` | Per website: timezone (default `Asia/Kolkata`), daily windows (default 09:00–18:00 every day), holidays. Supports overnight ranges (fixes B6). |
| `company_roles` (config) | Who gets what: `sales_followup_owner` (Chandrika), `sla_alert_recipient` (Aarav's Telegram), `refund_owner` (Aarav). **No names are hard-coded.** |

### 9.2 Rules → implementation

| Rule | Implementation |
|---|---|
| Sales enquiry: no human first response for **30 working minutes** → Telegram to Aarav (once) | A scheduler checks conversations in the Sales department with `first_human_response_at = null`. `BusinessHours::workingMinutesBetween(created_at, now)` must exceed 30. Automated greetings have `is_automated = true` and are ignored. `sales_alert_sent_at` ensures the alert fires once. It is inactive until a Telegram channel is configured. |
| Quotation reminders to Chandrika at **+10 min, +30 min, +2 h from the original quotation time** | A staff "Mark as quotation" action on a message creates a task with 3 reminders based on wall-clock time from the quotation message timestamp. These are dashboard notifications only, never customer messages. |
| Customer reply cancels remaining quotation reminders | The visitor-message handler sets `cancelled_at` on unsent reminders of open quotation tasks for that conversation. |
| One daily reminder at **10 AM IST** for unresolved actionable tasks | A scheduled command at `10:00 Asia/Kolkata` sends one digest notification per owner listing their pending tasks. |
| Resolved items stop reminders and stay in history below pending work | Resolve cancels reminders. The task board lists pending tasks first, then a resolved section. |
| Responsibility change keeps previous + current visibility | `ticket_assignments` history. The policy allows any user who was ever assigned, and marks the current owner. |
| Wider team sees minimal read-only summaries | `TaskSummaryResource` (§2.2). |

### 9.3 Scheduler & queue on cPanel

See §15 for the full cPanel design. One cPanel cron line (`* * * * * php artisan schedule:run`) drives every background task, including a short-lived queue worker.

---

## 10. Audit log

`audit_logs` holds `tenant_id`, `actor_type` (`user`/`customer`/`system`), `actor_id`, `action`, `subject_type`, `subject_id`, `changes` (JSON, secrets redacted), `ip`, `user_agent`, `created_at`. It is append-only (no update/delete route) and written by an `Audit::record()` helper.

It covers: login and failed login, role/permission changes, password/PIN resets, key regeneration, AI pause/resume, assignments, ticket status changes, internal-note creation, knowledge approval, exports, settings changes and deletes.

---

## 11. Migration plan (ordered)

All migrations are additive. Backfills run inside the migration, and a `down()` exists for each. The order matters because later steps rely on `tenant_id`.

| # | Migration | Notes / backfill |
|---|---|---|
| 1 | `add_tenant_id_to_tenant_owned_tables` | `visitors`, `conversations`, `messages`, `departments`, `faqs`, `canned_responses`: add nullable → backfill from `properties.tenant_id` (via conversation for messages) → make NOT NULL → index. |
| 2 | `add_slug_identity_mode_to_properties` | Backfill `slug` from `site_name` (deduplicated); `identity_mode = guest_only` for existing sites (no behaviour change until enabled). Move `webhook_url`/`webhook_secret` out of `widget_config` into dedicated columns (secret `encrypted`). |
| 3 | `create_property_user_table` | `property_id`, `user_id`, `role`. Backfill: every existing agent gets access to all websites of their tenant (today's effective behaviour). |
| 4 | `add_owner_to_tenants`, `add_is_platform_admin_to_users` | Owner = oldest admin of each tenant. |
| 5 | `create_password_reset_tokens` usage + `add_password_changed_at_to_users` | Fixes C1 (Laravel broker; revoke tokens on reset). |
| 6 | `create_customer_accounts`, `customer_sessions`, `customer_otps` | `customer_accounts`: tenant_id, property_id, email, email_normalized, pin_hash, email_verified_at, failed_attempts, locked_until, lockout_level, session_version, last_login_at; unique(property_id, email_normalized). Add `visitors.customer_account_id`. |
| 7 | `add_sequence_and_visibility_to_messages` | Add `seq`, `visibility` (default `public`), `is_automated`, `client_message_id`, `ticket_id`; backfill `seq` per conversation ordered by `created_at, id`; set `conversations.last_seq`; then unique(conversation_id, seq). Convert `message_type`/`sender_type` enums to `string(20)` + app-level validation. |
| 8 | `add_handler_language_fields_to_conversations` | Columns from §4; existing rows `handler = human` if an agent ever replied, else `ai`. |
| 9 | `create_attachments_table` + data move | Moves public files to private disk (artisan command run once, idempotent). |
| 10 | `create_audit_logs_table` | — |
| 11 | `create_tickets` + `ticket_issues`, `ticket_assignments`, `ticket_events`, `ticket_sequences` | — |
| 12 | `create_notifications`, `tasks`, `task_reminders`, `business_hours` | Seed default 09:00–18:00 Asia/Kolkata for every website. |
| 13 | `create_knowledge_documents`, `knowledge_versions`, `knowledge_chunks` | FULLTEXT index only on MySQL (guarded for SQLite tests). Mirror existing FAQs. |
| 14 | `create_ai_settings`, `ai_model_prices`, `ai_usage_logs`, `ai_usage_counters`, `ai_budgets` | — |
| 15 | `create_notification_channels`, `notification_deliveries` | — |
| 16 (Phase 8) | `plans`, `subscriptions`, `usage_limits` | SaaS billing later; not blocking. |

Steps 1–10 belong to **Phase 3**, 11–12 to **Phase 4**, 13–14 to **Phase 5**, and 15 to **Phase 6**.

---

## 12. Decisions needed (with the default this plan assumes)

| # | Question | Default if not answered |
|---|---|---|
| Q1 | Hosting | **Decided 2026-11-05: cPanel shared hosting only.** No VPS, Redis, Reverb, WebSocket server, Docker or SSH daemons. See §15. |
| Q2 | **LLM provider + model** and the **monthly AI budget** per company | OpenAI-compatible driver with a small, cheap model; ₹2,000/month cap per company; FAQ-only at cap. |
| Q3 | Customer accounts **per website or shared across a company's websites**? | Per website (brief wording). |
| Q4 | Retrieval: **MySQL FULLTEXT only**, or add embeddings? | FULLTEXT + LLM query rewriting; embeddings later. |
| Q5 | Accept that **signup reveals whether an e-mail is already registered** (unavoidable without signup OTP, §3.5)? | Accept, with the mitigations listed. |
| Q6 | Is **`chat.salutehost.com` live with real customers**? | Assume yes: Phase 3 ships C1/C2/C3/C4 hot-fixes first, then the migrations, with a backup before deploying. |
| Q7 | Default `identity_mode` for AmbitionHost websites | `pin_required` (Aarav's instructions). |
| Q8 | Data retention (chats, PII, AI logs) and DPDP-Act deletion requests | Keep chats 24 months, AI excerpts 30 days, audit logs 24 months; "delete my data" handled by staff action. |
| Q9 | WHMCS read-only integration: which website(s) and API credentials | Out of scope until Phase 5; never written to. |

---

## 13. Phase 3 scope (next): secure multi-tenant foundation

**Confirmed priority order (2026-11-05):**

1. Fix all Phase 1 audit findings until the full suite passes.
2. Verify existing functionality and tenant isolation.
3. Build Leads Management (§16).
4. Add secure Excel/CSV lead export.
5. Continue with website admin URLs, Support PIN, tickets, Telegram and AI knowledge as planned.

1. Hot-fixes, which can ship alone: C1 (real reset tokens), C2 (escape Blade output + plain-text storage), C3 (strip secrets from `init`), C4 (MIME-derived extension, no SVG/HTML), H1 (Origin header only, all widget endpoints), H4 (SSRF guard: resolve DNS, block private/link-local/loopback, no redirects), H5, H6 (attach limiters, key on IP + website), M1, B1–B4.
2. Repository hygiene: remove `salutehost_chatbox.sql` and `scratch/` from git, add `*.sql` and `scratch/` to `.gitignore`, `.htaccess` deny rules, placeholder-only `.env.example`, remove demo logins from README.
3. Tenancy layer (§2), migrations 1–10, policies, audit log.
4. Customer identity and the widget login screens (§3).
5. Sanctum cookie auth for the dashboard, token expiry for API tokens.

**Exit criteria:** all 16 Phase 1 audit tests pass, and new tests cover tenant isolation (cross-tenant ID tampering on every staff route), customer PIN signup/login/lockout/OTP reset/session revocation, internal-note invisibility, attachment authorisation, and sync without loss. Results will be reported with real test output.

---

## 14. Known limitations of this plan

- Not validated against a live MySQL instance yet. FULLTEXT behaviour for Devanagari and Hinglish needs measurement in Phase 5.
- AI replies via the cron queue can take up to ~60 s in the worst case; §15.3 describes the mitigation.
- The customer session in the host site's `localStorage` is exposed to XSS on that host site.
- Signup reveals e-mail registration (§3.5).
- Cost estimates depend on provider pricing at the time; prices are admin-editable, not hard-coded.

---

## 15. cPanel-only hosting design (confirmed 2026-11-05)

ChatBox must run on standard cPanel shared hosting. Nothing in the design may require Redis, Reverb or any WebSocket server, Node.js at runtime, Docker, SSH-started daemons or root access.

### 15.1 What runs where

| Need | cPanel-compatible implementation |
|---|---|
| Web/API | Apache + PHP-FPM, Laravel in `~/chatbox`, document root → `public/` (subdomain) |
| Database | MySQL/MariaDB (InnoDB, utf8mb4) |
| Dashboard | Next.js `output: 'export'` static files copied to `public/app/`, served by Apache; no Node on the server (build locally or in CI) |
| Live chat | AJAX polling with `after_seq` cursor (no WebSockets). `BROADCAST_CONNECTION=null` / `log`. |
| Background jobs | `QUEUE_CONNECTION=database`; scheduler runs `queue:work --stop-when-empty --max-time=50` every minute (`withoutOverlapping`) |
| Scheduled work | One cPanel cron line: `* * * * * cd ~/chatbox && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1` (the exact PHP path differs per host and is checked during setup) |
| Cache / sessions / rate limits | `CACHE_STORE=database`, `SESSION_DRIVER=database` (Redis optional, never required) |
| Files | `storage/app/private` (outside document root) for attachments and knowledge uploads |
| Mail | cPanel SMTP mailbox or external SMTP, sent from a queued job |
| Documents | Pure-PHP libraries only: `smalot/pdfparser`, `phpoffice/phpword`, `openspout/openspout` (no `pdftotext`, LibreOffice or Python) |
| Lead export | `openspout/openspout` streams XLSX row by row (low memory, needs `zip` + `xmlwriter`) |

### 15.2 Pre-implementation environment check

A new `php artisan chatbox:env-check` command will report, and a matching cPanel deployment checklist will cover:

- PHP ≥ 8.2 (the project's `composer.json` requirement) and the CLI PHP path used by cron (it often differs from web PHP)
- Extensions: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `zip`, `xml`, `xmlwriter`, `dom`, `ctype`, `bcmath`, `curl`, `intl` (optional), `gd` (optional)
- `proc_open`/`exec` availability (not required by ChatBox; Composer needs them only if run on the server)
- `memory_limit`, `max_execution_time`, `upload_max_filesize`, `post_max_size`
- MySQL version (FULLTEXT on InnoDB requires MySQL ≥ 5.6 / MariaDB ≥ 10.0.5) and `max_allowed_packet`
- Writable `storage/` and `bootstrap/cache/`
- Whether the cron has actually run (the scheduler writes a heartbeat timestamp, and the dashboard shows "Background jobs last ran X min ago")
- Outbound HTTPS to the LLM provider and `api.telegram.org` (some hosts firewall outbound traffic)

If Composer cannot run on the host, `vendor/` is built locally with `composer install --no-dev --optimize-autoloader` using the same PHP version and uploaded.

### 15.3 Limitations and the cPanel answer to each

| Limitation | Effect | Mitigation |
|---|---|---|
| Cron granularity is 1 minute | A queued AI reply may wait up to ~60 s | **Hybrid dispatch:** the customer's message is saved immediately. The widget then calls `POST /widget/conversations/{id}/process`, which runs the AI step *inside that request* (with a lock and a 25 s HTTP timeout to the LLM) after the response is flushed (`dispatch(...)->afterResponse()`). The cron worker is the fallback for anything that failed or timed out. The widget shows a "typing…" state, and after 45 s offers "Talk to a human / create ticket". |
| No WebSockets | Updates arrive by polling | Adaptive polling: 3 s while the widget is open and active, 10 s when idle, 30 s when the tab is hidden, stopped when the widget is closed. A lightweight `GET /widget/conversations/{id}/sync?after_seq=N` returns `204 No Content` when nothing changed and uses an indexed `(conversation_id, seq)` lookup. The dashboard polls one combined `inbox/changes?since=` endpoint, not one request per conversation. |
| Process limits (entry processes, CPU seconds, `max_execution_time`) | Long jobs get killed | Every job is small and resumable: document extraction processes N pages/rows per run and saves progress; worker `--max-time=50`, `--timeout=45`; `withoutOverlapping()` prevents stacked workers. |
| Memory limit (often 256–512 MB) | Large XLSX/PDF can exhaust memory | Streaming readers/writers (OpenSpout), 10 MB upload cap, page/row caps, chunked queries. |
| Cron may be disabled or misconfigured | Reminders, Telegram and email stop silently | Heartbeat check above plus a dashboard warning banner; the hybrid AI dispatch still works without cron. |
| Shared IP / outbound mail limits (hourly email caps) | OTP emails delayed | OTP and transcript mail are rate limited per customer; delivery failures are shown honestly ("we couldn't send the code, try again later"). |
| Exact timing of reminders | ±1 minute | Acceptable for the reminder rules (10 min / 30 min / 2 h, 10 AM daily). |

### 15.4 Removed from the plan

- Laravel Reverb and Pusher as required components. Broadcast events remain in code but are no-ops (`null` driver); any optional real-time server must never be required.
- Any recommendation to move to a VPS.

---

## 16. Leads Management (added 2026-11-05)

The existing CRM view treats `visitors` rows as leads. That mixes device sessions with sales prospects and creates duplicates per browser. A dedicated `leads` table is added and existing visitor data is preserved.

| Table | Key columns |
|---|---|
| `leads` | `id` (uuid), `tenant_id`, `property_id`, `reference` (`L-000123`, per company), `visitor_id` (nullable), `customer_account_id` (nullable, later), `name`, `email`, `email_normalized`, `phone`, `website` (customer's own site, optional), `interested_service`, `inquiry_summary`, `status` (`new`, `contacted`, `interested`, `follow_up`, `converted`, `closed`), `assigned_to`, `source` (`chat`, `manual`, `import`), `last_interaction_at`, timestamps |
| `lead_notes` | `lead_id`, `tenant_id`, `user_id`, `body`, timestamps (internal only) |
| `lead_conversation` | `lead_id`, `conversation_id` (links leads to chats; tickets link through the conversation once tickets exist) |

**Matching and deduplication (no unsafe merges):** one lead per visitor identity. The lookup order is:

1. A lead already linked to this `visitor_id`.
2. (later) A lead linked to the authenticated `customer_account_id`.
3. Otherwise a new lead.

A lead is **never** merged on name alone or on an unverified email alone. Staff can merge two leads manually, with an audit entry. New messages only update `last_interaction_at` and fill *empty* fields; they never overwrite staff edits.

**Permissions:** company owners/admins see every website's leads. Agents see leads of websites they are assigned to (all company websites until the `property_user` pivot lands). Every query, filter and export goes through the same scoped query.

**Export:** CSV and XLSX, streamed. They export all leads or the current filter. Cells that start with `=`, `+`, `-`, `@`, tab or carriage return are prefixed with `'`. Each export is audit-logged (filters, row count).
