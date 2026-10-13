# Enterprise SaaS Live Chat Platform — Architecture & Implementation Roadmap

## 🏗️ 1. High-Level System Architecture
```mermaid
flowchart TD
    subgraph ClientSites["Client Websites (Any Tech Stack)"]
        SiteA["Client Website A (WordPress)"]
        SiteB["Client Website B (Shopify)"]
        SiteC["Aapki Website (Laravel / Custom)"]
    end
    subgraph WidgetLayer["Widget Engine (chat.yourdomain.com/widget.js)"]
        WJS["widget.js (Vanilla JS + Shadow DOM)"]
        WFrame["Chat Bubble / Floating Modal"]
    end
    subgraph Frontend["Dashboard Frontend (Next.js 14+ / Tailwind CSS)"]
        AgentInbox["Agent Live Inbox (Sound Alert, Real-time)"]
        ClientPortal["Client Settings & Widget Customizer"]
        SuperAdmin["Super Admin (Subscriptions, Global Stats)"]
    end
    subgraph Backend["API & Real-time Server (Laravel 11/12 + Laravel Reverb)"]
        API["RESTful API (Sanctum / JWT Auth)"]
        Sockets["Laravel Reverb (Ultra-fast WebSockets)"]
        Queue["Redis Queue (Email alerts, Webhooks, Push alerts)"]
        DB[(PostgreSQL / MySQL / SQLite)]
    end
    SiteA & SiteB & SiteC -->|Injects 1-Line Script| WJS
    WJS --> WFrame
    WFrame <-->|WebSockets (Instant Msg)| Sockets
    WFrame -->|HTTP (Init & Uploads)| API
    Frontend <-->|WebSockets (Presence / Typing)| Sockets
    Frontend <-->|REST API| API
    API --> DB
    Sockets --> DB
    API --> Queue
```

---

## 🔐 2. Enterprise-Grade Security Architecture
- **Strict Domain Whitelisting (Origin Protection)**: Allowed domains configured per property. Widget rejects unauthorized origins (403 Forbidden).
- **Shadow DOM Encapsulation**: Widget runs inside `attachShadow({mode: 'closed'})` to isolate CSS and DOM completely from client websites.
- **End-to-End Input Sanitization & Anti-XSS**: Strip HTML and sanitize payloads via HTML Purifier.
- **Rate Limiting & Anti-Spam (Throttle)**: Visitor IP throttling (e.g. 15 msgs/min) + one-click dashboard IP/Geo block.
- **Role-Based Access Control (RBAC)**:
  - `super_admin`: Platform-wide oversight, subscriptions, metrics.
  - `admin` (Tenant Owner): Tenant management, properties, agents, widget config.
  - `agent`: Assigned inboxes, chat handling, visitor telemetry.

---

## 🗄️ 3. Database Schema (Multi-Tenant Relational Design)

### 1. `tenants` (Companies / Business Accounts)
- `id` (UUID, Primary Key)
- `name` (Company Name)
- `email`, `password`
- `plan` (`free`, `pro`, `enterprise`)
- `status` (`active`, `suspended`)
- `timestamps`

### 2. `properties` / `inboxes` (Websites)
- `id` (UUID, Primary Key)
- `tenant_id` (UUID, Foreign Key)
- `site_name` ("SaluteHost", "MyStore")
- `site_url` ("https://salutehost.com")
- `public_key` (`pk_live_xxxx` — used in widget embed code)
- `secret_key` (`sk_live_xxxx` — used for server-to-server API calls)
- `allowed_domains` (JSON array: `["salutehost.com", "myfixdns.com"]`)
- `widget_config` (JSON: `primary_color`, `welcome_message`, `logo_url`, `position: left/right`)
- `timestamps`

### 3. `departments`
- `id` (UUID / BigInt, Primary Key)
- `property_id` (UUID, Foreign Key)
- `name` ("Billing", "Technical Support", "Sales")
- `is_active` (boolean)
- `timestamps`

### 4. `agents` / `users`
- `id` (UUID, Primary Key)
- `tenant_id` (UUID, Foreign Key)
- `name`, `email`, `password`
- `role` (`super_admin`, `admin`, `agent`)
- `avatar_url` (nullable)
- `is_online` (boolean)
- `last_active_at` (nullable timestamp)
- `timestamps`

### 5. `visitors` (Website Visitors)
- `id` (UUID, Primary Key)
- `property_id` (UUID, Foreign Key)
- `visitor_token` (Unique string stored in cookie/localStorage)
- `name`, `email`, `phone` (nullable, captured via pre-chat form)
- `ip_address`, `city`, `country_code`, `browser`, `os`, `current_page_url`
- `last_seen_at`
- `is_blocked` (boolean)
- `timestamps`

### 6. `conversations` (Chat Sessions)
- `id` (UUID, Primary Key)
- `property_id` (UUID, Foreign Key)
- `visitor_id` (UUID, Foreign Key)
- `assigned_agent_id` (UUID, Foreign Key, nullable)
- `department_id` (Foreign Key, nullable)
- `status` (`unassigned`, `open`, `pending`, `closed`)
- `last_message_at`
- `timestamps`

### 7. `messages` (Chat Messages)
- `id` (UUID, Primary Key)
- `conversation_id` (UUID, Foreign Key)
- `sender_type` (`visitor`, `agent`, `system`, `bot`)
- `sender_id` (UUID, nullable, agent_id or null for visitor)
- `message_type` (`text`, `image`, `file`, `action_button`)
- `content` (Text)
- `attachment_url` (nullable)
- `is_read` (boolean)
- `timestamps`

### 8. `canned_responses` (Saved Shortcuts)
- `id` (UUID, Primary Key)
- `property_id` (UUID, Foreign Key)
- `shortcut` (e.g. `/greet`, `/pricing`)
- `content` (Text)
- `timestamps`

---

## ⚡ 4. Real-time WebSocket Events (Laravel Reverb)
| Event Name | Channel | Description |
|---|---|---|
| `client-visitor-typing` | `private-chat.{conversationId}` | Visitor is typing (3 dots shown to agent) |
| `client-agent-typing` | `private-chat.{conversationId}` | Agent is typing ("Agent is typing..." shown to visitor) |
| `message.sent` | `private-chat.{conversationId}` | Instant message delivered to both screens |
| `conversation.created` | `presence-inbox.{propertyId}` | New visitor conversation -> Agent audio alert |
| `agent.status.changed` | `presence-inbox.{propertyId}` | Realtime agent online/offline status |

---

## 📋 5. Step-by-Step Implementation Roadmap

### Phase 1: Foundation & Database
- [x] Create ROADMAP.md
- [x] Multi-tenant Database Migrations (UUID primary keys & foreign keys)
- [x] Eloquent Models with UUID traits, relationships, and scopes
- [x] Laravel Sanctum / RESTful API setup
- [x] Multi-tenant Auth & Property / API Key Generation Engine

### Phase 2: Real-time Backend Engine
- [x] Laravel Reverb & Broadcasting Setup
- [x] Chat API Endpoints (Widget Init, Message Send, History, Canned Responses)
- [x] WebSocket Channels & Events (`MessageSent`, `ConversationCreated`, etc.)

### Phase 3: The Embeddable Widget
- [x] Standalone `widget.js` (Vanilla JS + Closed Shadow DOM)
- [x] Pre-chat Form, Audio Alerts, Live Chat Bubble UI
- [x] Domain Origin Whitelisting verification

### Phase 4: Modern Agent Dashboard (Next.js / Inertia / Blade / SPA)
- [x] 3-Column Linear/Crisp-style live chat workspace
- [x] Real-time Audio Ping, visitor telemetry sidebar, canned response slash commands

### Phase 5: Client Portal & Widget Customizer
- [x] Color theme picker, logo upload, live preview
- [x] 1-Click embed script generator
- [x] Team members & departments management

### Phase 6: Hardening, Security & Production Polish
- [x] Rate limiting & anti-spam throttles
- [x] Anti-XSS HTML Sanitizer
4. Typing Indicators Optimization
WebSocket par client-visitor-typing events bohot zyada traffic generate karte hain. Client-side par hamesha "Debounce" (e.g., 500ms) lagayein, taaki har keystroke par socket event fire na ho, balki words ke beech mein ho.

5. Data Pruning (Archiving)
Kuch mahino baad messages table bohot badi ho jayegi. Roadmap ke Phase 6 mein ek cron job add karein jo 1 saal purani chats ko sasti storage (jaise S3 ya cold database) mein archive kar de taaki main database ki query speed maintain rahe.