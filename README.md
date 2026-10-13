# ChatBox — Enterprise Multi-Tenant SaaS Live Chat & CRM Platform

> Production-Grade Real-Time Live Support Platform with **Skeuomorphic UI** & **Visitor CRM Lead Tracker**.

---

## ⚡ Quick Start (Two Workspaces)

### 1. Start Laravel Backend (REST API & WebSockets)
```bash
# Terminal 1: Laravel Backend (Port 8000)
cd c:\laravel\chatbox
php artisan serve
```

### 2. Start Next.js Frontend (Skeuomorphic Dashboard Workspace)
```bash
# Terminal 2: Next.js Frontend (Port 3000)
cd c:\laravel\chatbox\frontend
npm run dev
```

- **Next.js Skeuomorphic Workspace**: Open 👉 **`http://localhost:3000`**
- **Client Website Demo with Live Widget**: Open 👉 **`http://localhost:8000/demo`**
- **Laravel Blade Alternative Dashboard**: Open 👉 **`http://localhost:8000/dashboard`**

---

## 🔑 Login Credentials

| Role | Email | Password | Access Level |
|---|---|---|---|
| **⚡ Super Admin (Master)** | `ambitionmanagements@gmail.com` | `password123` | **Full Platform Master**: Assign ChatBox to any company, edit any user's credentials (email, pass, role), view all tenants & stats |
| **👑 Tenant Admin (Company Owner)** | `admin@chatbox.com` | `password123` | Full company control, widget customizer, team agents, CRM Leads, analytics |
| **🎧 Support Agent** | `rahul@chatbox.com` | `password123` | Live chat workspace, visitor telemetry, canned responses (`/greet`, `/pricing`), CRM Leads |

*(1-Click quick login buttons are available directly on the login screen for instant access!)*

---

## 🔔 How Support & Admin Know Someone is Online or Messaged

When a visitor opens the chat on any client website:
1. **Real-time Audio Chime**: An instant synthesizer bell ping ("Ding!") plays on the dashboard whenever a message arrives.
2. **Glowing Online LED**: The visitor card displays a pulsing green beacon (`● Online Now`) with live IP, location, browser, and page URL.
3. **Incoming Toast Alert**: A skeuomorphic floating banner pops up at top-right: `"🔔 New Chat: [Visitor Name] sent a message!"`.
4. **Feed Badge & Live Queue**: The conversation appears at the top of the **"Unassigned"** feed with an orange new message indicator.

---

## 🎯 CRM Leads Hub (Form Submissions Tracking)

Every visitor who fills the pre-chat form (Name, Email, Phone, Initial inquiry) is automatically cataloged in the **CRM Leads Hub**:
- **Lead Profile**: Name, Email, Phone, City, Operating System, Initial Question, and Time.
- **Lifecycle Statuses**:
  - 🟡 `New`
  - 🔵 `Contacted`
  - 🟣 `Qualified`
  - 🟢 `Converted`
  - ⚪ `Lost`
- **Internal CRM Notes**: Agents can write follow-up summaries or deal value.
- **1-Click CSV Export**: Download captured leads directly into Excel/Google Sheets.

---

## 🏢 How Super Admin Assigns ChatBox to Any Company

1. Login as **Super Admin** (`ambitionmanagements@gmail.com`).
2. Click **"+ Assign ChatBox to Company"**.
3. Enter Company Name, Admin Email, Password, Website Name, and URL.
4. Click **"🚀 Create & Assign ChatBox"**.
5. The system generates a dedicated tenant workspace, company admin account, unique **Public Key** (`pk_live_...`), and 1-line script.

---

## 🌐 How to Add ChatBox to Any Website (HTML, WordPress, Shopify, Next.js)

Just add this 1-line embed script right before the closing `</body>` tag of any website:

```html
<!-- ChatBox Live Chat Widget -->
<script 
  src="http://localhost:8000/widget.js" 
  data-property-key="pk_live_chatbox_FiTEeVrRTkfRAuGWIhjD" 
  async>
</script>
```

### 1. Plain HTML / PHP
Paste directly above `</body>` in `index.html` or `footer.php`.

### 2. WordPress
Install **WPCode** or **Insert Headers and Footers** plugin -> Paste the snippet in the **Footer** section -> Save.

### 3. Shopify
Go to **Online Store > Themes > Edit Code > theme.liquid**, find `</body>` and paste right above it.

### 4. Next.js / React
In `app/layout.jsx`:
```jsx
import Script from 'next/script';

<Script 
  src="http://localhost:8000/widget.js" 
  data-property-key="pk_live_chatbox_FiTEeVrRTkfRAuGWIhjD" 
  strategy="lazyOnload" 
/>
```
