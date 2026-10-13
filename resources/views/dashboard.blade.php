<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ChatBox — Enterprise Live Chat Workspace</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg-dark: #f8fafc;
      --bg-surface: #ffffff;
      --bg-feed: #f1f5f9;
      --bg-card: #ffffff;
      --bg-card-hover: #f8fafc;
      --border-color: #e2e8f0;
      --border-focus: #2563eb;
      --primary: #2563eb;
      --primary-hover: #1d4ed8;
      --primary-light: #eff6ff;
      --primary-border: #bfdbfe;
      --orange: #f97316;
      --orange-hover: #ea580c;
      --orange-light: #fff7ed;
      --text-main: #0f172a;
      --text-muted: #475569;
      --text-dim: #94a3b8;
      --success: #10b981;
      --warning: #f59e0b;
      --danger: #ef4444;
      --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
      --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
      --shadow-lg: 0 10px 25px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -4px rgba(0, 0, 0, 0.04);
      --shadow-modal: 0 25px 50px -12px rgba(15, 23, 42, 0.18);
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: 'Plus Jakarta Sans', -apple-system, sans-serif;
    }

    body {
      background: var(--bg-dark);
      color: var(--text-main);
      height: 100vh;
      overflow: hidden;
      display: flex;
    }

    /* Auth Overlay Modal */
    #auth-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.6);
      backdrop-filter: blur(12px);
      z-index: 9999;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .auth-card {
      background: #ffffff;
      border: 1px solid var(--border-color);
      border-radius: 20px;
      padding: 36px;
      width: 440px;
      max-width: 90vw;
      box-shadow: var(--shadow-modal);
    }
    .auth-brand {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 24px;
    }
    .auth-logo {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      background: linear-gradient(135deg, #2563eb, #1d4ed8);
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      font-weight: 800;
      box-shadow: 0 6px 16px rgba(37, 99, 235, 0.25);
    }
    .auth-title {
      font-size: 22px;
      font-weight: 700;
      color: #0f172a;
      letter-spacing: -0.5px;
    }
    .auth-subtitle {
      font-size: 13px;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .demo-chips {
      margin-top: 20px;
      padding-top: 20px;
      border-top: 1px solid var(--border-color);
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .demo-chip-title {
      font-size: 11px;
      text-transform: uppercase;
      font-weight: 700;
      letter-spacing: 0.5px;
      color: var(--text-dim);
    }
    .demo-chip-btn {
      background: #f8fafc;
      border: 1px solid var(--border-color);
      border-radius: 10px;
      padding: 11px 14px;
      font-size: 13px;
      color: #334155;
      cursor: pointer;
      text-align: left;
      transition: all 0.2s;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .demo-chip-btn:hover {
      background: #eff6ff;
      border-color: #93c5fd;
      color: #1d4ed8;
      transform: translateY(-1px);
      box-shadow: 0 2px 8px rgba(37, 99, 235, 0.12);
    }

    .form-group {
      margin-bottom: 16px;
    }
    .form-group label {
      display: block;
      font-size: 12px;
      font-weight: 600;
      margin-bottom: 6px;
      color: #334155;
    }
    .form-input {
      width: 100%;
      background: #ffffff;
      border: 1px solid #cbd5e1;
      border-radius: 10px;
      padding: 10px 14px;
      color: #0f172a;
      font-size: 14px;
      outline: none;
      transition: all 0.2s;
    }
    .form-input:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.18);
    }
    .btn-primary {
      width: 100%;
      background: linear-gradient(135deg, #2563eb, #1d4ed8);
      border: none;
      color: #ffffff;
      font-weight: 600;
      padding: 12px;
      border-radius: 10px;
      cursor: pointer;
      font-size: 14px;
      transition: all 0.2s;
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
    }
    .btn-primary:hover {
      opacity: 0.95;
      box-shadow: 0 6px 18px rgba(37, 99, 235, 0.35);
      transform: translateY(-1px);
    }
    .btn-primary:active {
      transform: scale(0.98);
    }
    .auth-error {
      background: #fee2e2;
      border: 1px solid #fca5a5;
      color: #b91c1c;
      padding: 10px 14px;
      border-radius: 8px;
      font-size: 13px;
      margin-bottom: 14px;
      display: none;
    }

    /* Layout Columns */
    #app-container {
      display: flex;
      width: 100vw;
      height: 100vh;
      overflow: hidden;
      background: var(--bg-dark);
    }

    /* Column 1: Left Navigation Rail */
    .nav-rail {
      width: 250px;
      background: #ffffff;
      border-right: 1px solid var(--border-color);
      display: flex;
      flex-direction: column;
      flex-shrink: 0;
      box-shadow: 1px 0 3px rgba(0, 0, 0, 0.02);
    }
    .rail-header {
      padding: 20px;
      display: flex;
      align-items: center;
      gap: 12px;
      border-bottom: 1px solid var(--border-color);
    }
    .rail-logo {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      background: linear-gradient(135deg, #2563eb, #f97316);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: 18px;
      color: #ffffff;
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
    }
    .rail-title {
      font-size: 16px;
      font-weight: 700;
      color: #0f172a;
      letter-spacing: -0.3px;
    }
    .rail-menu {
      padding: 16px 12px;
      flex: 1;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .rail-section-title {
      font-size: 11px;
      font-weight: 700;
      color: var(--text-dim);
      text-transform: uppercase;
      letter-spacing: 0.6px;
      padding: 12px 10px 6px;
    }
    .rail-btn {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 12px;
      border-radius: 10px;
      color: #475569;
      cursor: pointer;
      text-decoration: none;
      font-size: 13.5px;
      font-weight: 500;
      transition: all 0.15s;
    }
    .rail-btn-left {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .rail-btn:hover {
      background: #f1f5f9;
      color: #0f172a;
    }
    .rail-btn.active {
      background: #eff6ff;
      color: #2563eb;
      font-weight: 600;
      box-shadow: inset 3px 0 0 #2563eb;
    }
    .rail-badge {
      background: #e2e8f0;
      color: #475569;
      font-size: 11px;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 12px;
    }
    .rail-badge.unread {
      background: #ef4444;
      color: #fff;
    }

    .rail-footer {
      padding: 16px;
      border-top: 1px solid var(--border-color);
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #f8fafc;
    }
    .user-profile {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .user-avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: #dbeafe;
      color: #1d4ed8;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 13px;
      position: relative;
    }
    .user-online-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: #10b981;
      border: 2px solid #ffffff;
      position: absolute;
      bottom: -1px;
      right: -1px;
    }
    .user-name {
      font-size: 13px;
      font-weight: 600;
      color: #0f172a;
      line-height: 1.2;
    }
    .user-role {
      font-size: 11px;
      color: var(--text-muted);
      text-transform: capitalize;
    }
    .btn-logout {
      background: #ffffff;
      border: 1px solid var(--border-color);
      color: #64748b;
      cursor: pointer;
      padding: 7px;
      border-radius: 8px;
      transition: all 0.15s;
    }
    .btn-logout:hover {
      color: #ef4444;
      border-color: #fca5a5;
      background: #fee2e2;
    }

    /* Column 2: Conversation Feed */
    .conv-feed {
      width: 320px;
      background: #ffffff;
      border-right: 1px solid var(--border-color);
      display: flex;
      flex-direction: column;
      flex-shrink: 0;
    }
    .feed-header {
      padding: 16px 16px 12px;
      border-bottom: 1px solid var(--border-color);
      background: #ffffff;
    }
    .feed-search {
      width: 100%;
      background: #f8fafc;
      border: 1px solid var(--border-color);
      border-radius: 10px;
      padding: 9px 12px;
      color: #0f172a;
      font-size: 13px;
      outline: none;
      transition: all 0.2s;
    }
    .feed-search:focus {
      background: #ffffff;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    .feed-tabs {
      display: flex;
      gap: 6px;
      margin-top: 10px;
    }
    .feed-tab {
      background: transparent;
      border: none;
      color: #64748b;
      font-size: 12px;
      font-weight: 600;
      padding: 6px 12px;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.15s;
    }
    .feed-tab:hover {
      color: #0f172a;
      background: #f1f5f9;
    }
    .feed-tab.active {
      background: #eff6ff;
      color: #2563eb;
      font-weight: 700;
    }
    .conv-list {
      flex: 1;
      overflow-y: auto;
      padding: 8px;
      display: flex;
      flex-direction: column;
      gap: 6px;
      background: #f8fafc;
    }
    .conv-card {
      background: #ffffff;
      border-radius: 12px;
      padding: 12px 14px;
      cursor: pointer;
      transition: all 0.15s;
      border: 1px solid var(--border-color);
      box-shadow: var(--shadow-sm);
      position: relative;
    }
    .conv-card:hover {
      border-color: #cbd5e1;
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
    }
    .conv-card.active {
      background: #eff6ff;
      border-color: #93c5fd;
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.12);
    }
    .conv-card-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 4px;
    }
    .conv-visitor-name {
      font-size: 13.5px;
      font-weight: 600;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .conv-time {
      font-size: 11px;
      color: var(--text-dim);
    }
    .conv-snippet {
      font-size: 12.5px;
      color: #475569;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      margin-top: 2px;
    }
    .conv-card-bottom {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-top: 8px;
    }
    .conv-tag {
      font-size: 10.5px;
      padding: 3px 8px;
      border-radius: 6px;
      background: #f1f5f9;
      color: #475569;
      font-weight: 600;
    }

    /* Column 3: Main Chat Workspace */
    .chat-workspace {
      flex: 1;
      background: #ffffff;
      display: flex;
      flex-direction: column;
      position: relative;
    }
    .chat-header {
      padding: 16px 24px;
      border-bottom: 1px solid var(--border-color);
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #ffffff;
      box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    }
    .chat-header-user {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .chat-header-avatar {
      width: 42px;
      height: 42px;
      border-radius: 12px;
      background: linear-gradient(135deg, #2563eb, #3b82f6);
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 15px;
      box-shadow: 0 4px 10px rgba(37, 99, 235, 0.2);
    }
    .chat-header-info h2 {
      font-size: 16px;
      font-weight: 700;
      color: #0f172a;
      letter-spacing: -0.3px;
    }
    .chat-header-info p {
      font-size: 12px;
      color: var(--text-muted);
      margin-top: 2px;
    }
    .chat-header-actions {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .btn-action {
      background: #ffffff;
      border: 1px solid var(--border-color);
      color: #334155;
      font-size: 12px;
      font-weight: 600;
      padding: 8px 14px;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.15s;
    }
    .btn-action:hover {
      background: #f8fafc;
      border-color: #cbd5e1;
      color: #0f172a;
    }
    .btn-action.success {
      background: #ecfdf5;
      border-color: #a7f3d0;
      color: #047857;
    }
    .btn-action.danger {
      background: #fef2f2;
      border-color: #fecaca;
      color: #b91c1c;
    }

    /* Messages Stream */
    .chat-stream {
      flex: 1;
      overflow-y: auto;
      padding: 24px;
      display: flex;
      flex-direction: column;
      gap: 14px;
      background: #f8fafc;
    }
    .stream-bubble-group {
      display: flex;
      flex-direction: column;
      max-width: 70%;
    }
    .stream-bubble-group.visitor {
      align-self: flex-start;
      align-items: flex-start;
    }
    .stream-bubble-group.agent {
      align-self: flex-end;
      align-items: flex-end;
    }
    .stream-bubble {
      padding: 12px 16px;
      border-radius: 16px;
      font-size: 14px;
      line-height: 1.5;
      word-break: break-word;
      box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    }
    .stream-bubble-group.visitor .stream-bubble {
      background: #ffffff;
      color: #0f172a;
      border: 1px solid var(--border-color);
      border-bottom-left-radius: 4px;
    }
    .stream-bubble-group.agent .stream-bubble {
      background: linear-gradient(135deg, #2563eb, #1d4ed8);
      color: #ffffff;
      border-bottom-right-radius: 4px;
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
    }
    .stream-meta {
      font-size: 11px;
      color: var(--text-dim);
      margin-top: 4px;
      padding: 0 4px;
    }

    /* Chat Footer & Slash Commands */
    .chat-input-wrapper {
      padding: 16px 24px 20px;
      border-top: 1px solid var(--border-color);
      background: #ffffff;
      position: relative;
    }
    .slash-popup {
      position: absolute;
      bottom: 100%;
      left: 24px;
      right: 24px;
      background: #ffffff;
      border: 1px solid var(--border-color);
      border-radius: 12px;
      padding: 8px;
      margin-bottom: 8px;
      box-shadow: var(--shadow-lg);
      display: none;
      flex-direction: column;
      gap: 4px;
      max-height: 200px;
      overflow-y: auto;
    }
    .slash-item {
      padding: 8px 12px;
      border-radius: 8px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-size: 13px;
    }
    .slash-item:hover, .slash-item.selected {
      background: #eff6ff;
    }
    .slash-shortcut {
      color: #2563eb;
      font-weight: 700;
      font-family: 'JetBrains Mono', monospace;
    }
    .slash-text {
      color: var(--text-muted);
      font-size: 12px;
    }

    .chat-box {
      background: #f8fafc;
      border: 1px solid var(--border-color);
      border-radius: 12px;
      padding: 10px 14px;
      display: flex;
      align-items: center;
      gap: 12px;
      transition: all 0.2s;
    }
    .chat-box:focus-within {
      background: #ffffff;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    }
    .chat-textarea {
      flex: 1;
      background: transparent;
      border: none;
      color: #0f172a;
      font-size: 14px;
      outline: none;
      resize: none;
      max-height: 100px;
    }
    .btn-send {
      background: linear-gradient(135deg, #2563eb, #1d4ed8);
      border: none;
      color: #fff;
      width: 38px;
      height: 38px;
      border-radius: 10px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.15s;
      box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
    }
    .btn-send:hover {
      transform: scale(1.05);
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
    }

    /* Column 4: Visitor Intel Sidebar */
    .intel-sidebar {
      width: 290px;
      background: #ffffff;
      border-left: 1px solid var(--border-color);
      padding: 20px;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 20px;
      flex-shrink: 0;
    }
    .intel-card {
      background: #f8fafc;
      border: 1px solid var(--border-color);
      border-radius: 12px;
      padding: 16px;
    }
    .intel-card h4 {
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--text-muted);
      margin-bottom: 12px;
    }
    .intel-item {
      display: flex;
      justify-content: space-between;
      font-size: 12.5px;
      padding: 7px 0;
      border-bottom: 1px solid #edf2f7;
    }
    .intel-item:last-child {
      border-bottom: none;
    }
    .intel-key {
      color: var(--text-muted);
    }
    .intel-val {
      color: #0f172a;
      font-weight: 600;
      text-align: right;
      max-width: 140px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    /* Empty state */
    .empty-state {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: var(--text-dim);
      gap: 12px;
    }
    .empty-icon {
      font-size: 44px;
      opacity: 0.6;
    }

    /* Other Views (Customizer, Analytics, Platform) */
    .full-view-panel {
      flex: 1;
      padding: 32px 40px;
      overflow-y: auto;
      display: none;
      background: #f8fafc;
    }
    .full-view-header {
      margin-bottom: 28px;
    }
    .full-view-header h2 {
      font-size: 24px;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: -0.5px;
    }
    .full-view-header p {
      font-size: 14px;
      color: var(--text-muted);
      margin-top: 4px;
    }

    /* Stats Grid */
    .stats-grid, .stats-overview-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 20px;
      margin-bottom: 30px;
    }
    .stat-card {
      background: #ffffff;
      border: 1px solid var(--border-color);
      border-radius: 14px;
      padding: 20px;
      box-shadow: var(--shadow-sm);
    }
    .stat-label {
      font-size: 12px;
      color: var(--text-muted);
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .stat-val {
      font-size: 32px;
      font-weight: 800;
      color: #0f172a;
      margin-top: 6px;
    }

    /* Customizer Grid */
    .customizer-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 30px;
    }
    .snippet-box {
      background: #0f172a;
      border: 1px solid #1e293b;
      border-radius: 10px;
      padding: 16px;
      font-family: 'JetBrains Mono', monospace;
      font-size: 12.5px;
      color: #93c5fd;
      position: relative;
      white-space: pre-wrap;
    }

    /* Platform Table & Role Badges */
    .badge-plan {
      background: #eff6ff;
      color: #2563eb;
      border: 1px solid #bfdbfe;
      padding: 3px 8px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
    }
    .badge-status-active {
      background: #ecfdf5;
      color: #059669;
      border: 1px solid #a7f3d0;
      padding: 3px 8px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 700;
    }
  </style>
</head>
<body>

  <!-- ========================================== -->
  <!-- 1. AUTH OVERLAY (LOGIN & FORGOT PASSWORD) -->
  <!-- ========================================== -->
  <div id="auth-overlay">
    <!-- Sign In Card -->
    <div class="auth-card" id="login-card">
      <div class="auth-brand">
        <div class="auth-logo">⚡</div>
        <div>
          <div class="auth-title">ChatBox Workspace</div>
          <div class="auth-subtitle">Sign in to your live support dashboard</div>
        </div>
      </div>

      <div class="auth-error" id="auth-error-msg"></div>

      <form id="login-form" onsubmit="event.preventDefault(); submitLogin();">
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" class="form-input" id="login-email" required placeholder="ambitionmanagements@gmail.com" value="ambitionmanagements@gmail.com" />
        </div>
        <div class="form-group">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
            <label style="margin:0;">Password</label>
            <a href="javascript:void(0)" onclick="toggleForgotPass(true)" style="font-size:12px; color:var(--primary); font-weight:600; text-decoration:none;">Forgot Password?</a>
          </div>
          <input type="password" class="form-input" id="login-password" required placeholder="••••••••" value="password123" />
        </div>
        <button type="button" class="btn-primary" id="login-submit-btn" onclick="submitLogin()">Sign In to Workspace</button>
      </form>

      <div class="demo-chips">
        <div class="demo-chip-title">1-Click Demo Accounts (Instant Sign In)</div>
        <button type="button" class="demo-chip-btn" onclick="quickLogin('ambitionmanagements@gmail.com', 'password123')">
          <span>⚡ <strong>Super Admin</strong> (Platform Owner)</span>
          <span style="font-size:11px; opacity:0.8; font-weight:600; color:var(--primary)">ambitionmanagements@gmail.com</span>
        </button>
        <button type="button" class="demo-chip-btn" onclick="quickLogin('admin@chatbox.com', 'password123')">
          <span>👑 <strong>ChatBox Owner</strong> (Company Admin)</span>
          <span style="font-size:11px; opacity:0.8">admin@chatbox.com</span>
        </button>
        <button type="button" class="demo-chip-btn" onclick="quickLogin('rahul@chatbox.com', 'password123')">
          <span>🎧 <strong>Support Agent Rahul</strong> (Agent)</span>
          <span style="font-size:11px; opacity:0.8">rahul@chatbox.com</span>
        </button>
      </div>
    </div>

    <!-- Forgot Password Card -->
    <div class="auth-card" id="forgot-card" style="display:none;">
      <div class="auth-brand">
        <div class="auth-logo" style="background:linear-gradient(135deg, #f97316, #ea580c);">🔑</div>
        <div>
          <div class="auth-title">Reset Password</div>
          <div class="auth-subtitle">Set a new password for your account</div>
        </div>
      </div>

      <div class="auth-error" id="forgot-error-msg"></div>
      <div id="forgot-success-msg" style="display:none; background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:14px;"></div>

      <form onsubmit="event.preventDefault(); submitForgotPassword();">
        <div class="form-group">
          <label>Registered Email Address</label>
          <input type="email" class="form-input" id="forgot-email" required placeholder="your-email@example.com" />
        </div>
        <div class="form-group">
          <label>New Password (min 6 characters)</label>
          <input type="password" class="form-input" id="forgot-new-password" required placeholder="Enter new password" />
        </div>
        <button type="submit" class="btn-primary" id="forgot-submit-btn" style="background:linear-gradient(135deg, #f97316, #ea580c);">Reset & Update Password</button>
      </form>

      <div style="margin-top:16px; text-align:center;">
        <a href="javascript:void(0)" onclick="toggleForgotPass(false)" style="font-size:13px; color:var(--text-muted); text-decoration:none; font-weight:600;">← Back to Sign In</a>
      </div>
    </div>
  </div>

  <!-- ========================================== -->
  <!-- 2. MAIN APPLICATION WORKSPACE -->
  <!-- ========================================== -->
  <div id="app-container">

    <!-- Column 1: Navigation Rail -->
    <aside class="nav-rail">
      <div class="rail-header">
        <div class="rail-logo">⚡</div>
        <div>
          <div class="rail-title" id="rail-company-name">ChatBox</div>
          <div style="font-size:11px; color:var(--text-dim)" id="rail-property-name">Live Workspace</div>
        </div>
      </div>

      <nav class="rail-menu">
        <!-- Super Admin Platform Section -->
        <div id="rail-super-admin-section" style="display:none">
          <div class="rail-section-title">SaaS Master</div>
          <a class="rail-btn" id="tab-platform" onclick="switchMainView('platform')">
            <div class="rail-btn-left">⚡ Platform Control</div>
          </a>
        </div>

        <div class="rail-section-title" id="rail-conv-title">Conversations</div>
        <a class="rail-btn active" id="tab-inbox" onclick="switchMainView('inbox')">
          <div class="rail-btn-left">💬 Live Inbox</div>
          <span class="rail-badge unread" id="inbox-unread-badge" style="display:none">0</span>
        </a>

        <!-- Admin Management Section (Hidden for frontline Agents) -->
        <div id="rail-management-section">
          <div class="rail-section-title">Workspace Settings</div>
          <a class="rail-btn" id="tab-customizer" onclick="switchMainView('customizer')">
            <div class="rail-btn-left">🎨 Widget & Embed</div>
          </a>
          <a class="rail-btn" id="tab-agents" onclick="switchMainView('agents')">
            <div class="rail-btn-left">👥 Support Team</div>
            <span class="rail-badge" id="team-count-badge">2</span>
          </a>
          <a class="rail-btn" id="tab-faqs" onclick="switchMainView('faqs')">
            <div class="rail-btn-left">📚 Knowledge Base & FAQs</div>
            <span class="rail-badge" id="faq-count-badge" style="background:#8b5cf6; color:#ffffff;">0</span>
          </a>
        </div>

        <div class="rail-section-title">Reports</div>
        <a class="rail-btn" id="tab-analytics" onclick="switchMainView('analytics')">
          <div class="rail-btn-left">📊 Analytics & KPIs</div>
        </a>

        <div class="rail-section-title" id="rail-quicklinks-title">Quick Links</div>
        <a href="/demo" target="_blank" class="rail-btn" id="rail-demo-link">
          <div class="rail-btn-left">🚀 Open Client Website</div>
          <span>↗</span>
        </a>
      </nav>

      <div class="rail-footer">
        <div class="user-profile" onclick="openProfileModal()" style="cursor:pointer;" title="Click to view & edit your profile">
          <div class="user-avatar" id="current-user-avatar">
            AD
            <div class="user-online-dot"></div>
          </div>
          <div>
            <div class="user-name" id="current-user-name">Admin</div>
            <div class="user-role" id="current-user-role">Tenant Admin</div>
          </div>
        </div>
        <div style="display:flex; gap:4px; align-items:center;">
          <button class="btn-logout" title="My Profile & Password" onclick="openProfileModal()" style="font-size:13px;">👤</button>
          <button class="btn-logout" title="Sign Out" onclick="logout()">⏻</button>
        </div>
      </div>
    </aside>

    <!-- Column 2: Conversations Feed -->
    <section class="conv-feed" id="conv-feed-panel">
      <div class="feed-header">
        <input type="text" class="feed-search" id="feed-search-input" placeholder="Search visitors or text..." oninput="handleSearch()" />
        <div class="feed-tabs">
          <button class="feed-tab active" id="filter-unassigned" onclick="setFilter('unassigned')">Unassigned</button>
          <button class="feed-tab" id="filter-mine" onclick="setFilter('mine')">Mine</button>
          <button class="feed-tab" id="filter-all" onclick="setFilter('all')">All</button>
          <button class="feed-tab" id="filter-closed" onclick="setFilter('closed')">Closed</button>
        </div>
      </div>

      <div class="conv-list" id="conv-list-container"></div>
    </section>

    <!-- Column 3: Active Chat Workspace -->
    <main class="chat-workspace" id="chat-workspace-panel">
      <div id="no-chat-selected" class="empty-state">
        <div class="empty-icon">💬</div>
        <h3>Select a conversation</h3>
        <p style="font-size:13px; max-width:300px; text-align:center">Choose a live conversation from the left feed or wait for incoming visitor messages.</p>
      </div>

      <div id="active-chat-container" style="display:none; flex:1; flex-direction:column; height:100%">
        <div class="chat-header">
          <div class="chat-header-user">
            <div class="chat-header-avatar" id="active-visitor-avatar">AK</div>
            <div class="chat-header-info">
              <h2 id="active-visitor-name">Amit Kumar</h2>
              <p id="active-visitor-page">Browsing /pricing • Mumbai, IN</p>
            </div>
          </div>
          <div class="chat-header-actions">
            <button class="btn-action success" id="assign-me-btn" onclick="assignToMe()">Assign to Me</button>
            <button class="btn-action danger" id="close-chat-btn" onclick="toggleCloseChat()">Close Ticket</button>
          </div>
        </div>

        <div class="chat-stream" id="chat-stream-container"></div>

        <div class="chat-input-wrapper">
          <div class="slash-popup" id="slash-popup"></div>

          <div class="chat-box">
            <textarea class="chat-textarea" id="agent-input" rows="1" placeholder="Type a message or '/' for canned responses..." oninput="handleInput(event)" onkeydown="handleInputKey(event)"></textarea>
            <button class="btn-send" onclick="sendAgentMessage()">➤</button>
          </div>
        </div>
      </div>
    </main>

    <!-- Column 4: Visitor Intel Sidebar -->
    <aside class="intel-sidebar" id="intel-sidebar-panel" style="display:none">
      <div class="intel-card">
        <h4>Visitor Telemetry</h4>
        <div class="intel-item"><span class="intel-key">Status</span><span class="intel-val" id="intel-status">Open</span></div>
        <div class="intel-item"><span class="intel-key">Location</span><span class="intel-val" id="intel-location">Mumbai, IN</span></div>
        <div class="intel-item"><span class="intel-key">IP Address</span><span class="intel-val" id="intel-ip">127.0.0.1</span></div>
        <div class="intel-item"><span class="intel-key">Browser</span><span class="intel-val" id="intel-browser">Chrome</span></div>
        <div class="intel-item"><span class="intel-key">OS</span><span class="intel-val" id="intel-os">Windows</span></div>
        <div class="intel-item"><span class="intel-key">Current Page</span><span class="intel-val" id="intel-page">/pricing</span></div>
      </div>

      <div class="intel-card">
        <h4>Assigned Agent</h4>
        <div class="intel-item"><span class="intel-key">Agent</span><span class="intel-val" id="intel-agent">Unassigned</span></div>
        <div class="intel-item"><span class="intel-key">Department</span><span class="intel-val" id="intel-dept">Sales</span></div>
      </div>

      <div class="intel-card">
        <h4>Canned Shortcuts</h4>
        <p style="font-size:11.5px; color:var(--text-dim); margin-bottom:8px">Click to insert into reply:</p>
        <div id="intel-canned-list" style="display:flex; flex-direction:column; gap:6px;"></div>
      </div>
    </aside>

    <!-- Full View Panel: Customizer -->
    <div class="full-view-panel" id="customizer-panel">
      <div class="full-view-header">
        <h2>Widget Customizer & Embed Code</h2>
        <p>Customize your live chat appearance and copy the 1-line script for your websites.</p>
      </div>

      <div class="customizer-grid">
        <div>
          <div class="intel-card" style="margin-bottom:20px;">
            <h4>Design & Branding</h4>
            <div class="form-group">
              <label>Theme Color</label>
              <input type="color" id="cust-color" value="#2563eb" style="width:100%; height:40px; border-radius:8px; border:none; cursor:pointer;" />
            </div>
            <div class="form-group">
              <label>Welcome Message</label>
              <input type="text" class="form-input" id="cust-welcome" value="Namaste! How can we help you today?" />
            </div>
            <button class="btn-primary" onclick="saveWidgetConfig()">Save Changes</button>
          </div>

          <div class="intel-card">
            <h4>1-Click Embed Snippet</h4>
            <p style="font-size:12px; color:var(--text-muted); margin-bottom:12px">Paste this snippet before the &lt;/body&gt; tag on WordPress, Shopify, or custom websites:</p>
            <div class="snippet-box" id="embed-snippet-code">&lt;!-- JixChat --&gt;</div>
            <button class="btn-primary" style="margin-top:12px" onclick="copySnippet()">Copy Embed Code</button>
          </div>
        </div>

        <div>
          <div class="intel-card">
            <h4>Departments</h4>
            <div id="departments-list" style="display:flex; flex-direction:column; gap:8px; margin-bottom:16px;"></div>
            <div style="display:flex; gap:8px;">
              <input type="text" class="form-input" id="new-dept-name" placeholder="New Department (e.g. Enterprise Sales)" />
              <button class="btn-action success" onclick="createDepartment()">Add</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Full View Panel: Analytics -->
    <div class="full-view-panel" id="analytics-panel">
      <div class="full-view-header">
        <h2>Real-Time Platform Analytics</h2>
        <p>Key metrics and performance KPIs across all tenant inboxes.</p>
      </div>

      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-label">Active Chats</div>
          <div class="stat-val" id="stat-open">0</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Unassigned Queue</div>
          <div class="stat-val" id="stat-unassigned" style="color:var(--warning)">0</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Resolved Tickets</div>
          <div class="stat-val" id="stat-closed" style="color:var(--success)">0</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Total Visitors</div>
          <div class="stat-val" id="stat-visitors" style="color:#818cf8">0</div>
        </div>
      </div>
    </div>

    <!-- Full View Panel: Support Team -->
    <div class="full-view-panel" id="agents-panel">
      <div class="full-view-header">
        <h2>Support Team Members</h2>
        <p>Manage human agents and department assignments.</p>
      </div>

      <div class="intel-card" style="max-width:600px; margin-bottom:24px;">
        <h4>Invite New Support Agent</h4>
        <div class="form-group">
          <label>Full Name</label>
          <input type="text" class="form-input" id="new-agent-name" placeholder="Pooja Sharma" />
        </div>
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" class="form-input" id="new-agent-email" placeholder="pooja@chatbox.com" />
        </div>
        <div class="form-group">
          <label>Temporary Password</label>
          <input type="password" class="form-input" id="new-agent-password" placeholder="agentPass123" />
        </div>
        <button class="btn-primary" onclick="createAgent()">Add Team Member</button>
      </div>

      <div class="intel-card" style="max-width:600px;">
        <h4>Active Agents</h4>
        <div id="agents-list" style="display:flex; flex-direction:column; gap:10px;"></div>
      </div>
    </div>

    <!-- Full View Panel: SaaS Platform Control (Super Admin) -->
    <div class="full-view-panel" id="platform-panel" style="display:none">
      <div class="full-view-header" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
        <div>
          <h2>⚡ SaaS Platform Control Center</h2>
          <p>Monitor multi-tenant metrics, manage client organizations, user credentials, and assign chatboxes.</p>
        </div>
        <div style="display:flex; gap:10px;">
          <button class="btn-action" style="padding:10px 18px; font-size:13px; color:#d97706; border-color:#fcd34d;" onclick="triggerFlushCache()">⚡ Flush Cache</button>
          <button class="btn-primary" style="padding:10px 18px; font-size:13px; width:auto;" onclick="openCreateTenantModal()">+ Assign ChatBox to Company</button>
          <button class="btn-action" style="padding:10px 18px; font-size:13px;" onclick="openEmbedGuideModal()">📖 How to Add to Websites</button>
        </div>
      </div>

      <div class="stats-overview-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 24px;">
        <div class="stat-card">
          <div class="stat-label">Total Tenants</div>
          <div class="stat-val" id="platform-stat-tenants" style="color:var(--primary)">-</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Active Client Sites</div>
          <div class="stat-val" id="platform-stat-sites" style="color:var(--success)">-</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Global Support Agents</div>
          <div class="stat-val" id="platform-stat-agents" style="color:#818cf8">-</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Processed Messages</div>
          <div class="stat-val" id="platform-stat-messages" style="color:var(--warning)">-</div>
        </div>
      </div>

      <!-- Tenants Table -->
      <div class="intel-card" style="width:100%; margin-bottom:28px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
          <div>
            <h4 style="margin-bottom:4px; font-size:14px;">🏢 Registered Tenant Organizations</h4>
            <div style="font-size:12px; color:var(--text-muted)">Companies hosted on your ChatBox SaaS platform</div>
          </div>
          <button class="btn-action" style="padding:6px 14px; font-size:12px;" onclick="loadPlatformData()">↻ Refresh</button>
        </div>

        <div style="overflow-x:auto;">
          <table style="width:100%; border-collapse:collapse; font-size:13px; text-align:left;">
            <thead>
              <tr style="border-bottom:1px solid var(--border-color); color:var(--text-muted); text-transform:uppercase; font-size:11px; letter-spacing:0.5px;">
                <th style="padding:10px 14px;">Organization</th>
                <th style="padding:10px 14px;">Contact Email</th>
                <th style="padding:10px 14px;">Plan</th>
                <th style="padding:10px 14px;">Websites</th>
                <th style="padding:10px 14px;">Agents</th>
                <th style="padding:10px 14px;">Status</th>
                <th style="padding:10px 14px; text-align:right;">Actions</th>
              </tr>
            </thead>
            <tbody id="platform-tenants-tbody">
              <tr><td colspan="7" style="padding:20px; text-align:center; color:var(--text-dim)">Loading platform tenants...</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Users & Credentials Management Table -->
      <div class="intel-card" style="width:100%; margin-bottom:28px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
          <div>
            <h4 style="margin-bottom:4px; font-size:14px;">👥 Platform Users & Access Control (Super Admin Master)</h4>
            <div style="font-size:12px; color:var(--text-muted)">Edit any user's email, password, and assign roles</div>
          </div>
          <button class="btn-action" style="padding:6px 14px; font-size:12px;" onclick="loadPlatformUsers()">↻ Refresh Users</button>
        </div>

        <div style="overflow-x:auto;">
          <table style="width:100%; border-collapse:collapse; font-size:13px; text-align:left;">
            <thead>
              <tr style="border-bottom:1px solid var(--border-color); color:var(--text-muted); text-transform:uppercase; font-size:11px; letter-spacing:0.5px;">
                <th style="padding:10px 14px;">User Name</th>
                <th style="padding:10px 14px;">Email Address</th>
                <th style="padding:10px 14px;">Role</th>
                <th style="padding:10px 14px;">Company (Tenant)</th>
                <th style="padding:10px 14px;">Online Status</th>
                <th style="padding:10px 14px; text-align:right;">Actions</th>
              </tr>
            </thead>
            <tbody id="platform-users-tbody">
              <tr><td colspan="6" style="padding:20px; text-align:center; color:var(--text-dim)">Loading platform users...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Full View Panel: 📚 Knowledge Base & FAQs -->
    <div class="full-view-panel" id="faqs-panel" style="display:none">
      <div class="full-view-header" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
        <div>
          <h2>📚 Company Knowledge Base &amp; FAQs</h2>
          <p>Manage self-service FAQs for website visitors and 1-click answers for live chat support agents.</p>
        </div>
        <div style="display:flex; gap:10px;">
          <button class="btn-action" style="padding:10px 18px; font-size:13px;" onclick="loadBladeFaqs()">↻ Refresh FAQs</button>
          <button class="btn-primary" style="padding:10px 18px; font-size:13px; width:auto;" onclick="openAddFaqModal()">+ Add Company FAQ</button>
        </div>
      </div>

      <!-- FAQ Self-Service & Agent Fallback Explanation Card -->
      <div class="intel-card" style="width:100%; margin-bottom:24px; background:linear-gradient(145deg, #f0fdf4, #dcfce7); border:1px solid #86efac;">
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
          <span style="font-size:20px;">💡</span>
          <strong style="font-size:14px; color:#166534;">Where FAQs Appear &amp; How They Work:</strong>
        </div>
        <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:14px; font-size:12.5px; color:#14532d;">
          <div style="background:rgba(255,255,255,0.7); padding:10px 12px; border-radius:8px;">
            <strong>1. Website Visitor Widget:</strong> Visitors see these FAQs on your website before starting a chat, getting instant answers.
          </div>
          <div style="background:rgba(255,255,255,0.7); padding:10px 12px; border-radius:8px;">
            <strong>2. Live Chat Fallback:</strong> If an answer doesn't resolve their query, visitors click &ldquo;Need more help? Start chat&rdquo; to transfer to an agent.
          </div>
          <div style="background:rgba(255,255,255,0.7); padding:10px 12px; border-radius:8px;">
            <strong>3. Support Agent 1-Click:</strong> When an agent doesn't know an answer, they can pick from these verified FAQs in the live chat reply box.
          </div>
        </div>
      </div>

      <!-- FAQs Search & List -->
      <div class="intel-card" style="width:100%;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
          <input type="text" id="blade-faq-search" placeholder="Search FAQs by question, category, or answer keywords..." class="feed-search" style="max-width:380px; margin:0;" oninput="renderBladeFaqs()" />
          <div style="font-size:12px; color:var(--text-muted);" id="blade-faq-count-text">0 FAQs total</div>
        </div>

        <div id="blade-faqs-container" style="display:flex; flex-direction:column; gap:12px;">
          <div style="text-align:center; padding:30px; color:var(--text-dim);">Loading FAQs...</div>
        </div>
      </div>
    </div>

    <!-- Modal 1: Onboard / Assign ChatBox to Company -->
    <div id="modal-create-tenant" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(8px); z-index:99999; align-items:center; justify-content:center;">
      <div class="auth-card" style="width:520px; max-width:92vw;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
          <h3 style="font-size:18px; font-weight:700; color:#0f172a;">🏢 Assign ChatBox to Company</h3>
          <button onclick="closeCreateTenantModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b;">✕</button>
        </div>
        <p style="font-size:13px; color:var(--text-muted); margin-bottom:16px;">Create a new client company workspace and generate their live chat embed script instantly.</p>

        <form onsubmit="event.preventDefault(); submitCreateTenant();">
          <div class="form-group">
            <label>Company / Business Name *</label>
            <input type="text" class="form-input" id="new-comp-name" required placeholder="e.g. Acme Fashion / Apex Hospital" />
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <div class="form-group">
              <label>Admin Owner Name *</label>
              <input type="text" class="form-input" id="new-comp-admin-name" required placeholder="Owner name" />
            </div>
            <div class="form-group">
              <label>Admin Email *</label>
              <input type="email" class="form-input" id="new-comp-admin-email" required placeholder="owner@company.com" />
            </div>
          </div>
          <div class="form-group">
            <label>Initial Password *</label>
            <input type="password" class="form-input" id="new-comp-admin-pass" required placeholder="Set password (min 6 chars)" />
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <div class="form-group">
              <label>Website Name *</label>
              <input type="text" class="form-input" id="new-comp-site-name" required placeholder="e.g. Official Store" />
            </div>
            <div class="form-group">
              <label>Website URL *</label>
              <input type="text" class="form-input" id="new-comp-site-url" required placeholder="https://example.com" />
            </div>
          </div>
          <div class="form-group">
            <label>SaaS Plan</label>
            <select class="form-input" id="new-comp-plan">
              <option value="pro" selected>Pro Plan (Unlimited Chats)</option>
              <option value="enterprise">Enterprise Plan</option>
              <option value="free">Free Starter Plan</option>
            </select>
          </div>
          <button type="submit" class="btn-primary" style="margin-top:10px;">Create Workspace & Generate Key</button>
        </form>
      </div>
    </div>

    <!-- Modal 2: Edit User Credentials (Super Admin) -->
    <div id="modal-edit-user" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(8px); z-index:99999; align-items:center; justify-content:center;">
      <div class="auth-card" style="width:460px; max-width:92vw;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
          <h3 style="font-size:18px; font-weight:700; color:#0f172a;">✏️ Edit User Credentials</h3>
          <button onclick="closeEditUserModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b;">✕</button>
        </div>

        <input type="hidden" id="edit-user-id" />
        <form onsubmit="event.preventDefault(); submitUpdateUser();">
          <div class="form-group">
            <label>Full Name</label>
            <input type="text" class="form-input" id="edit-user-name" required />
          </div>
          <div class="form-group">
            <label>Email Address</label>
            <input type="email" class="form-input" id="edit-user-email" required />
          </div>
          <div class="form-group">
            <label>Role</label>
            <select class="form-input" id="edit-user-role">
              <option value="super_admin">⚡ Platform Super Admin</option>
              <option value="admin">👑 Tenant Admin (Company Owner)</option>
              <option value="agent">🎧 Support Agent</option>
            </select>
          </div>
          <div class="form-group">
            <label>New Password (Leave blank to keep current password)</label>
            <input type="password" class="form-input" id="edit-user-password" placeholder="Enter new password (optional)" />
          </div>
          <button type="submit" class="btn-primary" style="margin-top:10px;">Save User Updates</button>
        </form>
      </div>
    </div>

    <!-- Modal 3: Website Integration Guide -->
    <div id="modal-embed-guide" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(8px); z-index:99999; align-items:center; justify-content:center;">
      <div class="auth-card" style="width:680px; max-width:94vw; max-height:85vh; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
          <h3 style="font-size:20px; font-weight:800; color:#0f172a;">📖 How to Add ChatBox to Any Website</h3>
          <button onclick="closeEmbedGuideModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b;">✕</button>
        </div>

        <div style="font-size:13.5px; color:var(--text-muted); line-height:1.6; display:flex; flex-direction:column; gap:18px;">
          <div>
            <h4 style="color:#0f172a; margin-bottom:6px;">1. Standard HTML / PHP / Laravel Websites</h4>
            <p>Copy this 1-line script and paste it right before the closing <code>&lt;/body&gt;</code> tag of your website:</p>
            <div class="snippet-box" style="margin-top:8px;">&lt;!-- ChatBox Live Support --&gt;
&lt;script&gt;
  window.JixChatConfig = {
    propertyKey: "YOUR_PROPERTY_KEY",
    apiUrl: window.location.origin
  };
&lt;/script&gt;
&lt;script src="/widget.js" async&gt;&lt;/script&gt;</div>
          </div>

          <div>
            <h4 style="color:#0f172a; margin-bottom:6px;">2. WordPress Websites</h4>
            <p>1. WordPress Admin Dashboard &gt; <strong>Plugins &gt; Add New</strong> &gt; Install <strong>"WPCode"</strong> or <strong>"Insert Headers and Footers"</strong>.<br>
            2. Go to <strong>Code Snippets &gt; Header &amp; Footer</strong> &gt; Paste the script in the <strong>Footer</strong> section and click <strong>Save Changes</strong>.</p>
          </div>

          <div>
            <h4 style="color:#0f172a; margin-bottom:6px;">3. Shopify Stores</h4>
            <p>1. Shopify Admin &gt; <strong>Online Store &gt; Themes</strong> &gt; Click <strong>Actions &gt; Edit Code</strong>.<br>
            2. Open <code>theme.liquid</code>, scroll down to the bottom and paste the script right before <code>&lt;/body&gt;</code>, then click <strong>Save</strong>.</p>
          </div>

          <div>
            <h4 style="color:#0f172a; margin-bottom:6px;">4. React / Next.js</h4>
            <p>In your <code>index.html</code> or <code>app/layout.tsx</code>, load <code>widget.js</code> with script config on window.</p>
          </div>
        </div>
    <!-- Modal 4: Add Company FAQ -->
    <div id="modal-add-faq" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(8px); z-index:99999; align-items:center; justify-content:center;">
      <div class="auth-card" style="width:520px; max-width:92vw;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
          <h3 style="font-size:18px; font-weight:700; color:#0f172a;">➕ Add Company FAQ</h3>
          <button onclick="closeAddFaqModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b;">✕</button>
        </div>
        <p style="font-size:13px; color:var(--text-muted); margin-bottom:16px;">This FAQ will be visible to website visitors on the chat widget, and accessible to live chat agents for 1-click answers.</p>

        <form onsubmit="event.preventDefault(); submitAddFaq();">
          <div class="form-group">
            <label>Question *</label>
            <input type="text" class="form-input" id="new-faq-question" required placeholder="e.g. How do I track my order?" />
          </div>
          <div class="form-group">
            <label>Answer *</label>
            <textarea class="form-input" id="new-faq-answer" required rows="3" placeholder="Provide clear, concise answer..." style="resize:vertical;"></textarea>
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <div class="form-group">
              <label>Category</label>
              <input type="text" class="form-input" id="new-faq-category" placeholder="e.g. Orders, Billing, Support" value="General" />
            </div>
            <div class="form-group">
              <label>Display Order</label>
              <input type="number" class="form-input" id="new-faq-order" value="1" min="1" />
            </div>
          </div>
          <button type="submit" class="btn-primary" style="margin-top:10px;">Publish FAQ</button>
        </form>
      </div>
    </div>

    <!-- Modal 5: User Profile & Credentials -->
    <div id="modal-profile" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(8px); z-index:99999; align-items:center; justify-content:center;">
      <div class="auth-card" style="width:460px; max-width:92vw;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
          <h3 style="font-size:18px; font-weight:700; color:#0f172a;">👤 My Profile &amp; Credentials</h3>
          <button onclick="closeProfileModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b;">✕</button>
        </div>

        <form onsubmit="event.preventDefault(); submitUpdateProfile();">
          <div class="form-group">
            <label>Full Name *</label>
            <input type="text" class="form-input" id="profile-name" required />
          </div>
          <div class="form-group">
            <label>Email Address *</label>
            <input type="email" class="form-input" id="profile-email" required />
          </div>
          <div class="form-group">
            <label>New Password (Leave blank to keep existing password)</label>
            <input type="password" class="form-input" id="profile-password" placeholder="Enter new password (min 6 chars, optional)" />
          </div>
          <button type="submit" class="btn-primary" style="margin-top:10px;">Update My Profile</button>
        </form>
      </div>
    </div>

  </div>

  <script>
    const API_BASE = '/api/v1';
    let AUTH_TOKEN = localStorage.getItem('jix_token') || null;
    let CURRENT_USER = null;
    let CURRENT_PROPERTY = null;
    let CONVERSATIONS = [];
    let ACTIVE_CONV = null;
    let CURRENT_FILTER = 'unassigned';
    let CANNED_RESPONSES = [];

    function quickLogin(email, pass) {
      const emailInput = document.getElementById('login-email');
      const passInput = document.getElementById('login-password');
      if (emailInput) emailInput.value = email;
      if (passInput) passInput.value = pass;
      submitLogin();
    }
    window.quickLogin = quickLogin;

    async function submitLogin() {
      const emailEl = document.getElementById('login-email');
      const passEl = document.getElementById('login-password');
      const email = emailEl ? emailEl.value.trim() : '';
      const pass = passEl ? passEl.value : '';
      const btn = document.getElementById('login-submit-btn');
      const errorEl = document.getElementById('auth-error-msg');
      if (!email || !pass) {
        if (errorEl) {
          errorEl.textContent = 'Please enter both email and password.';
          errorEl.style.display = 'block';
        }
        return;
      }

      errorEl.style.display = 'none';
      btn.disabled = true;
      btn.textContent = 'Signing in...';

      try {
        const res = await fetch(`${API_BASE}/auth/login`, {
          method: 'POST',
          headers: { 
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({ email: email, password: pass })
        });

        const json = await res.json();
        if (json.success && json.data && json.data.token) {
          AUTH_TOKEN = json.data.token;
          localStorage.setItem('jix_token', AUTH_TOKEN);
          document.getElementById('auth-overlay').style.display = 'none';
          await initDashboard();
        } else {
          errorEl.textContent = json.message || 'Login failed. Please check credentials.';
          errorEl.style.display = 'block';
        }
      } catch (err) {
        errorEl.textContent = 'Server connection error. Please make sure php artisan serve is running.';
        errorEl.style.display = 'block';
      } finally {
        btn.disabled = false;
        btn.textContent = 'Sign In to Workspace';
      }
    }
    window.submitLogin = submitLogin;

    function logout() {
      if (AUTH_TOKEN) {
        fetch(`${API_BASE}/auth/logout`, {
          method: 'POST',
          headers: { 
            'Authorization': `Bearer ${AUTH_TOKEN}`,
            'Accept': 'application/json'
          }
        }).catch(() => {});
      }
      AUTH_TOKEN = null;
      localStorage.removeItem('jix_token');
      document.getElementById('auth-overlay').style.display = 'flex';
    }

    async function initDashboard() {
      if (!AUTH_TOKEN) {
        document.getElementById('auth-overlay').style.display = 'flex';
        return;
      }

      try {
        const res = await fetch(`${API_BASE}/auth/me`, {
          headers: { 
            'Authorization': `Bearer ${AUTH_TOKEN}`,
            'Accept': 'application/json'
          }
        });

        if (res.status === 401) {
          logout();
          return;
        }

        const json = await res.json();
        CURRENT_USER = json.data.user;
        const properties = json.data.properties || [];
        CURRENT_PROPERTY = properties[0] || null;

        document.getElementById('auth-overlay').style.display = 'none';
        document.getElementById('current-user-name').textContent = CURRENT_USER.name;
        document.getElementById('current-user-avatar').textContent = (CURRENT_USER.name || 'US').substring(0, 2).toUpperCase();

        // Role-based UI adaptation
        const isSuperAdmin = CURRENT_USER.role === 'super_admin';
        const isAdmin = CURRENT_USER.role === 'admin';
        const isAgent = CURRENT_USER.role === 'agent';

        // 1. Role Badges
        const roleLabel = isSuperAdmin ? 'Platform Super Admin' : (isAdmin ? 'Tenant Admin (Owner)' : 'Support Agent');
        document.getElementById('current-user-role').textContent = roleLabel;

        // 2. Navigation Rail adaptation
        const saSection = document.getElementById('rail-super-admin-section');
        if (saSection) saSection.style.display = isSuperAdmin ? 'block' : 'none';

        const mgmtSection = document.getElementById('rail-management-section');
        if (mgmtSection) mgmtSection.style.display = (isAdmin || isSuperAdmin) ? 'block' : 'none';

        const demoLink = document.getElementById('rail-demo-link');
        const qlTitle = document.getElementById('rail-quicklinks-title');
        if (demoLink) demoLink.style.display = (isAdmin || isSuperAdmin) ? 'flex' : 'none';
        if (qlTitle) qlTitle.style.display = (isAdmin || isSuperAdmin) ? 'block' : 'none';

        // 3. Header & Initial View
        if (isSuperAdmin) {
          document.getElementById('rail-company-name').textContent = 'ChatBox SaaS';
          document.getElementById('rail-property-name').textContent = 'Platform Master Control';
          switchMainView('platform');
          loadPlatformData();
        } else {
          if (CURRENT_USER.tenant) {
            document.getElementById('rail-company-name').textContent = CURRENT_USER.tenant.name;
          }
          if (CURRENT_PROPERTY) {
            document.getElementById('rail-property-name').textContent = CURRENT_PROPERTY.site_name;
            updateEmbedSnippet();
          } else {
            document.getElementById('rail-property-name').textContent = 'Live Inboxes';
          }
          switchMainView('inbox');
        }

        loadCannedResponses();
        loadConversations();
        loadStats();

        if (!window.__dashboardPollers) {
          window.__dashboardPollers = true;
          setInterval(loadConversations, 3000);
          setInterval(loadStats, 10000);
        }
      } catch(e) {
        console.error('Init dashboard error:', e);
      }
    }

    function switchMainView(view) {
      document.querySelectorAll('.rail-btn').forEach(b => b.classList.remove('active'));
      const activeBtn = document.getElementById(`tab-${view}`);
      if (activeBtn) activeBtn.classList.add('active');

      const isInbox = view === 'inbox';
      document.getElementById('conv-feed-panel').style.display = isInbox ? 'flex' : 'none';
      document.getElementById('chat-workspace-panel').style.display = isInbox ? 'flex' : 'none';
      document.getElementById('intel-sidebar-panel').style.display = isInbox && ACTIVE_CONV ? 'flex' : 'none';

      document.getElementById('customizer-panel').style.display = view === 'customizer' ? 'block' : 'none';
      document.getElementById('analytics-panel').style.display = view === 'analytics' ? 'block' : 'none';
      document.getElementById('agents-panel').style.display = view === 'agents' ? 'block' : 'none';
      if (document.getElementById('platform-panel')) {
        document.getElementById('platform-panel').style.display = view === 'platform' ? 'block' : 'none';
      }
      if (document.getElementById('faqs-panel')) {
        document.getElementById('faqs-panel').style.display = view === 'faqs' ? 'block' : 'none';
      }

      if (view === 'platform') loadPlatformData();
      if (view === 'agents') loadAgents();
      if (view === 'customizer') loadCustomizer();
      if (view === 'faqs') loadBladeFaqs();
    }

    function setFilter(filter) {
      CURRENT_FILTER = filter;
      document.querySelectorAll('.feed-tab').forEach(t => t.classList.remove('active'));
      const tab = document.getElementById(`filter-${filter}`);
      if (tab) tab.classList.add('active');
      loadConversations();
    }

    async function loadConversations() {
      if (!AUTH_TOKEN) return;
      try {
        const res = await fetch(`${API_BASE}/chat/conversations?filter=${CURRENT_FILTER}`, {
          headers: { 
            'Authorization': `Bearer ${AUTH_TOKEN}`,
            'Accept': 'application/json'
          }
        });
        const json = await res.json();
        if (json.success) {
          const prevCount = CONVERSATIONS.length;
          CONVERSATIONS = json.data.data || [];

          if (CONVERSATIONS.length > prevCount && CURRENT_FILTER === 'unassigned') {
            playAudioPing();
          }

          renderConversationList();

          if (ACTIVE_CONV) {
            const updated = CONVERSATIONS.find(c => c.id === ACTIVE_CONV.id);
            if (updated) {
              loadActiveConversation(ACTIVE_CONV.id, false);
            }
          }
        }
      } catch(e) {}
    }

    function handleSearch() {
      const q = document.getElementById('feed-search-input').value.toLowerCase();
      const cards = document.querySelectorAll('.conv-card');
      cards.forEach(c => {
        const text = c.textContent.toLowerCase();
        c.style.display = text.includes(q) ? 'block' : 'none';
      });
    }

    function renderConversationList() {
      const listEl = document.getElementById('conv-list-container');
      listEl.innerHTML = '';

      let totalUnread = 0;

      CONVERSATIONS.forEach(conv => {
        if (conv.unread_count > 0) totalUnread += conv.unread_count;

        const card = document.createElement('div');
        card.className = `conv-card ${ACTIVE_CONV && ACTIVE_CONV.id === conv.id ? 'active' : ''}`;
        card.onclick = () => loadActiveConversation(conv.id, true);

        const vName = conv.visitor?.name || 'Visitor';
        const vLoc = conv.visitor?.city ? `${conv.visitor.city}, ${conv.visitor.country_code}` : 'Online';
        const snippet = conv.latest_message ? conv.latest_message.content : 'No messages yet';
        const time = conv.last_message_at ? new Date(conv.last_message_at).toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' }) : '';

        card.innerHTML = `
          <div class="conv-card-top">
            <div class="conv-visitor-name">
              <span>👤</span> ${vName}
              ${conv.unread_count > 0 ? `<span class="rail-badge unread">${conv.unread_count}</span>` : ''}
            </div>
            <div class="conv-time">${time}</div>
          </div>
          <div class="conv-snippet">${snippet}</div>
          <div class="conv-card-bottom">
            <span class="conv-tag">${conv.department?.name || 'General'}</span>
            <span style="font-size:11px; color:var(--text-dim)">${vLoc}</span>
          </div>
        `;

        listEl.appendChild(card);
      });

      const badge = document.getElementById('inbox-unread-badge');
      badge.textContent = totalUnread;
      badge.style.display = totalUnread > 0 ? 'inline-block' : 'none';
    }

    async function loadActiveConversation(convId, shouldScroll = true) {
      if (!AUTH_TOKEN) return;
      try {
        const res = await fetch(`${API_BASE}/chat/conversations/${convId}`, {
          headers: { 
            'Authorization': `Bearer ${AUTH_TOKEN}`,
            'Accept': 'application/json'
          }
        });
        const json = await res.json();
        if (json.success) {
          ACTIVE_CONV = json.data;

          document.getElementById('no-chat-selected').style.display = 'none';
          document.getElementById('active-chat-container').style.display = 'flex';
          document.getElementById('intel-sidebar-panel').style.display = 'flex';

          const v = ACTIVE_CONV.visitor || {};
          document.getElementById('active-visitor-name').textContent = v.name || 'Visitor';
          document.getElementById('active-visitor-avatar').textContent = (v.name || 'V').substring(0, 2).toUpperCase();
          document.getElementById('active-visitor-page').textContent = `Browsing ${v.current_page_url || '/'} • ${v.city || 'Online'}, ${v.country_code || ''}`;

          document.getElementById('intel-status').textContent = (ACTIVE_CONV.status || 'open').toUpperCase();
          document.getElementById('intel-location').textContent = `${v.city || 'Unknown'}, ${v.country_code || ''}`;
          document.getElementById('intel-ip').textContent = v.ip_address || '127.0.0.1';
          document.getElementById('intel-browser').textContent = v.browser || 'Browser';
          document.getElementById('intel-os').textContent = v.os || 'OS';
          document.getElementById('intel-page').textContent = v.current_page_url || '/';
          document.getElementById('intel-agent').textContent = ACTIVE_CONV.assigned_agent?.name || 'Unassigned';
          document.getElementById('intel-dept').textContent = ACTIVE_CONV.department?.name || 'Support';

          const closeBtn = document.getElementById('close-chat-btn');
          closeBtn.textContent = ACTIVE_CONV.status === 'closed' ? 'Reopen Ticket' : 'Close Ticket';

          renderMessages(ACTIVE_CONV.messages || [], shouldScroll);

          fetch(`${API_BASE}/chat/conversations/${convId}/read`, {
            method: 'POST',
            headers: { 
              'Authorization': `Bearer ${AUTH_TOKEN}`,
              'Accept': 'application/json'
            }
          }).catch(() => {});
        }
      } catch(e) {}
    }

    function renderMessages(messages, shouldScroll) {
      const stream = document.getElementById('chat-stream-container');
      stream.innerHTML = '';

      messages.forEach(msg => {
        const isAgent = msg.sender_type === 'agent';
        const group = document.createElement('div');
        group.className = `stream-bubble-group ${isAgent ? 'agent' : 'visitor'}`;

        const time = new Date(msg.created_at).toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' });
        const sender = isAgent ? (msg.sender?.name || 'Agent') : (ACTIVE_CONV.visitor?.name || 'Visitor');

        group.innerHTML = `
          <div class="stream-bubble">${msg.content}</div>
          <div class="stream-meta">${sender} • ${time}</div>
        `;
        stream.appendChild(group);
      });

      if (shouldScroll) {
        stream.scrollTop = stream.scrollHeight;
      }
    }

    async function sendAgentMessage() {
      const input = document.getElementById('agent-input');
      const text = input.value.trim();
      if (!text || !ACTIVE_CONV) return;

      input.value = '';

      try {
        const res = await fetch(`${API_BASE}/chat/conversations/${ACTIVE_CONV.id}/messages`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${AUTH_TOKEN}`
          },
          body: JSON.stringify({ content: text })
        });
        const json = await res.json();
        if (json.success) {
          ACTIVE_CONV.messages.push(json.data);
          renderMessages(ACTIVE_CONV.messages, true);
        }
      } catch(e) {
        console.error(e);
      }
    }

    function handleInputKey(e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendAgentMessage();
      }
    }

    function handleInput(e) {
      const val = e.target.value;
      const popup = document.getElementById('slash-popup');

      if (val.startsWith('/')) {
        const query = val.toLowerCase();
        const matches = CANNED_RESPONSES.filter(c => c.shortcut.toLowerCase().includes(query));

        if (matches.length > 0) {
          popup.innerHTML = matches.map(m => `
            <div class="slash-item" onclick="insertCanned('${m.content.replace(/'/g, "\\'")}')">
              <span class="slash-shortcut">${m.shortcut}</span>
              <span class="slash-text">${m.content}</span>
            </div>
          `).join('');
          popup.style.display = 'flex';
          return;
        }
      }
      popup.style.display = 'none';
    }

    function insertCanned(content) {
      const input = document.getElementById('agent-input');
      input.value = content;
      document.getElementById('slash-popup').style.display = 'none';
      input.focus();
    }

    async function assignToMe() {
      if (!ACTIVE_CONV) return;
      await fetch(`${API_BASE}/chat/conversations/${ACTIVE_CONV.id}/assign`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'Authorization': `Bearer ${AUTH_TOKEN}`
        },
        body: JSON.stringify({ agent_id: CURRENT_USER.id })
      });
      loadActiveConversation(ACTIVE_CONV.id, false);
      loadConversations();
    }

    async function toggleCloseChat() {
      if (!ACTIVE_CONV) return;
      const targetStatus = ACTIVE_CONV.status === 'closed' ? 'open' : 'closed';
      await fetch(`${API_BASE}/chat/conversations/${ACTIVE_CONV.id}/status`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'Authorization': `Bearer ${AUTH_TOKEN}`
        },
        body: JSON.stringify({ status: targetStatus })
      });
      loadActiveConversation(ACTIVE_CONV.id, false);
      loadConversations();
      loadStats();
    }

    async function loadCannedResponses() {
      try {
        const res = await fetch(`${API_BASE}/chat/canned-responses`, {
          headers: { 
            'Authorization': `Bearer ${AUTH_TOKEN}`,
            'Accept': 'application/json'
          }
        });
        const json = await res.json();
        if (json.success) {
          CANNED_RESPONSES = json.data || [];
          const list = document.getElementById('intel-canned-list');
          list.innerHTML = CANNED_RESPONSES.map(c => `
            <button class="demo-chip-btn" onclick="insertCanned('${c.content.replace(/'/g, "\\'")}')">
              <span><strong>${c.shortcut}</strong></span>
              <span style="font-size:11px; opacity:0.6">${c.content.substring(0, 20)}...</span>
            </button>
          `).join('');
        }
      } catch(e) {}
    }

    async function loadStats() {
      if (!AUTH_TOKEN) return;
      try {
        const res = await fetch(`${API_BASE}/chat/stats`, {
          headers: { 
            'Authorization': `Bearer ${AUTH_TOKEN}`,
            'Accept': 'application/json'
          }
        });
        const json = await res.json();
        if (json.success) {
          const d = json.data;
          document.getElementById('stat-open').textContent = d.open_conversations;
          document.getElementById('stat-unassigned').textContent = d.unassigned_conversations;
          document.getElementById('stat-closed').textContent = d.closed_conversations;
          document.getElementById('stat-visitors').textContent = d.total_visitors;
        }
      } catch(e) {}
    }

    function updateEmbedSnippet() {
      if (!CURRENT_PROPERTY) return;
      const origin = window.location.origin;
      const sTag = '<' + 'script';
      const sClose = '<' + '/script>';
      const code = '<!-- JixChat Live Support -->\n' +
        sTag + '>\n' +
        '  window.JixChatConfig = {\n' +
        '    propertyKey: "' + CURRENT_PROPERTY.public_key + '",\n' +
        '    apiUrl: "' + origin + '"\n' +
        '  };\n' +
        sClose + '\n' +
        sTag + ' src="' + origin + '/widget.js" async>' + sClose + '\n' +
        '<!-- End JixChat -->';
      document.getElementById('embed-snippet-code').textContent = code;
    }

    function copySnippet() {
      const code = document.getElementById('embed-snippet-code').textContent;
      navigator.clipboard.writeText(code);
      alert('Embed code copied to clipboard!');
    }

    async function loadCustomizer() {
      if (!CURRENT_PROPERTY) return;
      document.getElementById('cust-color').value = CURRENT_PROPERTY.widget_config?.primary_color || '#2563eb';
      document.getElementById('cust-welcome').value = CURRENT_PROPERTY.widget_config?.welcome_message || '';
      renderDepartments();
    }

    function renderDepartments() {
      const depts = CURRENT_PROPERTY?.departments || [];
      const list = document.getElementById('departments-list');
      list.innerHTML = depts.map(d => `
        <div style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; border:1px solid var(--border-color); padding:10px 14px; border-radius:10px;">
          <span style="font-weight:600; color:#0f172a;">🏷️ ${d.name}</span>
          <span class="conv-tag" style="background:${d.is_active ? '#ecfdf5' : '#f1f5f9'}; color:${d.is_active ? '#059669' : '#64748b'};">${d.is_active ? 'Active' : 'Disabled'}</span>
        </div>
      `).join('');
    }

    async function createDepartment() {
      const input = document.getElementById('new-dept-name');
      const name = input.value.trim();
      if (!name || !CURRENT_PROPERTY) return;

      const res = await fetch(`${API_BASE}/properties/${CURRENT_PROPERTY.id}/departments`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'Authorization': `Bearer ${AUTH_TOKEN}`
        },
        body: JSON.stringify({ name })
      });
      const json = await res.json();
      if (json.success) {
        if (!CURRENT_PROPERTY.departments) CURRENT_PROPERTY.departments = [];
        CURRENT_PROPERTY.departments.push(json.data);
        renderDepartments();
        input.value = '';
      }
    }

    async function saveWidgetConfig() {
      if (!CURRENT_PROPERTY) return;
      const color = document.getElementById('cust-color').value;
      const welcome = document.getElementById('cust-welcome').value;

      const res = await fetch(`${API_BASE}/properties/${CURRENT_PROPERTY.id}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'Authorization': `Bearer ${AUTH_TOKEN}`
        },
        body: JSON.stringify({
          widget_config: {
            ...CURRENT_PROPERTY.widget_config,
            primary_color: color,
            welcome_message: welcome
          }
        })
      });
      const json = await res.json();
      if (json.success) {
        alert('Widget configuration updated successfully!');
      }
    }

    async function loadAgents() {
      const res = await fetch(`${API_BASE}/agents`, {
        headers: { 
          'Authorization': `Bearer ${AUTH_TOKEN}`,
          'Accept': 'application/json'
        }
      });
      const json = await res.json();
      if (json.success) {
        const list = document.getElementById('agents-list');
        list.innerHTML = json.data.map(a => `
          <div style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; border:1px solid var(--border-color); padding:12px 16px; border-radius:10px;">
            <div>
              <strong style="color:#0f172a">${a.name}</strong>
              <div style="font-size:12px; color:var(--text-muted)">${a.email}</div>
            </div>
            <span class="conv-tag" style="background:${a.is_online ? '#ecfdf5' : '#f1f5f9'}; color:${a.is_online ? '#059669' : '#64748b'}; border:1px solid ${a.is_online ? '#a7f3d0' : '#e2e8f0'}">
              ${a.is_online ? '● Online' : 'Offline'}
            </span>
          </div>
        `).join('');
      }
    }

    async function createAgent() {
      const name = document.getElementById('new-agent-name').value.trim();
      const email = document.getElementById('new-agent-email').value.trim();
      const pass = document.getElementById('new-agent-password').value;

      if (!name || !email || !pass) {
        alert('Please fill all agent fields.');
        return;
      }

      const res = await fetch(`${API_BASE}/agents`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'Authorization': `Bearer ${AUTH_TOKEN}`
        },
        body: JSON.stringify({ name, email, password: pass })
      });
      const json = await res.json();
      if (json.success) {
        alert('Agent added successfully!');
        document.getElementById('new-agent-name').value = '';
        document.getElementById('new-agent-email').value = '';
        document.getElementById('new-agent-password').value = '';
        loadAgents();
      } else {
        alert(json.message || 'Failed to add agent.');
      }
    }

    let PLATFORM_TENANTS = [];

    async function loadPlatformData() {
      if (!AUTH_TOKEN || CURRENT_USER?.role !== 'super_admin') return;

      try {
        // Load Platform KPIs
        const statsRes = await fetch(`${API_BASE}/platform/stats`, {
          headers: { 'Authorization': `Bearer ${AUTH_TOKEN}`, 'Accept': 'application/json' }
        });
        const statsJson = await statsRes.json();
        if (statsJson.success) {
          const s = statsJson.data;
          document.getElementById('platform-stat-tenants').textContent = s.total_tenants;
          document.getElementById('platform-stat-sites').textContent = s.total_properties;
          document.getElementById('platform-stat-agents').textContent = s.total_agents;
          document.getElementById('platform-stat-messages').textContent = s.total_messages;
        }

        // Load Platform Tenants Table
        const tenantsRes = await fetch(`${API_BASE}/platform/tenants`, {
          headers: { 'Authorization': `Bearer ${AUTH_TOKEN}`, 'Accept': 'application/json' }
        });
        const tenantsJson = await tenantsRes.json();
        if (tenantsJson.success) {
          PLATFORM_TENANTS = tenantsJson.data;
          const tbody = document.getElementById('platform-tenants-tbody');
          if (PLATFORM_TENANTS.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="padding:20px; text-align:center; color:var(--text-dim)">No tenants registered yet.</td></tr>';
          } else {
            tbody.innerHTML = PLATFORM_TENANTS.map(t => `
              <tr style="border-bottom:1px solid var(--border-color);">
                <td style="padding:12px 14px;">
                  <strong style="color:#0f172a;">${t.name}</strong>
                  <div style="font-size:11px; color:var(--text-dim);">ID: ${t.id.substring(0, 8)}...</div>
                </td>
                <td style="padding:12px 14px; color:var(--text-muted);">${t.email || 'N/A'}</td>
                <td style="padding:12px 14px;"><span class="badge-plan">${t.plan || 'Free'}</span></td>
                <td style="padding:12px 14px; color:#0f172a; font-weight:600;">${t.properties_count || 0}</td>
                <td style="padding:12px 14px; color:#0f172a; font-weight:600;">${t.users_count || 0}</td>
                <td style="padding:12px 14px;"><span class="badge-status-active">${t.status || 'active'}</span></td>
                <td style="padding:12px 14px; text-align:right;">
                  <button class="btn-primary" style="padding:6px 14px; font-size:12px; width:auto;" onclick="inspectTenant('${t.id}')">Inspect Workspace →</button>
                </td>
              </tr>
            `).join('');
          }
        }
        loadPlatformUsers();
      } catch(e) {
        console.error('Failed to load platform data:', e);
      }
    }
    window.loadPlatformData = loadPlatformData;

    function inspectTenant(tenantId) {
      const tenant = PLATFORM_TENANTS.find(t => t.id === tenantId);
      if (!tenant) return;

      document.getElementById('rail-company-name').textContent = tenant.name;
      if (tenant.properties && tenant.properties.length > 0) {
        CURRENT_PROPERTY = tenant.properties[0];
        document.getElementById('rail-property-name').textContent = CURRENT_PROPERTY.site_name;
        updateEmbedSnippet();
      } else {
        CURRENT_PROPERTY = null;
        document.getElementById('rail-property-name').textContent = 'No sites';
      }

      switchMainView('inbox');
      loadConversations();
      alert('Switched to tenant workspace: ' + tenant.name + '. You are now viewing their live chat activity.');
    }
    window.inspectTenant = inspectTenant;

    // Forgot Password Flow
    function toggleForgotPass(show) {
      document.getElementById('login-card').style.display = show ? 'none' : 'block';
      document.getElementById('forgot-card').style.display = show ? 'block' : 'none';
      document.getElementById('forgot-error-msg').style.display = 'none';
      document.getElementById('forgot-success-msg').style.display = 'none';
    }
    window.toggleForgotPass = toggleForgotPass;

    async function submitForgotPassword() {
      const email = document.getElementById('forgot-email').value.trim();
      const newPass = document.getElementById('forgot-new-password').value;
      const btn = document.getElementById('forgot-submit-btn');
      const errEl = document.getElementById('forgot-error-msg');
      const succEl = document.getElementById('forgot-success-msg');

      errEl.style.display = 'none';
      succEl.style.display = 'none';
      btn.disabled = true;
      btn.textContent = 'Updating...';

      try {
        const res = await fetch(`${API_BASE}/auth/forgot-password`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ email: email, new_password: newPass })
        });
        const json = await res.json();
        if (json.success) {
          succEl.textContent = json.message;
          succEl.style.display = 'block';
          setTimeout(() => {
            toggleForgotPass(false);
            document.getElementById('login-email').value = email;
            document.getElementById('login-password').value = newPass;
          }, 1800);
        } else {
          errEl.textContent = json.message || 'Password reset failed.';
          errEl.style.display = 'block';
        }
      } catch (e) {
        errEl.textContent = 'Server connection error.';
        errEl.style.display = 'block';
      } finally {
        btn.disabled = false;
        btn.textContent = 'Reset & Update Password';
      }
    }
    window.submitForgotPassword = submitForgotPassword;

    // Modal Controls for Super Admin
    function openCreateTenantModal() {
      document.getElementById('modal-create-tenant').style.display = 'flex';
    }
    window.openCreateTenantModal = openCreateTenantModal;

    function closeCreateTenantModal() {
      document.getElementById('modal-create-tenant').style.display = 'none';
    }
    window.closeCreateTenantModal = closeCreateTenantModal;

    function openEmbedGuideModal() {
      document.getElementById('modal-embed-guide').style.display = 'flex';
    }
    window.openEmbedGuideModal = openEmbedGuideModal;

    function closeEmbedGuideModal() {
      document.getElementById('modal-embed-guide').style.display = 'none';
    }
    window.closeEmbedGuideModal = closeEmbedGuideModal;

    async function submitCreateTenant() {
      const companyName = document.getElementById('new-comp-name').value.trim();
      const adminName = document.getElementById('new-comp-admin-name').value.trim();
      const adminEmail = document.getElementById('new-comp-admin-email').value.trim();
      const adminPass = document.getElementById('new-comp-admin-pass').value;
      const siteName = document.getElementById('new-comp-site-name').value.trim();
      const siteUrl = document.getElementById('new-comp-site-url').value.trim();
      const plan = document.getElementById('new-comp-plan').value;

      try {
        const res = await fetch(`${API_BASE}/platform/tenants`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${AUTH_TOKEN}`
          },
          body: JSON.stringify({
            company_name: companyName,
            admin_name: adminName,
            admin_email: adminEmail,
            admin_password: adminPass,
            website_name: siteName,
            website_url: siteUrl,
            plan: plan
          })
        });
        const json = await res.json();
        if (json.success) {
          closeCreateTenantModal();
          alert(`Success! Created ChatBox workspace for "${companyName}".\n\nPublic Key: ${json.data.property.public_key}\nAdmin Login: ${adminEmail}`);
          loadPlatformData();
          loadPlatformUsers();
        } else {
          alert(json.message || 'Failed to create company tenant.');
        }
      } catch (e) {
        alert('Server error creating company tenant.');
      }
    }
    window.submitCreateTenant = submitCreateTenant;

    // Platform User Management
    let PLATFORM_USERS = [];

    async function loadPlatformUsers() {
      if (!AUTH_TOKEN || CURRENT_USER?.role !== 'super_admin') return;
      try {
        const res = await fetch(`${API_BASE}/platform/users`, {
          headers: { 'Authorization': `Bearer ${AUTH_TOKEN}`, 'Accept': 'application/json' }
        });
        const json = await res.json();
        if (json.success) {
          PLATFORM_USERS = json.data;
          const tbody = document.getElementById('platform-users-tbody');
          if (PLATFORM_USERS.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="padding:20px; text-align:center; color:var(--text-dim)">No users found.</td></tr>';
          } else {
            tbody.innerHTML = PLATFORM_USERS.map(u => {
              const roleBadge = u.role === 'super_admin' ? '<span class="badge-plan" style="background:#fee2e2; color:#b91c1c; border-color:#fca5a5;">⚡ Super Admin</span>' :
                                (u.role === 'admin' ? '<span class="badge-plan">👑 Tenant Admin</span>' : '<span class="badge-plan" style="background:#f1f5f9; color:#475569; border-color:#e2e8f0;">🎧 Agent</span>');
              return `
                <tr style="border-bottom:1px solid var(--border-color);">
                  <td style="padding:12px 14px;">
                    <strong style="color:#0f172a;">${u.name}</strong>
                  </td>
                  <td style="padding:12px 14px; color:var(--text-muted); font-family:monospace;">${u.email}</td>
                  <td style="padding:12px 14px;">${roleBadge}</td>
                  <td style="padding:12px 14px; color:#0f172a;">${u.tenant ? u.tenant.name : '<em style="color:var(--text-dim)">Platform Master</em>'}</td>
                  <td style="padding:12px 14px;">
                    <span class="conv-tag" style="background:${u.is_online ? '#ecfdf5' : '#f1f5f9'}; color:${u.is_online ? '#059669' : '#64748b'};">
                      ${u.is_online ? '● Online' : 'Offline'}
                    </span>
                  </td>
                  <td style="padding:12px 14px; text-align:right;">
                    <div style="display:flex; gap:6px; justify-content:flex-end;">
                      <button class="btn-action" style="padding:5px 12px; font-size:12px;" onclick="openEditUserModal('${u.id}')">✏️ Edit</button>
                      ${u.role !== 'super_admin' ? `<button class="btn-action" style="padding:5px 12px; font-size:12px; color:#b91c1c; border-color:#fca5a5;" onclick="deletePlatformUser('${u.id}', '${u.name.replace(/'/g, "\\'")}')">🗑️ Delete</button>` : ''}
                    </div>
                  </td>
                </tr>
              `;
            }).join('');
          }
        }
      } catch (e) {
        console.error('Failed to load platform users:', e);
      }
    }
    window.loadPlatformUsers = loadPlatformUsers;

    async function deletePlatformUser(userId, userName) {
      if (!confirm(`Are you sure you want to delete user "${userName}"? This will permanently remove their access.`)) return;
      try {
        const res = await fetch(`${API_BASE}/platform/users/${userId}`, {
          method: 'DELETE',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${AUTH_TOKEN}`
          }
        });
        const json = await res.json();
        if (json.success) {
          alert(`User "${userName}" was successfully deleted.`);
          loadPlatformUsers();
          if (CURRENT_USER?.role === 'super_admin') {
            loadPlatformData();
          }
        } else {
          alert(json.message || 'Failed to delete user.');
        }
      } catch (e) {
        alert('Server error deleting user.');
      }
    }
    window.deletePlatformUser = deletePlatformUser;

    async function triggerFlushCache() {
      if (!confirm('Are you sure you want to flush system cache? All cached stats, properties, and FAQs will be refreshed.')) return;
      try {
        const res = await fetch(`${API_BASE}/platform/flush-cache`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${AUTH_TOKEN}`
          }
        });
        const json = await res.json();
        if (json.success) {
          alert('⚡ System cache flushed successfully!');
          if (CURRENT_USER?.role === 'super_admin') {
            loadPlatformData();
          }
        } else {
          alert(json.message || 'Failed to flush cache.');
        }
      } catch (e) {
        alert('Server connection error while flushing cache.');
      }
    }
    window.triggerFlushCache = triggerFlushCache;

    function openProfileModal() {
      if (!CURRENT_USER) return;
      document.getElementById('profile-name').value = CURRENT_USER.name || '';
      document.getElementById('profile-email').value = CURRENT_USER.email || '';
      document.getElementById('profile-password').value = '';
      document.getElementById('modal-profile').style.display = 'flex';
    }
    window.openProfileModal = openProfileModal;

    function closeProfileModal() {
      document.getElementById('modal-profile').style.display = 'none';
    }
    window.closeProfileModal = closeProfileModal;

    async function submitUpdateProfile() {
      const name = document.getElementById('profile-name').value.trim();
      const email = document.getElementById('profile-email').value.trim();
      const pass = document.getElementById('profile-password').value;

      const payload = { name, email };
      if (pass && pass.length >= 6) {
        payload.password = pass;
      }

      try {
        const res = await fetch(`${API_BASE}/auth/profile`, {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${AUTH_TOKEN}`
          },
          body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
          CURRENT_USER = json.data;
          document.getElementById('current-user-name').textContent = CURRENT_USER.name;
          document.getElementById('current-user-avatar').textContent = (CURRENT_USER.name || 'US').substring(0, 2).toUpperCase();
          closeProfileModal();
          alert('Profile credentials updated successfully!');
        } else {
          alert(json.message || 'Failed to update profile.');
        }
      } catch (e) {
        alert('Server error updating profile.');
      }
    }
    window.submitUpdateProfile = submitUpdateProfile;

    // Knowledge Base / FAQ Management
    let BLADE_FAQS = [];

    async function loadBladeFaqs() {
      if (!AUTH_TOKEN) return;
      const propId = CURRENT_PROPERTY?.id;
      if (!propId) {
        const container = document.getElementById('blade-faqs-container');
        if (container) {
          container.innerHTML = '<div style="text-align:center; padding:30px; color:var(--text-dim)">No active website property selected. Please select or inspect a tenant first.</div>';
        }
        return;
      }

      try {
        const res = await fetch(`${API_BASE}/properties/${propId}/faqs`, {
          headers: {
            'Authorization': `Bearer ${AUTH_TOKEN}`,
            'Accept': 'application/json'
          }
        });
        const json = await res.json();
        if (json.success) {
          BLADE_FAQS = json.data || [];
          renderBladeFaqs();
        }
      } catch (e) {
        console.error('Failed to load FAQs:', e);
      }
    }
    window.loadBladeFaqs = loadBladeFaqs;

    function renderBladeFaqs() {
      const q = (document.getElementById('blade-faq-search')?.value || '').toLowerCase();
      const container = document.getElementById('blade-faqs-container');
      const countEl = document.getElementById('blade-faq-count-text');
      if (!container) return;

      const filtered = BLADE_FAQS.filter(f => 
        (f.question || '').toLowerCase().includes(q) ||
        (f.answer || '').toLowerCase().includes(q) ||
        (f.category || '').toLowerCase().includes(q)
      );

      if (countEl) countEl.textContent = `${filtered.length} FAQs total`;

      if (filtered.length === 0) {
        container.innerHTML = `
          <div style="text-align:center; padding:40px 20px; background:#f8fafc; border:1px dashed var(--border-color); border-radius:12px;">
            <div style="font-size:32px; margin-bottom:8px;">💬</div>
            <div style="font-weight:700; color:#0f172a; margin-bottom:4px;">No FAQs found</div>
            <div style="font-size:13px; color:var(--text-muted); margin-bottom:14px;">Add common questions so visitors get immediate answers before opening a live chat ticket.</div>
            <button class="btn-primary" style="width:auto; padding:8px 18px; margin:0 auto;" onclick="openAddFaqModal()">+ Add Your First FAQ</button>
          </div>
        `;
        return;
      }

      container.innerHTML = filtered.map(f => `
        <div style="background:#f8fafc; border:1px solid var(--border-color); border-radius:12px; padding:16px;">
          <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
            <div>
              <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                <span class="conv-tag" style="background:#e0e7ff; color:#3730a3; font-weight:600;">🏷️ ${f.category || 'General'}</span>
                <span style="font-size:11px; color:var(--text-dim);">Order: #${f.order || 1}</span>
              </div>
              <h4 style="font-size:15px; font-weight:700; color:#0f172a; margin-bottom:8px;">${f.question}</h4>
              <p style="font-size:13px; color:var(--text-muted); line-height:1.5; white-space:pre-wrap;">${f.answer}</p>
            </div>
            <div style="display:flex; gap:6px;">
              <button class="btn-action" style="padding:5px 12px; font-size:12px; color:#b91c1c; border-color:#fca5a5;" onclick="deleteBladeFaq('${f.id}')">🗑️ Delete</button>
            </div>
          </div>
        </div>
      `).join('');
    }
    window.renderBladeFaqs = renderBladeFaqs;

    function openAddFaqModal() {
      document.getElementById('new-faq-question').value = '';
      document.getElementById('new-faq-answer').value = '';
      document.getElementById('new-faq-category').value = 'General';
      document.getElementById('new-faq-order').value = BLADE_FAQS.length + 1;
      document.getElementById('modal-add-faq').style.display = 'flex';
    }
    window.openAddFaqModal = openAddFaqModal;

    function closeAddFaqModal() {
      document.getElementById('modal-add-faq').style.display = 'none';
    }
    window.closeAddFaqModal = closeAddFaqModal;

    async function submitAddFaq() {
      const propId = CURRENT_PROPERTY?.id;
      if (!propId) {
        alert('Please select or inspect a property before adding FAQs.');
        return;
      }

      const q = document.getElementById('new-faq-question').value.trim();
      const a = document.getElementById('new-faq-answer').value.trim();
      const c = document.getElementById('new-faq-category').value.trim() || 'General';
      const o = parseInt(document.getElementById('new-faq-order').value) || 1;

      try {
        const res = await fetch(`${API_BASE}/properties/${propId}/faqs`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${AUTH_TOKEN}`
          },
          body: JSON.stringify({
            question: q,
            answer: a,
            category: c,
            order: o,
            is_active: true
          })
        });
        const json = await res.json();
        if (json.success) {
          closeAddFaqModal();
          alert('FAQ created successfully and is now active for this company!');
          loadBladeFaqs();
        } else {
          alert(json.message || 'Failed to create FAQ.');
        }
      } catch (e) {
        alert('Server error creating FAQ.');
      }
    }
    window.submitAddFaq = submitAddFaq;

    async function deleteBladeFaq(faqId) {
      if (!confirm('Are you sure you want to delete this FAQ?')) return;
      try {
        const res = await fetch(`${API_BASE}/faqs/${faqId}`, {
          method: 'DELETE',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${AUTH_TOKEN}`
          }
        });
        const json = await res.json();
        if (json.success) {
          loadBladeFaqs();
        } else {
          alert(json.message || 'Failed to delete FAQ.');
        }
      } catch (e) {
        alert('Server error deleting FAQ.');
      }
    }
    window.deleteBladeFaq = deleteBladeFaq;

    function openEditUserModal(userId) {
      const u = PLATFORM_USERS.find(x => x.id === userId);
      if (!u) return;

      document.getElementById('edit-user-id').value = u.id;
      document.getElementById('edit-user-name').value = u.name;
      document.getElementById('edit-user-email').value = u.email;
      document.getElementById('edit-user-role').value = u.role;
      document.getElementById('edit-user-password').value = '';
      document.getElementById('modal-edit-user').style.display = 'flex';
    }
    window.openEditUserModal = openEditUserModal;

    function closeEditUserModal() {
      document.getElementById('modal-edit-user').style.display = 'none';
    }
    window.closeEditUserModal = closeEditUserModal;

    async function submitUpdateUser() {
      const userId = document.getElementById('edit-user-id').value;
      const name = document.getElementById('edit-user-name').value.trim();
      const email = document.getElementById('edit-user-email').value.trim();
      const role = document.getElementById('edit-user-role').value;
      const pass = document.getElementById('edit-user-password').value;

      const payload = { name, email, role };
      if (pass && pass.length >= 6) {
        payload.password = pass;
      }

      try {
        const res = await fetch(`${API_BASE}/platform/users/${userId}`, {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${AUTH_TOKEN}`
          },
          body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
          closeEditUserModal();
          alert('User updated successfully by Super Admin!');
          loadPlatformUsers();
        } else {
          alert(json.message || 'Failed to update user.');
        }
      } catch (e) {
        alert('Server connection error.');
      }
    }
    window.submitUpdateUser = submitUpdateUser;

    // Auto-init on page load if token is cached
    if (AUTH_TOKEN) {
      initDashboard();
    }
  </script>

</body>
</html>
