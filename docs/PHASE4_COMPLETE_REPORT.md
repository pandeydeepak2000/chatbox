# ChatBox — Full Implementation Report (Phase 4)

**Date:** 5 November 2026 · **Branch:** `main` (uncommitted working tree) · **Status:** implemented and tested locally; **not deployed**, no live data touched.

Builds on `PHASE3_REPORT.md` (audit fixes + Leads). The existing Laravel app was extended, not rebuilt. All database changes are additive migrations.

---

## 1. Test results (real runs, 5 Nov 2026)

| Suite | Environment | Result |
|---|---|---|
| PHPUnit (unit + feature) | SQLite in-memory | **121 passed**, 756 assertions |
| Same suite | MariaDB 10.4 (scratch DB `chatbox_mysql_ci`, never the real `chatbox` DB) | **121 passed**, 756 assertions |
| MySQL FULLTEXT knowledge search | MariaDB with committed data (scripted check) | passed |
| Browser end-to-end (Chrome, Puppeteer) | Local server + throwaway SQLite DB | **21 / 21 steps passed, 0 JavaScript errors** |
| `composer audit` / `npm audit` | — | 0 vulnerabilities |
| ESLint (dashboard) | — | clean (one intended `<img>` warning) |

End-to-end steps (screenshots in `docs/screenshots/e2e/`, raw output in `RESULT.txt`):

1. Widget loads on a whitelisted site → FAQ question answered instantly (no AI cost).
2. Customer creates e-mail + 6-digit Support PIN (no OTP) and stays in the same chat.
3. Unanswerable question → ticket `AMB-000001` created and the reference shown to the customer.
4. `/demo-hosting/admin` shows a website-branded login → owner signs in.
5. Ticket shows a summary → staff reply (~0.6 s round trip) and internal note.
6. Every dashboard page opens with data: Overview, Live Inbox, Leads, Knowledge, AI Settings, Websites, Team, Notifications, Analytics, Company.
7. Knowledge draft reviewed and approved in the UI.
8. **Second device (phone size):** customer signs in with e-mail + PIN → sees the ticket and the staff reply; the internal note is **not** visible.
9. Wrong PIN → generic error.
10. Agent restricted to one website cannot sign in on another website's admin URL.
11. Super admin panel; dashboard on a phone.

### Defects the end-to-end run found (all fixed and covered)

| Defect | Fix |
|---|---|
| Widget wiped what the customer was typing on the sign-up / sign-in form every few seconds while a chat was open (polling re-rendered the panel) | Polling re-renders only when something changed; all typed fields, focus and caret survive re-renders; chat no longer jumps to the bottom while reading history |
| Ticket subject was the customer's *first* message (already answered by the FAQ) instead of the problem that caused escalation | Subject and summary use the latest substantive message; earlier messages listed as context; "talk to a human" phrases skipped (new test) |
| Staff reply left the ticket "Unassigned" while the conversation was assigned to that person; inbox replies did not record ticket history | One shared `TicketService::staffReplied()` for both paths; first responder claims an unassigned ticket with ownership history (2 new tests) |
| Ticket list unreadable on phones (status/priority off-screen) | Table becomes stacked cards below 640 px |
| AI cost was priced on the configured model even when a fallback model answered (found while writing this report) | Cost uses the price of the model that actually replied (new test) |

---

## 2. What is implemented

| Module | Implemented |
|---|---|
| **Security & auth** | All Phase 1 audit findings (see Phase 3 report), Sanctum token expiry, staff password reset, rate limits per endpoint, ownership checks on every staff endpoint, website-scoped staff access, audit log |
| **Website admin URLs** | `/{slug}/admin` per website with branding; staff without access to that website are refused with a generic message; company owner sees all websites |
| **Roles** | Super admin · company owner/admin · per-website admin · agent (per-website access list) |
| **Support PIN (H8)** | E-mail + 6-digit PIN sign-up without OTP; forgot PIN via 10-minute single-use e-mailed code (no links); lockout 15 min → 1 h → 24 h after 5 failures; new sign-up never receives or overwrites an existing account's data; per-website customer accounts; guest chats bound to the device token |
| **Live chat** | Polling with sequence numbers (no lost/duplicate messages), retries with idempotent client IDs, private signed attachments, departments, assignment/transfer, takeover, resume AI, internal notes, block visitor, mobile full-screen widget |
| **Tickets** | Automatic on "talk to a human" outside hours / nobody online, on AI escalation and on restricted requests; references per company (`AMB-000001`); categories, priorities, statuses; assignment with history (previous + current owner can see it); private notes; DB-level duplicate protection; customer tracking under *Support Tickets* via PIN; reopen on customer reply within 7 days; resolved tickets keep history |
| **AI knowledge base** | Manual entries and TXT/CSV/XLSX/PDF/DOCX uploads; company-wide or per website; draft → approve/reject/archive; versions; FAQ answered before any AI call; AI answers only from approved knowledge with citations checked; forbidden claims (refund done, payment received, etc.) blocked; one clarifying question, then escalation |
| **AI provider & budget** | Per company: provider (Anthropic or OpenAI-compatible), model, encrypted API key, effort; monthly / daily caps, per-visitor daily limit, 80 % alert, hard stop → FAQ-only + human; cost logged per reply from a price table (unknown price → AI blocked) |
| **Telegram** | Per-company bots/chats; events: ticket created, human requested, sales alert, reminders, assignment, AI budget; delivery log with retries (back-off, max 5); token never shown or logged |
| **Operations rules** | Working hours 09:00–18:00 IST every day (company and per-website override); sales enquiry alert after 30 *working* minutes, once; quotation reminders at 10 m / 30 m / 2 h from the original quote, cancelled by a customer reply; 10:00 IST daily digest; reading ≠ resolving |
| **Leads** | As in Phase 3, now also in the new dashboard (filters, statuses, notes, assignment, Excel/CSV export with formula-injection protection) |
| **Dashboard** | New responsive dashboard (static Next.js build in `public/app`, no Node in production): Overview, Live Inbox, Support Tickets, Leads, Knowledge Base, AI Settings, Websites & Widget, Team & Departments, Notifications & Tasks, Analytics & Reports, Company Settings, Super Admin. Every button calls a real endpoint |
| **SaaS readiness** | Plans (free / pro / enterprise / internal) with limits on websites, staff, knowledge documents, monthly conversations and AI spend; subscriptions managed by super admin. Payment gateway not included (planned later) |
| **cPanel** | Polling only, database queue, single cron line, `chatbox:env-check`, no Redis/WebSockets/Node at runtime |

## 3. Not done / limitations (honest list)

| Item | Status |
|---|---|
| **WHMCS read-only integration** (order/invoice/service status) | **Not implemented.** The bot never claims payment, refund or service status; such questions go to staff |
| Real AI provider calls | Code path written against the official Anthropic PHP SDK; **tests use a fake model**, so no live API call has been made. Enter a key on staging and test before enabling |
| Real Telegram delivery | Tested with faked HTTP; send a *Test* message from *Notifications* on staging |
| Real e-mail (PIN reset codes, digests) | Tested with the log mailer only |
| Apache `.htaccess` (Option B document root) | Reviewed, **not tested on Apache** |
| Legacy Blade dashboard (`/dashboard`) | Still present as a fallback; the new dashboard is `/app` and `/{slug}/admin` |
| Staff tokens | Stored in browser `localStorage` (7-day expiry) — acceptable with the strict CSP/escaping in place, but an HttpOnly cookie would be stronger |
| Sign-up e-mail check | Sign-up with an existing e-mail says the account exists (and mails the owner). This reveals that the e-mail is registered on that website |
| Payment gateway | Not started (by design, later phase) |

## 4. Things to know

- **Next.js security advisory:** the dashboard was on Next.js 16.0.x, affected by published critical advisories. It was upgraded to **16.4.5** (patch upgrade within 16.x). `npm audit` reports 0 vulnerabilities. Production does not run Node; the dashboard is static files.
- **AI defaults:** model `claude-opus-5-5` with effort `low`; `claude-haiku-5-5` is the lowest-cost choice in the dropdown. Anthropic calls send `fallbacks: 'default'`, so if the chosen model is temporarily unavailable the API may serve the reply from a fallback model. The cost is recorded from the model actually used.
- AI is **off by default** for every company. FAQs, tickets and human hand-off work without it.

## 5. Database changes

11 additive migrations (6 from Phase 3 + 5 new): customer identity & website access, tickets & conversation handling, notifications/tasks/Telegram, knowledge & AI, plans & subscriptions. Existing websites are set to `guest_only`, so live widgets behave as before until the Support PIN is switched on. Rollback: `php artisan migrate:rollback --step=11`.

## 6. Before going live (needs your approval)

1. Rotate all secrets that were in git history (see Phase 3 §8).
2. Back up the live DB; run the migrations on a **staging copy** first.
3. On staging: set an AI key and send test questions; send a Telegram test; test PIN-reset e-mail delivery; run the checklist in `CPANEL_DEPLOYMENT.md` §8.
4. Set `BROADCAST_CONNECTION=null` (the local `.env` still says `reverb`; `chatbox:env-check` flags it).
5. Decide whether WHMCS read-only lookups are needed for launch.

---

## सारांश (Hindi)

- पूरा प्रोजेक्ट लोकल पर बना और टेस्ट हुआ है: **121 ऑटोमेटेड टेस्ट SQLite और MySQL दोनों पर पास**, और ब्राउज़र में **21/21 एंड-टू-एंड स्टेप पास**, कोई JavaScript एरर नहीं।
- ग्राहक ई-मेल + 6 अंकों के Support PIN से साइन-अप करता है, दूसरे फ़ोन/ब्राउज़र पर भी अपनी चैट और टिकट देख सकता है। Internal note ग्राहक को कभी नहीं दिखता।
- जवाब न मिलने पर या "human" माँगने पर अपने-आप टिकट बनता है (जैसे `AMB-000001`), Telegram अलर्ट जाता है, और जवाब देने वाला स्टाफ़ टिकट का owner बन जाता है।
- हर वेबसाइट का अलग admin URL है (`/website-slug/admin`)। स्टाफ़ सिर्फ़ अपनी वेबसाइट देख सकता है।
- AI डिफ़ॉल्ट रूप से बंद है; बजट की सीमा पूरी होने पर सिर्फ़ FAQ और इंसानी टीम से जवाब मिलता है।
- **अभी बाकी:** WHMCS integration, असली AI/Telegram/e-mail की staging पर जाँच, Apache पर टेस्ट। लाइव सर्वर पर कुछ भी deploy नहीं किया गया है।

---

## 7. Update — 5 Nov 2026 (evening)

### Changes
| Area | Change |
|---|---|
| **Local database** | Backed up to `C:\laravel\chatbox_backups\chatbox_before_upgrade_2026-11-05.sql`, then all 12 pending migrations run on the local `chatbox` DB (owner's request). Existing rows verified unchanged (5 companies, 9 users, 6 websites, 4 chats, 8 messages); leads, subscriptions, ticket prefixes and admin addresses filled in. |
| **Passwords** | New passwords set for `ambitionmanagements@gmail.com`, `admin@salutehost.com`, `admin@chatbox.com` (owner's request); old sessions signed out; audited. Passwords were given to the owner directly, not written in any file. |
| **Old login screen** | `/login`, `/dashboard`, `/register`, `/workspace` open the new dashboard (query string kept for reset links). Old dashboard at `/legacy-dashboard`. |
| **Super admin** | Sees only the platform page; company-only pages (and the "Only company administrators…" error) no longer appear. |
| **Support PIN in the chat** | Chat asks for the e-mail; new e-mail → create PIN in 6 boxes; known e-mail → enter PIN; wrong PIN clears the boxes; "Forgot PIN?" pre-fills the e-mail. New endpoint `POST /widget/customer/identify` (rate-limited like login). |
| **Customers page (new)** | Lists Support PIN customers per website; website admins can **Reset PIN** (customer signed out everywhere + security e-mail; does not mark the e-mail verified) and **Unlock**. Endpoints `GET /customers`, `POST /customers/{id}/reset-pin`, `POST /customers/{id}/unlock`. Agents get 403, other companies 404. |
| **Professional UI** | New design system (Inter font, Lucide icons instead of emoji, grouped sidebar, breadcrumb top bar, split-screen sign-in, readable labels instead of system values, timelines, empty states with guidance) on all 13 dashboard pages. Widget: proper chat icon, no "Support Support" header. |
| **Smaller fixes** | Visitor e-mail filled from the PIN account (staff saw "Website visitor"); a device signed out by a PIN change returns to the e-mail step; platform plan price shown. |
| **Docs** | `docs/WORKFLOW_GUIDE.md`: complete workflow guide with examples. |

### Test results (real runs)
| Suite | Result |
|---|---|
| PHPUnit, SQLite | **124 passed** (786 assertions) |
| PHPUnit, MariaDB scratch DB | **124 passed** (786 assertions) |
| Browser end-to-end | **23 / 23 passed** on 3 clean runs. One earlier run failed the PIN-reset step once and did not reproduce. Screenshots: `docs/screenshots/e2e/` |

The 2 console 404s in the last run are the expected "session ended" responses after the admin PIN reset.

### Notes
- `npm audit` now reports **1 moderate advisory (minimatch)** inside ESLint dev tooling only. It is not shipped to the server; the offered fix would downgrade `eslint-config-next`, so it was left as is.
- `lucide-react 0.468.0` (icons) was added; the Inter font is bundled at build time (no browser request to Google).
- Still open: WHMCS read-only lookups, real AI/Telegram/e-mail checks on staging, Apache `.htaccess` test.

---

## 8. Production readiness review — 7 Nov 2026

### Checked and fixed
| Check | Result |
|---|---|
| Secrets in files to be committed (passwords, API keys, Telegram tokens, APP_KEY, DB/mail passwords) | None found; only placeholders and a fake test key |
| `php artisan config:cache` / `route:cache` | Both succeed |
| `env()` used outside config files (returns null once config is cached) | Removed from the widget controller; WebSocket details are now sent only when `BROADCAST_CONNECTION=reverb` |
| E-mail check in the chat (`/widget/customer/identify`) could be used to test many e-mails | Own limit: 10 per minute and 40 per hour per IP |
| Queue, cache and session tables for cPanel | Present (`jobs`, `cache`, `sessions` migrations) |
| `.env.example` | `APP_ENV=production`, `APP_DEBUG=false`, secure cookies, `BROADCAST_CONNECTION=null`, database queue/cache |
| `composer audit`, `npm audit --omit=dev` | 0 vulnerabilities |
| Built dashboard `public/app` committed; `frontend/out`, `node_modules`, `.next`, `.env`, `*.sql`, `*.sqlite` ignored | Yes |

### Still open (needs a decision, credentials or the live server)
1. **Rotate secrets that are in old git history** (Phase 3 §8). Deleting `salutehost_chatbox.sql` in this commit removes it from the current files only; it stays in older commits on GitHub. If it contained real customer data, consider rewriting the history (e.g. `git filter-repo`) and treat the data as exposed.
2. **WHMCS read-only lookups**: not built; needs WHMCS API credentials and a decision.
3. **Real AI, Telegram and e-mail**: tested with fakes only; verify on staging with real keys.
4. **Apache**: `.htaccess` rules not yet tested on the cPanel server.
5. **`vendor/` includes dev tools**: harmless (not web-reachable); use `composer install --no-dev` where possible.
6. **Staff sign-in tokens** are kept in browser storage (7-day expiry). An HttpOnly cookie would be stronger; possible later improvement.

### Tests for this review
- PHPUnit on SQLite: **124 passed** (782 assertions) after these changes.
- PHPUnit on MariaDB: last full pass on 5 Nov (124 passed). It could not be repeated after these small changes because the local MySQL server (XAMPP) was not running. The changes do not touch database queries (an `env()` cleanup, a rate limit and one test update). Run it again once MySQL is started.
