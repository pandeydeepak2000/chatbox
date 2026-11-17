# Premium support flow: technical notes

Branch `feature/premium-support-experience`. Everything below is additive and cPanel-compatible (polling + cron, no Redis, no WebSockets, no VPS services).

## 1. Conversation state machine

`app/Services/ConversationStateMachine.php`, table `conversation_state_logs`, columns `conversations.support_state / support_state_changed_at / escalation_requested_at`.

```
AUTO_HANDLING → ESCALATION_REQUESTED → QUEUED_FOR_TEAM → AGENT_ASSIGNED → AGENT_ACTIVE ⇄ WAITING_FOR_CUSTOMER → RESOLVED → CLOSED
                                       (RESOLVED / CLOSED can reopen; AUTO_HANDLING again only via an audited "Resume AI")
```

- State names are internal. The widget API never returns them (it gets `waiting_senior` / `agent_active` booleans).
- Every change runs under a row lock, refuses illegal moves, is idempotent (`CHANGED | SAME | REFUSED`) and is logged with actor.
- Agent actors must belong to the conversation's company and have access to its website. The platform super admin is refused.
- `conversations.handler = 'human'` remains the single switch that stops automated replies. The bot re-checks it under a row lock before saving a reply.

Who moves the state:

| Event | Code | New state |
|---|---|---|
| Customer asks for a person / bot cannot answer / restricted action | `SupportBot::transferSupportTeam` → `EscalationService::request` → `ConversationService::queueForTeam` | ESCALATION_REQUESTED → QUEUED_FOR_TEAM |
| Ticket assigned to a person | `TicketService::assign` | AGENT_ASSIGNED (+ one "assigned" notice) |
| Agent clicks Take over / sends a reply | `ConversationService::takeOver / markHuman` | AGENT_ACTIVE (+ one "joined" notice) |
| Ticket → waiting for customer / resolved / closed | `TicketService::changeStatus` | WAITING_FOR_CUSTOMER / RESOLVED / CLOSED |
| Customer replies while waiting | `TicketService::customerReplied` | AGENT_ACTIVE |
| Resume AI | `ConversationService::resumeAi` | AUTO_HANDLING |

## 2. The real 60-second senior-support window

> Correction history: an earlier revision removed the countdown and replaced it with an open-ended "stay in this chat". That was wrong: the 60-second window is a business rule (a real opportunity for a team member to join). It is restored below and driven by the server clock.

Code: `App\Services\EscalationService`, `App\Models\Escalation`, table `escalations`, scheduler command `chatbox:escalations`.

| Step | Behaviour |
|---|---|
| Start | `EscalationService::request()` under a conversation row lock: `escalated_at = now()`, `deadline_at = escalated_at + 60 s`, `status = waiting`, `open_key = conversation_id` (unique: one open escalation per chat). Automated replies stop (`handler = human`). The customer message is *"Please wait a moment. We're connecting you with our senior support team."* |
| Duplicates | A second request while one is waiting (button, message, refresh, retry) returns the same row. A chat that already has an open ticket or an active agent starts nothing. |
| Countdown | The widget shows the server's `senior_wait_remaining` (from `deadline_at − now`), re-synced on every poll/init/sign-in. It is never restarted by the client. |
| Agent joins | `onAgentJoin()` (take over or first staff reply): inside the window → `joined`, no ticket. After the deadline but not yet finalised → ticket registered first (once), then the agent continues in the same chat (`finalized_via = agent_late`). |
| Nobody joins | `finalize()` creates/reuses exactly one ticket (`idempotency_key = esc-<id>`), writes the customer message once and moves on. |
| Race safety | Every path locks conversation row → escalation row and re-reads `status` under the lock; the loser sees the final status. Takeover vs timeout therefore always ends in one consistent state (tests cover both orders and the 1-second-before case). |
| Who finalises | (1) the widget's `/sync` poll while the chat is open (within a poll, ~2.5 s); (2) the scheduler `chatbox:escalations` every minute for visitors who left. **cPanel delay:** cron granularity is one minute, so for an offline visitor the ticket appears 0–60 s after the deadline (≈2 minutes after the escalation at worst). |
| Outside working hours | Nobody can answer within a minute, so no countdown is staged: `deadline_at = escalated_at`, the ticket is registered immediately and the message states the working hours. (Product decision; change `EscalationService::request()` if a countdown is wanted at all times.) |

Customer-facing texts (EN / HI / Hinglish) are in `SupportBot::T`: `escalation_wait`, `ticket_registered`, `ticket_registered_guest` (no PIN sentence for guests without an account), `assigned`, `identity`.

Other rules kept from before: "Joined" notices only after a real authenticated takeover; the assistant answers truthfully if asked whether it is automated; no technical labels in the customer chat.

## 2b. Live chat session and Telegram events

- `POST /api/v1/widget/session` (called by `widget.js` when the visitor opens the chat) creates/reuses a `chat_sessions` row (reference `CH-<PREFIX>-XXXXXX`) and queues the `chat.started` Telegram event **before e-mail, PIN or any question**. The first question later attaches to the same session (`conversations.chat_reference`). A client that never calls `/session` gets the session at `/start`, so there is exactly one alert either way. Trade-off: every visitor who opens the chat produces an alert; limit it by rate limits/bot shield or by moving the call to the department/e-mail step if that is too noisy.
- `Notifier::telegram()` writes the event (`notification_deliveries`, status `pending`) **before** sending and has a durable idempotency key (`dedupe_key`, unique per channel): `chat-session:<id>`, `escalation:<id>`, `ticket-reminder:<ticket>:<cycle>:<n>`. Failures are stored with the error and retried by cron with back-off (max 5 attempts); rows that never got a first attempt (process died after the response) are rescued after one minute. **Delivery is not guaranteed** (Telegram can be down): what is guaranteed is that the event is recorded and retried, and failures stay visible in the delivery history.
- Events: `chat.started` (live chat opened), `human.requested` (senior-support window, with the deadline), `ticket.created`, `tickets.reminders`.

## 2c. 30-minute ticket reminders

`Notifier::checkPendingTicketReminders()` (run by `chatbox:reminders` every minute). Per ticket, per pending period: `tickets.team_pending_since` + `tickets.reminder_cycle`, history in `ticket_reminders` (unique `ticket_id, cycle, sequence`). Statuses reminded: `open`, `pending_team` (not `pending_customer`, unless `CHATBOX_REMIND_WAITING_CUSTOMER=true`). Resolved/closed stops it; a reopen or a customer reply starts a new cycle. If cron was paused, only the latest due reminder is sent.
## 3. Accuracy and company isolation

- Removed SaluteHost-specific FAQ row-order boosts and the hard-coded SaluteHost product list from shared code. Intent boosts now look only at the FAQ's own text.
- A FAQ must overlap with the question or category, not just the answer body.
- Retrieval uses the current customer message; the previous one is added only for short follow-ups.
- The prompt is company-scoped (names the site), concise, context-aware, and forbids inventing agents or claiming a handover.
- Knowledge search was already scoped by `tenant_id` + `property_id`. Tests assert that company B's FAQ never answers company A.

## 4. Telegram (internal only)

See 2b. Alerts contain company, website, chat/ticket reference, department, masked e-mail, a 160-character excerpt and a sign-in-protected dashboard link. No chat history. Nothing Telegram-related reaches the widget.

## 5. Platform admin isolation

`company.data` middleware (`DenyPlatformAdmin`) returns 403 for the super admin on chats, tickets, leads, customers, knowledge, AI settings, Telegram settings, notifications and analytics. Previously `EnsureRole` gave the super admin universal access and many controllers removed the company scope for them.

## 6. Admin AI: providers, models, answer review

**Five providers, one key each per company** (`ai_providers`, `ai_models`; the active choice is mirrored into `ai_settings`, so the engine, budgets and usage log are unchanged): OpenAI, Google Gemini, OrvAPI, Anthropic (Claude), Custom Provider.

| Provider | Endpoint (fixed) | Model list | JSON mode |
|---|---|---|---|
| OpenAI | `https://api.openai.com/v1` | `GET /models` | `response_format` sent |
| Google Gemini | `https://generativelanguage.googleapis.com/v1beta/openai` (OpenAI-compatible layer) | `GET /models` (ids lose the `models/` prefix) | not sent |
| OrvAPI | `https://api.orvapi.com/v1` | `GET /v1/models` | **not sent**: its chat completions rejects `response_format` (https://www.orvapi.com/docs/) |
| Anthropic | official SDK | `GET /v1/models?limit=1000` | SDK structured output |
| Custom | administrator's `https://…` address | optional discovery path | per-provider flag |

- Models are discovered live, never from a hard-coded list. "Saved" ≠ "available": `catalog_status` (listed by the provider?) and `test_status` (a real request) are separate from `enabled`.
- Statuses shown separately: API connection, selected model, AI enabled, spend. A good connection or a model test never switches AI on; making a model the default does not either. Only the administrator's toggle does.
- Errors are classified (invalid key, rate limit, outage, unreachable, no such model). Observed against the live APIs on 2026-11-17: OpenAI, OrvAPI and Anthropic answer a bad key with HTTP 401, **Gemini with HTTP 400** ("Please pass a valid API key"); `LlmResult::kindOf()` handles both and discards the provider text, which is never stored or relayed. Keys never appear in any response, log or audit entry.
- **Custom providers**: `https` on port 443, host name only (no IP literal), public addresses only, validated again at request time with the vetted IP pinned and redirects off, unusable until approved by a platform administrator (Platform → *Custom AI Providers*; super admin sees name, host and status only, never keys). Hosts in `CHATBOX_AI_CUSTOM_HOSTS` (comma separated, sub-domains included) are approved automatically. Changing the address requires approval again. Existing companies' custom endpoints are grandfathered as approved by the migration.
- **Price**: the company administrator confirms input/output USD per 1M tokens for each model; the platform price table is the fallback. No price = AI stays off (`no_price`). Nothing is guessed.
- No automatic retries (a failed request is never sent twice), per-visitor and budget limits unchanged.
- Answer review (`/api/v1/ai/answers`, `…/review`, `reviews/{id}/approve|reject`, `quality`, `regression/run`): stars, incorrect flag, typed correction. A correction is only a proposal; approving it creates versioned, approved, website-scoped knowledge plus a regression case. `php artisan chatbox:ai-regression` (daily 03:30 IST) checks that every approved correction is still retrievable (no AI call).

### Service-only assistant

The assistant answers about the company's own services only. Order: greeting/small talk (free) → FAQ (free) → deterministic off-topic gate (free) → grounded AI answer → human escalation (60-second window).
- Clearly unrelated questions (sport, politics, entertainment, jokes, weather, trivia…) get a fixed polite redirect: no ticket, no countdown, no AI call. A service signal (hosting, domain, billing, error…), the company's own vocabulary (administrator's "Services we support" list, approved document titles, FAQ questions) or matching approved knowledge always wins, so a real customer is never turned away by a keyword.
- The model may answer `out_of_scope`, honoured only when nothing points at the company's services and always answered with the fixed text, never the model's own words.
- A reply containing an e-mail, phone or card-like number that is not already in the conversation or the approved passages is never sent.

## 6b. Company dashboard addresses

`https://host/{company}` and `/{company}/inbox|tickets|leads|customers|knowledge|ai|aireview|websites|team|notifications|analytics|company` serve the same dashboard as `/app`, under the company's own address (`tenants.slug`, backfilled for every existing company from its name, unique, editable by the company admin in *Company settings* or by the platform admin; reserved words and duplicates are refused). The route is registered last, so `/app`, `/login`, `/{website}/admin` keep priority; only a real company and a known view are served, anything else is 404.
- Sign-in at a company address accepts only that company's people (`tenant_slug`); another company's staff or a platform admin get the same generic error as a wrong password.
- The address is presentation only: access is decided on the server from the signed-in account. A signed-in user visiting another company's address sees a "belongs to another company" screen, no data.
- The dashboard now reads the view from the path (`/salute/inbox?id=…`); old `#/inbox` links and `/app/inbox?id=…` Telegram links are understood. Telegram/notification links use the company address.
- Changing the address changes only `tenants.slug`: same company id, users, websites, chats, tickets, AI settings and keys, ticket counter and prefix, sessions. The old address becomes 404; `/app` keeps working.
## 7. Cron / polling

One cPanel cron entry running `php artisan schedule:run` every minute. Scheduled: `chatbox:escalations` (every minute, first), `chatbox:reminders` (every minute: task/sales reminders, 30-minute ticket reminders, Telegram retries), `chatbox:ai-regression` (daily). The widget polls `/sync` (2.5 s while waiting, slower when idle). No Redis, WebSocket server or Supervisor is needed.

## 8. Migrations (run `php artisan migrate`)

- `2026_11_16_100000_add_support_state_machine` (adds columns, state log table, backfills existing chats)
- `2026_11_16_110000_create_ai_answer_reviews` (reviews and regression cases)
- `2026_11_16_120000_create_escalations_chat_sessions_and_reminders` (escalations, chat sessions, Telegram `dedupe_key`, ticket pending period + reminder history)
- `2026_11_17_100000_create_ai_providers_and_models` (provider/model tables; backfills every existing company's current provider, model and key by copying: ai_settings itself is not modified)
- `2026_11_17_110000_add_slug_to_tenants` (company address; backfilled from the company name, unique)
