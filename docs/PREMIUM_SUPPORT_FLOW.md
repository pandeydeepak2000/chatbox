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
| Customer asks for a person / bot cannot answer / restricted action | `SupportBot::transferSupportTeam` → `ConversationService::queueForTeam` | ESCALATION_REQUESTED → QUEUED_FOR_TEAM |
| Ticket assigned to a person | `TicketService::assign` | AGENT_ASSIGNED (+ one "assigned" notice) |
| Agent clicks Take over / sends a reply | `ConversationService::takeOver / markHuman` | AGENT_ACTIVE (+ one "joined" notice) |
| Ticket → waiting for customer / resolved / closed | `TicketService::changeStatus` | WAITING_FOR_CUSTOMER / RESOLVED / CLOSED |
| Customer replies while waiting | `TicketService::customerReplied` | AGENT_ACTIVE |
| Resume AI | `ConversationService::resumeAi` | AUTO_HANDLING |

## 2. What the customer is told (real state only)

- Team online in working hours: "Main aapki request senior team ke review ke liye bhej raha hoon. Aap isi chat mein bane rahiye." No ticket yet. If nobody joins within `QUEUE_NOTICE_SECONDS` (60) the ticket reference is shown.
- Team offline / outside hours, or a staff-only request (refund, DNS, …): the ticket reference plus the working hours, immediately.
- Assigned: "Aapki request support team ko assign kar di gayi hai."
- Joined: "{Name} from the support team has joined the conversation." Written only when the state really changed to AGENT_ACTIVE.
- "Are you a bot?" gets a truthful answer (`SupportBot::asksIfAutomated`) and is not turned into a transfer.
- No countdown, no "transferring… 1 minute", no technical labels.

## 3. Accuracy and company isolation

- Removed SaluteHost-specific FAQ row-order boosts and the hard-coded SaluteHost product list from shared code. Intent boosts now look only at the FAQ's own text.
- A FAQ must overlap with the question or category, not just the answer body.
- Retrieval uses the current customer message; the previous one is added only for short follow-ups.
- The prompt is company-scoped (names the site), concise, context-aware, and forbids inventing agents or claiming a handover.
- Knowledge search was already scoped by `tenant_id` + `property_id`. Tests assert that company B's FAQ never answers company A.

## 4. Telegram (internal only)

`Notifier::chatStarted`: one alert per escalation (cache dedupe shared with `humanRequested`), containing company, website, ticket, urgency, department, masked e-mail, a 160-character excerpt and a sign-in-protected dashboard link. No chat history. Nothing Telegram-related reaches the widget.

## 5. Platform admin isolation

`company.data` middleware (`DenyPlatformAdmin`) returns 403 for the super admin on chats, tickets, leads, customers, knowledge, AI settings, Telegram settings, notifications and analytics. Previously `EnsureRole` gave the super admin universal access and many controllers removed the company scope for them.

## 6. Admin AI

- Providers: Anthropic, OpenAI (official, fixed `https://api.openai.com/v1`), OrvAPI (fixed `https://api.orvapi.com/v1`), other OpenAI-compatible (custom URL, SSRF-guarded). Keys are encrypted and never returned.
- `POST /api/v1/ai/test-connection` (admin, throttled 5/min).
- OrvAPI model suggestions come from https://www.orvapi.com/Model (checked 2026-11-16). That page marks models Active/Unavailable dynamically and some are paid-plan only, so always use Test connection.
- Named providers need a price row in the platform price table, otherwise the budget guard keeps AI off (`no_price`).
- Answer review (`/api/v1/ai/answers`, `…/review`, `reviews/{id}/approve|reject`, `quality`, `regression/run`): stars, incorrect flag, typed correction. A correction is only a proposal. Approving creates versioned, approved, website-scoped knowledge plus a regression case. Customer or model text is never promoted automatically.
- `php artisan chatbox:ai-regression` (scheduled daily 03:30 IST) checks that every approved correction is still retrievable. It makes no AI call.

## 7. Cron / polling

Unchanged: one cPanel cron entry running `php artisan schedule:run` every minute. New scheduled item: `chatbox:ai-regression` (daily). The widget polls `/sync` (2.5 s while waiting, slower when idle).

## 8. Migrations (run `php artisan migrate`)

- `2026_11_16_100000_add_support_state_machine` (adds columns, state log table, backfills existing chats)
- `2026_11_16_110000_create_ai_answer_reviews` (reviews and regression cases)
