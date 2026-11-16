# ChatBox — Complete Workflow Guide

**For:** company owners, support staff and the platform administrator.
**What it covers:** what every part of ChatBox does, who can use it, and step-by-step examples from first setup to daily support.

> Examples use the company **AmbitionHost** with the website **AmbitionHost Hosting** (admin address `ambitionhost-hosting`), the owner **Aarav**, the sales person **Chandrika**, and a customer **Rahul** (`rahul@example.com`).

---

> Technical details of the escalation state machine, Telegram alerts, AI administration and company isolation: see [PREMIUM_SUPPORT_FLOW.md](PREMIUM_SUPPORT_FLOW.md).

## 1. ChatBox in one minute

ChatBox puts a chat button on your websites. When a customer asks something:

1. **FAQ match** → the answer is sent instantly at no AI cost.
2. **Approved knowledge** → the AI assistant answers, but only from documents your team approved (if AI is switched on).
0. **The moment a visitor opens the live chat** (before e-mail, PIN or any question) a chat record (e.g. `CH-AMB-7K2QXD`) is created and your Telegram group is alerted: *"New live chat started"*, with company, website, department (if chosen), customer ("Visitor — verification pending" until they sign in), start time and a sign-in-protected link.
3. **No reliable answer, a staff-only request, or the customer asks for a person** → the **60-second senior-support window** starts: the customer sees *"Please wait a moment. We're connecting you with our senior support team."* with a live 01:00 → 00:00 countdown, and your team is alerted on Telegram. If a team member clicks **Take over** before 00:00 the countdown stops and they are in the same chat. If nobody does, **one ticket** (e.g. `AMB-000123`) is registered automatically and shown in the chat.
4. Your team replies from the dashboard. The customer sees the reply in the chat window, on any device, using their **e-mail + 6-digit Support PIN**.

Every chat also creates a **lead**, so sales enquiries are never lost.

---

## 2. Addresses

| What | Address | Who |
|---|---|---|
| Staff dashboard (all websites you can access) | `https://your-domain/app` | Owner, admins, agents |
| Website-specific staff sign-in | `https://your-domain/{website-address}/admin`, e.g. `/ambitionhost-hosting/admin` | Staff of that website only |
| Platform administration | `https://your-domain/app` signed in as the super admin | Platform owner |
| Old dashboard (fallback only) | `https://your-domain/legacy-dashboard` | — |
| Chat widget | Install code from **Websites & Widget → Install code** | Your customers |

`/login` and `/dashboard` now open the new dashboard automatically.

On this computer (local testing) the same pages are at `http://127.0.0.1:8000/app` after running `php artisan serve`.

---

## 3. Who can do what

| Role | Sees | Can change |
|---|---|---|
| **Super admin** (platform) | Only the **Super Admin** page: all companies, plans, AI prices | Create companies, change plans, suspend companies. Does **not** work inside a company's inbox. To do that, sign in with that company owner's account. |
| **Company owner / company admin** | Every website of the company | Everything in the company: websites, team, AI settings, Telegram, knowledge, customers' PINs |
| **Website admin** (agent with the admin role on a website) | Only their websites | That website's settings, FAQs, departments, customers' PINs |
| **Agent** | Only the websites they are given (or all, if none are ticked) | Reply to chats and tickets, notes, leads. Cannot change settings or PINs. |
| **Customer** | Only their own chats and tickets, after signing in with e-mail + PIN | Their own PIN (via "Forgot PIN?") |

> Why does the super admin see "Only company administrators can change AI settings"? It cannot happen any more: the super admin is not part of a company, so company pages are hidden from them. Each company manages its own AI settings.

---

## 4. First-time setup (company owner)

Example: Aarav sets up AmbitionHost.

1. **Sign in** at `/app` with the owner account.
2. **Company Settings**
   - Ticket prefix `AMB` → tickets become `AMB-000001`, `AMB-000002`, …
   - Working hours `09:00–18:00`, every day, timezone `Asia/Kolkata`.
   - Sales follow-up owner: **Chandrika** (she receives quotation reminders and sales alerts).
3. **Websites & Widget → Add website**: name "AmbitionHost Hosting", URL `https://ambitionhost.in`.
   - **General**: admin address `ambitionhost-hosting`; customer sign-in = *Ask for e-mail & Support PIN, allow quick guest chats (recommended)*; allowed domains `ambitionhost.in`.
   - **Chat widget**: brand colour, welcome message ("Namaste! How can we help you today?"), attachments on, rating on.
   - **Install code**: copy the one-line `<script …>` code and paste it before `</body>` on every page of the website.
4. **Team & Departments**
   - Add Chandrika as an agent, tick only "AmbitionHost Hosting", department **Sales**.
   - Create departments **Support** and **Sales**. A department whose name contains "Sales" triggers the 30-working-minute sales alert.
   - Add saved replies, e.g. `/nameservers` → "Our nameservers are ns1… and ns2…".
5. **Knowledge Base**
   - **FAQs**: add the 10–20 most common questions (password reset, nameservers, refund policy…).
   - **Documents**: upload policies (PDF, DOCX, XLSX, CSV, TXT) or write articles → open each one → **Approve**. Drafts are never used.
   - **Test search**: type a customer question and check which approved text would be used.
6. **Notifications & Tasks → Telegram**: add a destination (bot token + chat ID), tick the events, press **Send test**.
7. **AI Settings** (optional): provider, model, API key, monthly and daily budget → switch on. Without AI, FAQs, tickets and hand-over still work.

---

## 5. The customer's journey (chat window)

### 5.1 First visit: e-mail and a new PIN

```
Assistant: Namaste! How can we help you today?
Assistant: Please share your e-mail address so we can keep your chats and tickets together.
Rahul:     rahul@example.com
Assistant: Set a 6-digit Support PIN. Next time, your e-mail + this PIN opens all your chats and tickets on any device.
           [ ][ ][ ][ ][ ][ ]   ← six boxes, numbers only
```

- No OTP is needed to create the PIN. Easy PINs (123456, 111111…) are refused.
- A new sign-up **never** gets access to someone else's history, and an existing account is **never** overwritten. If the e-mail already has a PIN, the chat asks for that PIN instead.
- On websites set to "allow quick guest chats", the customer can choose **Continue without e-mail**.

### 5.2 Asking a question

| Rahul asks | What happens |
|---|---|
| "How do I reset my client area password?" | Matches an FAQ → instant answer |
| "Is shared hosting refundable?" | AI answers from the approved *Refund policy* (if AI is on) |
| "My website shows a 500 error after installing a plugin" | No reliable answer → ticket `AMB-000001` is created, Rahul sees the reference, the team is alerted |
| "Talk to a human" (or the **Talk to a human** button) | Inside working hours → the real **60-second window** (see section 6.2.1): countdown for the customer, Telegram alert for your team. An agent who takes over in time stops it; otherwise one ticket is registered. Outside working hours nobody can answer within a minute, so no countdown is shown: the ticket is registered at once and the chat states the working hours. |
| "Are you a bot?" / "kya aap bot ho?" | A truthful answer that this is an automated assistant and a team member can join; it is not turned into a transfer. |
| "Please refund my payment" / "renew my domain" / "change my DNS" | The assistant **never** does these. A ticket is created for staff. |

The assistant replies in the customer's language: English, Hindi or Roman Hinglish.

### 5.3 Coming back (phone, another browser)

```
Rahul opens the chat on his phone → types rahul@example.com
Assistant: Welcome back! Enter your 6-digit Support PIN to continue where you left off.
           [4][8][2][9][1][6]
→ Rahul sees his earlier chat, the ticket AMB-000001 and the team's reply.
```

The **Support Tickets** tab lists all his tickets and their status. Internal staff notes are never visible to customers.

### 5.4 Forgotten PIN

| Option | Who | How |
|---|---|---|
| **Forgot PIN?** (self-service) | Customer | Chat → enter e-mail → **Forgot PIN?** → a 6-digit code is e-mailed (valid 10 minutes, single use, 5 tries) → set a new PIN. This also verifies the e-mail. |
| **Reset PIN** (by an admin) | Company owner / website admin | Dashboard → **Customers** → find the e-mail → **Reset PIN** → type a new 6-digit PIN → confirm you verified the person → **Save new PIN**. The customer is signed out on all devices and receives a security e-mail. |
| **Unlock** | Company owner / website admin | After 5 wrong PINs the account pauses for 15 min (then 1 h, then 24 h). **Customers → Unlock** removes the pause. |

Nobody, including admins, can see a customer's current PIN.

---

## 6. Daily work for the support team

### 6.1 Overview
Your start page: open chats, unresolved tickets, new leads, average first response time, automated answers and AI spend. Below it are the tickets waiting and your follow-up tasks.

### 6.2 Live Inbox
- Tabs: **Unassigned**, **Mine**, **All open**, **Closed**. Filter by website, search by name, e-mail or message.
- **Take over**: you handle the chat and the assistant stops replying.
- **Resume AI**: hand the chat back to the assistant (asks for confirmation if a ticket is open).
- **Reply to customer** / **Internal note** (notes are visible to staff only).
- **Transfer** to another department or colleague, **Create ticket**, **Close / Reopen**, **Attach file**, **Block** a visitor.
- **Mark as quotation** on a reply you sent with a price: Chandrika gets reminders at **+10 min, +30 min and +2 h** from the time of the quote. A customer reply cancels the remaining reminders.

Example: Rahul writes at 11:00 "What is the price of the Business plan?" in the Sales department. If nobody replies within **30 working minutes**, Aarav gets a Telegram alert (once). Chandrika replies with the price and clicks **Mark as quotation**.

#### 6.2.1 The 60-second senior-support window (exact behaviour)

1. The customer's question cannot be answered reliably (no matching FAQ, AI off or unsure), needs staff action (refund, DNS, renewal…) or the customer asks for a person. The automated replies stop.
2. The server stores `escalated_at` and `deadline_at = escalated_at + 60 s`, shows the customer the message and a countdown, and queues **one** Telegram alert (*"Senior support needed — customer is waiting … join before 12:01:00 PM"*).
3. The countdown is the server's remaining time. Refreshing the page, reconnecting, a second tab, pressing the button again or signing in never restarts it and never creates a second escalation.
4. **A team member clicks Take over before 00:00** → the countdown stops, the chat shows *"Alex from the support team has joined the conversation."* (their real name, only after they really joined), no ticket is created, the full history stays in the same chat.
5. **Nobody joins** → one ticket is registered automatically and the customer sees *"Thank you for waiting. Your support request has been registered successfully. Ticket ID: #AMB-000123 …"*. The ticket appears under **Tickets** in the chat (for signed-in customers) and in your dashboard, with company, website, department, priority, reason and the conversation.
6. **If the customer closed the browser**, the scheduler (`chatbox:escalations`, every minute) creates the ticket. With a one-minute cron the ticket can appear up to about 60 seconds after the 60-second deadline (about 2 minutes after the escalation at worst). While the chat is open the widget completes it within a few seconds.
7. A team member who takes over *after* the ticket exists continues in the same chat; the ticket is already linked to it. If the deadline passed but nothing had finalised it yet, taking over registers the ticket first (once) and then joins.

#### 6.2.2 Telegram reminders for tickets

From the moment a ticket starts waiting for the team (created, reopened, or the customer replied), a Telegram reminder is sent every **30 minutes** (10:00 created → 10:30, 11:00, 11:30, 12:00 …) with company, ticket, website, department, priority, status, how long it has been pending and a sign-in-protected link. Reminders stop at once when the ticket is **resolved or closed**; a reopened ticket starts a new 30-minute count. A ticket that is *waiting for the customer* is not repeated to the team. Each reminder is recorded once per ticket and period (no duplicates, with history).

### 6.3 Support Tickets
- Tabs: **Unresolved**, **Assigned to me**, **Unassigned**, **Resolved**, **Closed**.
- Each ticket shows a **summary** (issue, context, what is still open), the full conversation, owners and an activity timeline.
- The **first person who replies** becomes the owner. Previous and current owners can both see the ticket.
- Statuses: *Open* → *Waiting for team* → *Waiting for customer* → *Resolved* / *Closed*.
- Reading a ticket does **not** resolve it, and completing a task does **not** resolve it. Only the status does.
- If the customer writes again within 7 days of *Resolved*, the ticket reopens automatically. History is always kept.

### 6.4 Leads
- One lead per visitor, created automatically from chats (name, e-mail, phone, service such as hosting, domain or VPS, inquiry summary).
- Statuses: **New → Contacted → Interested → Follow-up → Converted / Closed**. Assign to staff, add internal notes, open linked chats.
- **Export to Excel / CSV** for the current filters or all leads. Exports are protected against spreadsheet formula injection and are logged.

### 6.5 Customers
All Support PIN customers per website: verification status, chats, tickets, last sign-in. Admins can **Reset PIN** and **Unlock** (see 5.4).

### 6.6 Notifications & Tasks
- **Bell** (top right): new tickets, hand-over requests, assignments, reminders.
- **My tasks**: quotation reminders and sales alerts. **Mark done** when handled.
- **Daily digest at 10:00 IST**: unresolved tickets and pending tasks.
- **Telegram** (admins): destinations, events and delivery history. Failed messages are retried up to 5 times.

---

## 7. Knowledge base and AI

| Item | Workflow |
|---|---|
| **FAQ** | Add question + answer per website → used immediately, no AI cost |
| **Article** | Write → **Save for review** → open → **Approve** |
| **File** | Upload (max 10 MB; scanned image PDFs cannot be read) → text is extracted in the background → review/correct the text → **Approve**. New version: open → **Upload new version** → approve again |
| **Archive** | Stops the assistant using a document. History is kept. |

**AI Settings** (owner/admin):
- Provider (Anthropic or OpenAI-compatible), model, effort, your own API key (stored encrypted, never shown again).
- **Monthly / daily budget**, **AI replies per customer per day**, **alert at 80 %**. When a limit is reached, only FAQs answer automatically and everything else goes to the team.
- **Guidance for the assistant**: tone and things it must never promise.

**What the assistant never does:** refunds, marking invoices paid, renewals, DNS changes, suspensions, password/credential resets, or inventing payment, refund or service status. It answers only from approved knowledge and hands everything else to a person.

---

## 8. Platform administration (super admin)

1. Sign in at `/app` with the super admin account → **Super Admin**.
2. **Companies**: see websites, staff, chats and AI spend per company → **Create company** (owner, first website, plan) → **Plan** (change plan or status) → **Suspend / Activate**.
3. **Plans**: limits for websites, staff, chats per month, knowledge documents and AI spend. Empty means unlimited.
4. **AI prices**: USD per 1 million tokens. A model without a price is never called, so spending is always controlled.

---

## 9. Automatic timings

| When | What |
|---|---|
| Immediately | FAQ answer; Telegram alert when a live chat is opened; Telegram alert + 60-second window when escalated |
| 60 seconds after an escalation (cron: within 0–60 s after the deadline; open chat: within a few seconds) | If no agent took over: one ticket is registered and the customer is told its number |
| 30, 60, 90 … minutes after a ticket starts waiting for the team | Telegram reminder per ticket (until resolved or closed) |
| 30 working minutes without a human reply in a Sales chat | Telegram sales alert (once) |
| +10 min, +30 min, +2 h after **Mark as quotation** | Reminders to the sales follow-up owner (cancelled by a customer reply) |
| Every day 10:00 IST | Digest of unresolved tickets and pending tasks |
| Every minute (cron) | Escalation deadlines (tickets), ticket reminders, AI replies that need more time, document processing, Telegram retries |

On cPanel all of this runs from **one cron job** (see `CPANEL_DEPLOYMENT.md`). On a local computer run `php artisan schedule:work` in a second window.

---

## 10. Troubleshooting

| Problem | Fix |
|---|---|
| Chat button does not appear on the website | Check **Websites & Widget → General → Allowed domains** and that the install code is on the page. The website must be active. |
| "The provided credentials do not match our records" | Wrong e-mail or password, or you are on a different server or database. Use **Forgot your password?** |
| Staff cannot sign in at `/{website}/admin` | They do not have access to that website. Give access in **Team & Departments**. |
| AI does not reply | **AI Settings** shows the reason: switched off, no API key, no price for the model, or budget reached. |
| Uploaded document stuck on "Processing" | The cron job (or `php artisan schedule:work` locally) is not running. |
| Customer forgot PIN and has no access to their e-mail | Admin verifies identity → **Customers → Reset PIN**. |
| Telegram alerts not arriving | **Notifications → Telegram → Send test** and check the delivery history for the error. |

---

## सारांश (हिंदी)

- **ग्राहक:** चैट में पहले ई-मेल डालता है। नया ई-मेल हो तो 6 बॉक्स में अपना 6 अंकों का Support PIN बनाता है (OTP नहीं चाहिए)। पुराना ई-मेल हो तो वही PIN डालकर किसी भी फ़ोन या ब्राउज़र पर अपनी पुरानी चैट और टिकट देख सकता है।
- **PIN भूल गए:** ग्राहक खुद "Forgot PIN?" से ई-मेल पर कोड लेकर नया PIN बना सकता है। या admin **Customers → Reset PIN** से नया PIN सेट कर सकता है (पहले पहचान पक्की करें)। PIN किसी को दिखता नहीं।
- **जवाब कैसे आता है:** चैट खुलते ही Telegram अलर्ट जाता है। FAQ हो तो तुरंत जवाब, नहीं तो approved knowledge से AI जवाब देता है। भरोसेमंद जवाब न हो या ग्राहक इंसान से बात करना चाहे, तो 60 सेकंड की सीनियर-सपोर्ट विंडो (काउंटडाउन के साथ) शुरू होती है। इस बीच कोई एजेंट Take over कर ले तो काउंटडाउन रुक जाता है; नहीं तो अपने आप एक टिकट (`AMB-000001`) बनता है। टिकट के बाद हर 30 मिनट पर Telegram रिमाइंडर जाता है, जब तक टिकट resolved/closed न हो।
- **टीम:** Live Inbox में जवाब, Take over / Resume AI, internal note, transfer। टिकट में पहला जवाब देने वाला owner बनता है। Leads Excel/CSV में export होते हैं।
- **Super admin:** सिर्फ़ Platform पेज (companies, plans, AI prices) देखता है। कंपनी के अंदर काम करने के लिए उस कंपनी के owner account से login करें।
- **Bot कभी नहीं करता:** refund, payment मार्क करना, renewal, DNS बदलना, suspend करना या password reset।
