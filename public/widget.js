/**
 * JixChat Realtime SaaS Live Chat Widget
 * Encapsulated inside Shadow DOM for 100% style isolation and security.
 */
(function () {
  'use strict';

  // Prevent multiple initializations
  if (window.__JixChatInitialized) return;
  window.__JixChatInitialized = true;

  const config = window.ChatBoxConfig || window.JixChatConfig || {};
  if (!config.propertyKey) {
    console.warn('[ChatBox] Missing window.ChatBoxConfig.propertyKey');
    return;
  }

  const API_BASE = (config.apiUrl || window.location.origin).replace(/\/$/, '') + '/api/v1/widget';
  const STORAGE_KEY = 'jixchat_vtok_' + config.propertyKey.substring(0, 12);
  let visitorToken = localStorage.getItem(STORAGE_KEY) || null;

  let state = {
    isOpen: false,
    soundEnabled: true,
    property: null,
    visitor: null,
    departments: [],
    faqs: [],
    conversation: null,
    messages: [],
    broadcasting: null,
    isAgentTyping: false,
    typingTimeout: null,
    unreadCount: 0,
    primaryColor: '#2563eb',
  };

  // Synthesize notification sound using Web Audio API (no external file dependency)
  function playNotificationSound() {
    if (!state.soundEnabled) return;
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
      osc.frequency.exponentialRampToValueAtTime(880, ctx.currentTime + 0.12); // A5
      gain.gain.setValueAtTime(0.15, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + 0.35);
    } catch (e) {
      // AudioContext autoplay restrictions handled silently
    }
  }

  // Create Shadow DOM Container
  const container = document.createElement('div');
  container.id = 'jixchat-widget-root';
  document.body.appendChild(container);
  const shadow = container.attachShadow({ mode: 'open' });

  // Stylesheet
  const style = document.createElement('style');
  style.textContent = `
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      -webkit-font-smoothing: antialiased;
    }

    .jix-bubble-btn {
      position: fixed;
      bottom: 24px;
      right: 24px;
      width: 60px;
      height: 60px;
      border-radius: 50%;
      background: var(--primary, #2563eb);
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18), 0 2px 6px rgba(0, 0, 0, 0.1);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      z-index: 2147483647;
      transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.2s ease;
      border: none;
      outline: none;
    }
    .jix-bubble-btn:hover {
      transform: scale(1.08);
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.25);
    }
    .jix-bubble-btn svg {
      width: 28px;
      height: 28px;
      fill: #ffffff;
      transition: transform 0.25s ease;
    }

    .jix-badge {
      position: absolute;
      top: -3px;
      right: -3px;
      background: #ef4444;
      color: #fff;
      font-size: 11px;
      font-weight: 700;
      min-width: 20px;
      height: 20px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0 5px;
      border: 2px solid #ffffff;
      box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }

    .jix-window {
      position: fixed;
      bottom: 96px;
      right: 24px;
      width: 380px;
      height: 600px;
      max-height: calc(100vh - 120px);
      background: #ffffff;
      border-radius: 16px;
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.18), 0 2px 10px rgba(0, 0, 0, 0.08);
      display: flex;
      flex-direction: column;
      overflow: hidden;
      z-index: 2147483646;
      opacity: 0;
      transform: translateY(20px) scale(0.96);
      pointer-events: none;
      transition: opacity 0.25s cubic-bezier(0.16, 1, 0.3, 1), transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      border: 1px solid rgba(0, 0, 0, 0.06);
    }

    .jix-window.open {
      opacity: 1;
      transform: translateY(0) scale(1);
      pointer-events: auto;
    }

    /* Header */
    .jix-header {
      background: var(--primary, #2563eb);
      color: #ffffff;
      padding: 16px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }
    .jix-header-info {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .jix-header-avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.2);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 16px;
      position: relative;
    }
    .jix-online-dot {
      width: 10px;
      height: 10px;
      background: #22c55e;
      border: 2px solid #ffffff;
      border-radius: 50%;
      position: absolute;
      bottom: 0;
      right: 0;
    }
    .jix-header-titles h3 {
      font-size: 15px;
      font-weight: 600;
      line-height: 1.2;
    }
    .jix-header-titles p {
      font-size: 11px;
      opacity: 0.85;
      margin-top: 2px;
    }
    .jix-header-actions {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .jix-icon-btn {
      background: transparent;
      border: none;
      cursor: pointer;
      color: #ffffff;
      opacity: 0.8;
      padding: 6px;
      border-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: opacity 0.15s, background 0.15s;
    }
    .jix-icon-btn:hover {
      opacity: 1;
      background: rgba(255, 255, 255, 0.15);
    }

    /* Body Area */
    .jix-body {
      flex: 1;
      overflow-y: auto;
      padding: 16px;
      background: #f8fafc;
      display: flex;
      flex-direction: column;
      gap: 12px;
      scroll-behavior: smooth;
    }

    /* Pre-Chat Form */
    .jix-prechat {
      background: #ffffff;
      padding: 20px;
      border-radius: 12px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.04);
      border: 1px solid #e2e8f0;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .jix-prechat h4 {
      font-size: 15px;
      font-weight: 600;
      color: #1e293b;
      margin-bottom: 2px;
    }
    .jix-prechat p {
      font-size: 12px;
      color: #64748b;
      line-height: 1.4;
      margin-bottom: 6px;
    }
    .jix-form-group {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .jix-form-group label {
      font-size: 11px;
      font-weight: 600;
      color: #475569;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .jix-input, .jix-select, .jix-textarea {
      width: 100%;
      padding: 10px 12px;
      border-radius: 8px;
      border: 1px solid #cbd5e1;
      font-size: 13px;
      color: #1e293b;
      background: #ffffff;
      outline: none;
      transition: border-color 0.15s, box-shadow 0.15s;
    }
    .jix-input:focus, .jix-select:focus, .jix-textarea:focus {
      border-color: var(--primary, #2563eb);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    }
    .jix-btn {
      width: 100%;
      padding: 11px;
      border-radius: 8px;
      background: var(--primary, #2563eb);
      color: #ffffff;
      font-weight: 600;
      font-size: 14px;
      border: none;
      cursor: pointer;
      transition: opacity 0.15s, transform 0.1s;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }
    .jix-btn:hover {
      opacity: 0.94;
    }
    .jix-btn:active {
      transform: scale(0.98);
    }

    /* FAQ Knowledge Base */
    .jix-faq-item {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      margin-bottom: 8px;
      overflow: hidden;
      transition: all 0.2s;
    }
    .jix-faq-question {
      padding: 10px 12px;
      font-size: 13px;
      font-weight: 600;
      color: #1e293b;
      cursor: pointer;
      display: flex;
      justify-content: space-between;
      align-items: center;
      user-select: none;
    }
    .jix-faq-question:hover {
      background: #f1f5f9;
      color: var(--primary, #2563eb);
    }
    .jix-faq-answer {
      padding: 0 12px 10px;
      font-size: 12.5px;
      color: #475569;
      line-height: 1.5;
      display: none;
    }
    .jix-faq-item.open .jix-faq-answer {
      display: block;
    }

    /* Message Bubbles */
    .jix-msg {
      display: flex;
      flex-direction: column;
      max-width: 82%;
      animation: jixFadeIn 0.2s ease;
    }
    @keyframes jixFadeIn {
      from { opacity: 0; transform: translateY(6px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .jix-msg.visitor {
      align-self: flex-end;
      align-items: flex-end;
    }
    .jix-msg.agent {
      align-self: flex-start;
      align-items: flex-start;
    }
    .jix-msg-bubble {
      padding: 10px 14px;
      border-radius: 14px;
      font-size: 13.5px;
      line-height: 1.45;
      word-break: break-word;
      position: relative;
    }
    .jix-msg.visitor .jix-msg-bubble {
      background: var(--primary, #2563eb);
      color: #ffffff;
      border-bottom-right-radius: 3px;
    }
    .jix-msg.agent .jix-msg-bubble {
      background: #ffffff;
      color: #1e293b;
      border: 1px solid #e2e8f0;
      box-shadow: 0 1px 3px rgba(0,0,0,0.03);
      border-bottom-left-radius: 3px;
    }
    .jix-msg-meta {
      font-size: 10px;
      color: #94a3b8;
      margin-top: 3px;
      padding: 0 2px;
    }

    /* Typing Dots */
    .jix-typing-bubble {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 8px 12px;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      width: fit-content;
      align-self: flex-start;
      box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    }
    .jix-typing-dot {
      width: 6px;
      height: 6px;
      background: #94a3b8;
      border-radius: 50%;
      animation: jixBounce 1.3s infinite;
    }
    .jix-typing-dot:nth-child(2) { animation-delay: 0.15s; }
    .jix-typing-dot:nth-child(3) { animation-delay: 0.3s; }
    @keyframes jixBounce {
      0%, 80%, 100% { transform: translateY(0); }
      40% { transform: translateY(-4px); }
    }

    /* Input Footer */
    .jix-footer {
      padding: 12px 16px;
      background: #ffffff;
      border-top: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .jix-input-box {
      flex: 1;
      display: flex;
      align-items: center;
      background: #f1f5f9;
      border-radius: 20px;
      padding: 6px 14px;
      border: 1px solid transparent;
      transition: border-color 0.15s, background 0.15s;
    }
    .jix-input-box:focus-within {
      background: #ffffff;
      border-color: var(--primary, #2563eb);
    }
    .jix-chat-input {
      flex: 1;
      border: none;
      background: transparent;
      outline: none;
      font-size: 13.5px;
      color: #1e293b;
      max-height: 80px;
      resize: none;
    }
    .jix-send-btn {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: var(--primary, #2563eb);
      border: none;
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: transform 0.15s, opacity 0.15s;
    }
    .jix-send-btn:hover {
      opacity: 0.92;
      transform: scale(1.05);
    }
    .jix-send-btn:disabled {
      opacity: 0.4;
      cursor: not-allowed;
      transform: none;
    }

    /* Mobile Responsive bottom sheet */
    @media (max-width: 480px) {
      .jix-window {
        bottom: 0;
        right: 0;
        width: 100vw;
        height: 100vh;
        max-height: 100vh;
        border-radius: 0;
      }
      .jix-bubble-btn {
        bottom: 16px;
        right: 16px;
      }
    }
  `;
  shadow.appendChild(style);

  // SVG Icons
  const ICONS = {
    chat: `<svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H5.2L4 17.2V4h16v12z"/></svg>`,
    close: `<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>`,
    soundOn: `<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>`,
    soundOff: `<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27l4.73 4.73H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/></svg>`,
    send: `<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>`,
  };

  // Build DOM Structure
  const wrapper = document.createElement('div');
  wrapper.innerHTML = `
    <button class="jix-bubble-btn" id="jix-bubble" aria-label="Open Live Chat">
      ${ICONS.chat}
      <span class="jix-badge" id="jix-badge" style="display:none">0</span>
    </button>

    <div class="jix-window" id="jix-window">
      <!-- Header -->
      <div class="jix-header">
        <div class="jix-header-info">
          <div class="jix-header-avatar">
            <span id="jix-avatar-text">SH</span>
            <div class="jix-online-dot"></div>
          </div>
          <div class="jix-header-titles">
            <h3 id="jix-header-title">Live Support</h3>
            <p id="jix-header-status" style="display:flex; align-items:center; gap:6px; font-size:11.5px;">
              <span id="jix-status-text">Online • Replies instantly</span>
              <span id="jix-header-timer" style="display:none; font-family:monospace; font-weight:700; background:rgba(255,255,255,0.22); padding:1px 6px; border-radius:10px; font-size:10.5px; letter-spacing:0.5px;">⏱️ 00:00</span>
            </p>
          </div>
        </div>
        <div class="jix-header-actions">
          <button class="jix-icon-btn" id="jix-transcript-header-btn" title="Email me chat transcript" style="display:none; font-size:14px;">
            ✉️
          </button>
          <button class="jix-icon-btn" id="jix-faq-modal-btn" title="Instant FAQs & Help" style="display: none;">
            ❓
          </button>
          <button class="jix-icon-btn" id="jix-sound-toggle" title="Toggle Sound">
            ${ICONS.soundOn}
          </button>
          <button class="jix-icon-btn" id="jix-close-btn" title="Close Chat">
            ${ICONS.close}
          </button>
        </div>
      </div>

      <!-- FAQ Drawer Overlay -->
      <div id="jix-faq-drawer" style="display:none; position:absolute; top:64px; left:0; right:0; bottom:0; background:#ffffff; z-index:20; overflow-y:auto; padding:18px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
          <h4 style="font-size:14px; font-weight:700; color:#0f172a; margin:0;">💡 Instant Help &amp; FAQs</h4>
          <button id="jix-faq-drawer-close" style="background:none; border:none; cursor:pointer; font-size:16px; color:#64748b;">✕</button>
        </div>
        <div id="jix-faq-drawer-content" style="display:flex; flex-direction:column; gap:10px;"></div>
      </div>

      <!-- Body / Messages -->
      <div class="jix-body" id="jix-body">
        <div style="display:flex; align-items:center; justify-content:center; height:100%; color:#94a3b8; font-size:13px;">
          <span>Loading chat...</span>
        </div>
      </div>

      <!-- Footer Input -->
      <div class="jix-footer" id="jix-footer" style="display:none">
        <input type="file" id="jix-file-input" style="display:none" accept="image/*,application/pdf,.doc,.docx,.txt" />
        <button class="jix-icon-btn" id="jix-attach-btn" title="Attach file or screenshot" style="background:none; border:none; cursor:pointer; font-size:16px; padding:0 6px; color:#64748b;">
          📎
        </button>
        <div class="jix-input-box">
          <input type="text" class="jix-chat-input" id="jix-chat-input" placeholder="Type your message..." autocomplete="off" />
        </div>
        <button class="jix-send-btn" id="jix-send-btn" title="Send message">
          ${ICONS.send}
        </button>
      </div>
    </div>
  `;
  shadow.appendChild(wrapper);

  // References
  const bubbleBtn = shadow.getElementById('jix-bubble');
  const chatWindow = shadow.getElementById('jix-window');
  const closeBtn = shadow.getElementById('jix-close-btn');
  const soundToggleBtn = shadow.getElementById('jix-sound-toggle');
  const faqModalBtn = shadow.getElementById('jix-faq-modal-btn');
  const faqDrawer = shadow.getElementById('jix-faq-drawer');
  const faqDrawerClose = shadow.getElementById('jix-faq-drawer-close');
  const faqDrawerContent = shadow.getElementById('jix-faq-drawer-content');
  const chatBody = shadow.getElementById('jix-body');
  const chatFooter = shadow.getElementById('jix-footer');
  const chatInput = shadow.getElementById('jix-chat-input');
  const sendBtn = shadow.getElementById('jix-send-btn');
  const attachBtn = shadow.getElementById('jix-attach-btn');
  const fileInput = shadow.getElementById('jix-file-input');
  const transcriptHeaderBtn = shadow.getElementById('jix-transcript-header-btn');
  const badgeEl = shadow.getElementById('jix-badge');
  const headerTitleEl = shadow.getElementById('jix-header-title');

  // Toggle Window
  function toggleChat(forceOpen) {
    state.isOpen = typeof forceOpen === 'boolean' ? forceOpen : !state.isOpen;
    if (state.isOpen) {
      chatWindow.classList.add('open');
      state.unreadCount = 0;
      updateBadge();
      setTimeout(() => chatInput?.focus(), 150);
      scrollToBottom();
    } else {
      chatWindow.classList.remove('open');
      if (faqDrawer) faqDrawer.style.display = 'none';
    }
  }

  bubbleBtn.addEventListener('click', () => toggleChat());
  closeBtn.addEventListener('click', () => toggleChat(false));

  // Sound Toggle
  soundToggleBtn.addEventListener('click', () => {
    state.soundEnabled = !state.soundEnabled;
    soundToggleBtn.innerHTML = state.soundEnabled ? ICONS.soundOn : ICONS.soundOff;
  });

  // Open FAQ Drawer
  function openFaqDrawer() {
    if (!state.faqs || state.faqs.length === 0) {
      faqDrawerContent.innerHTML = '<div style="padding:20px; text-align:center; color:#94a3b8; font-size:13px;">No FAQs available right now. Feel free to start a live chat!</div>';
    } else {
      faqDrawerContent.innerHTML = state.faqs.map((f, i) => `
        <div style="border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:8px;">
          <div class="jix-drawer-faq-q" style="font-size:13px; font-weight:600; padding:10px 14px; background:#f8fafc; cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
            <span>${f.question}</span>
            <span style="font-size:11px; color:#94a3b8;">▾</span>
          </div>
          <div class="jix-drawer-faq-a" style="padding:12px 14px; font-size:12.5px; color:#334155; line-height:1.5; background:#ffffff; border-top:1px solid #e2e8f0;">
            ${f.answer}
            <div style="margin-top:10px; border-top:1px dashed #e2e8f0; padding-top:8px;">
              <button class="jix-drawer-ask-btn" data-q="${encodeURIComponent(f.question)}" style="background:none; border:none; padding:0; color:var(--primary,#2563eb); font-size:11.5px; font-weight:600; cursor:pointer;">
                💬 Need more help? Ask our live team →
              </button>
            </div>
          </div>
        </div>
      `).join('');

      faqDrawerContent.querySelectorAll('.jix-drawer-faq-q').forEach(q => {
        q.addEventListener('click', () => {
          const ans = q.nextElementSibling;
          ans.style.display = ans.style.display === 'none' ? 'block' : 'none';
        });
      });

      faqDrawerContent.querySelectorAll('.jix-drawer-ask-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          const qText = decodeURIComponent(btn.getAttribute('data-q') || '');
          faqDrawer.style.display = 'none';
          if (!state.conversation) {
            const msgInput = shadow.getElementById('jix-prechat-msg');
            if (msgInput) {
              msgInput.value = 'I need more information about: ' + qText;
              msgInput.focus();
              msgInput.scrollIntoView({ behavior: 'smooth' });
            }
          } else {
            if (chatInput) {
              chatInput.value = 'I need more information about: ' + qText;
              chatInput.focus();
            }
          }
        });
      });
    }
    faqDrawer.style.display = 'block';
  }

  faqModalBtn.addEventListener('click', openFaqDrawer);
  faqDrawerClose.addEventListener('click', () => {
    faqDrawer.style.display = 'none';
  });

  function updateBadge() {
    if (state.unreadCount > 0 && !state.isOpen) {
      badgeEl.textContent = state.unreadCount > 9 ? '9+' : state.unreadCount;
      badgeEl.style.display = 'flex';
    } else {
      badgeEl.style.display = 'none';
    }
  }

  function scrollToBottom() {
    setTimeout(() => {
      chatBody.scrollTop = chatBody.scrollHeight;
    }, 50);
  }

  let sessionTimerInterval = null;
  function startSessionTimer(startTimeStr) {
    if (sessionTimerInterval) clearInterval(sessionTimerInterval);
    const timerEl = shadow.getElementById('jix-header-timer');
    if (!timerEl) return;
    timerEl.style.display = 'inline-block';

    const startTimestamp = startTimeStr ? new Date(startTimeStr).getTime() : Date.now();
    function tick() {
      const elapsedSec = Math.max(0, Math.floor((Date.now() - startTimestamp) / 1000));
      const mins = String(Math.floor(elapsedSec / 60)).padStart(2, '0');
      const secs = String(elapsedSec % 60).padStart(2, '0');
      timerEl.textContent = `⏱️ ${mins}:${secs}`;
    }
    tick();
    sessionTimerInterval = setInterval(tick, 1000);
  }

  function updateHeaderAgentInfo(agent, startedAt) {
    const avatarText = shadow.getElementById('jix-avatar-text');
    const headerTitle = shadow.getElementById('jix-header-title');
    const statusText = shadow.getElementById('jix-status-text');

    if (agent && agent.name) {
      if (avatarText) avatarText.textContent = (agent.avatar || agent.name.substring(0, 2)).toUpperCase();
      if (headerTitle) headerTitle.textContent = `${agent.name}`;
      if (statusText) statusText.textContent = `🟢 Online (Support)`;
    } else {
      if (avatarText) avatarText.textContent = state.property?.site_name ? state.property.site_name.substring(0, 2).toUpperCase() : 'SH';
      if (headerTitle) headerTitle.textContent = state.property?.site_name ? `${state.property.site_name} Support` : 'Live Support';
      if (statusText) statusText.textContent = state.conversation ? '⏳ Connecting to live agent...' : 'Online • Replies instantly';
    }

    if (state.conversation) {
      startSessionTimer(startedAt || state.conversation.created_at);
    }
  }

  // Render Messages or Prechat Form
  function render() {
    chatBody.innerHTML = '';

    if (!state.conversation) {
      // Render Pre-Chat Form
      chatFooter.style.display = 'none';
      if (transcriptHeaderBtn) transcriptHeaderBtn.style.display = 'none';

      const welcome = state.property?.widget_config?.welcome_message || 'Hello! How can we assist you today?';
      const isOnline = state.isOnline !== false;
      const offlineMsg = state.offlineNotice || "Our support team is currently offline. Send us a message and we'll reply to your email shortly!";
      const offlineNoticeHtml = !isOnline 
        ? `<div style="background:#fffbeb; color:#92400e; padding:10px 14px; border-radius:10px; font-size:12px; margin-bottom:14px; border:1px solid #fde68a; line-height:1.4;">🌙 <strong>We're currently away</strong><div style="font-size:11.5px; opacity:0.9; margin-top:2px;">${offlineMsg}</div></div>` 
        : '';
      
      let deptOptions = '';
      if (state.departments && state.departments.length > 0) {
        deptOptions = `
          <div class="jix-form-group">
            <label>Department</label>
            <select class="jix-select" id="jix-prechat-dept">
              ${state.departments.map(d => `<option value="${d.id}">${d.name}</option>`).join('')}
            </select>
          </div>
        `;
      }

      const formHtml = `
        <div class="jix-prechat">
          ${offlineNoticeHtml}
          <h4>${isOnline ? 'Start a Conversation' : 'Leave a Message'}</h4>
          <p>${welcome}</p>
          <div class="jix-form-group">
            <label>Your Name *</label>
            <input type="text" class="jix-input" id="jix-prechat-name" placeholder="John Doe" value="${state.visitor?.name || ''}" required />
          </div>
          <div class="jix-form-group">
            <label>Email Address *</label>
            <input type="email" class="jix-input" id="jix-prechat-email" placeholder="john@example.com" value="${state.visitor?.email || ''}" required />
          </div>
          ${deptOptions}
          <div class="jix-form-group">
            <label>How can we help? *</label>
            <textarea class="jix-textarea" id="jix-prechat-msg" rows="3" placeholder="Write your question or request here..." required></textarea>
          </div>
          <!-- Anti-Bot Honeypot Trap (Invisible to humans, catches automated bots) -->
          <input type="text" id="jix-bot-trap" name="_bot_trap" style="position:absolute!important;opacity:0!important;pointer-events:none!important;left:-9999px!important;top:-9999px!important;" tabindex="-1" autocomplete="off" />
          <button class="jix-btn" id="jix-prechat-submit">${isOnline ? 'Start Chat' : 'Send Message'}</button>
        </div>
      `;
      chatBody.innerHTML = formHtml;

      shadow.getElementById('jix-prechat-submit').addEventListener('click', handlePrechatSubmit);
      return;
    }

    // Has Active Conversation
    if (transcriptHeaderBtn) transcriptHeaderBtn.style.display = 'inline-flex';

    updateHeaderAgentInfo(state.conversation.assigned_agent, state.conversation.created_at);

    const isClosed = state.conversation.status === 'closed';
    chatFooter.style.display = isClosed ? 'none' : 'flex';

    state.messages.forEach(msg => {
      appendMessageToDOM(msg, false);
    });

    if (state.isAgentTyping) {
      appendTypingToDOM();
    }

    // Post-Chat CSAT Rating Experience
    if (isClosed) {
      renderCsatCard();
    }

    scrollToBottom();
  }

  function renderCsatCard() {
    const existing = shadow.getElementById('jix-csat-card');
    if (existing) return;

    const csatCard = document.createElement('div');
    csatCard.id = 'jix-csat-card';
    csatCard.style.cssText = 'background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin:16px 8px; text-align:center; box-shadow:0 2px 8px rgba(0,0,0,0.04);';

    if (state.conversation.rating) {
      csatCard.innerHTML = `
        <div style="font-weight:700; font-size:13px; color:#0f172a; margin-bottom:4px;">Thank you for your rating!</div>
        <div style="font-size:22px; color:#f59e0b; margin-bottom:6px;">${'⭐'.repeat(state.conversation.rating)}</div>
        <div style="font-size:11.5px; color:#64748b; margin-bottom:12px;">This support session is now resolved.</div>
        <button id="jix-csat-email-transcript-btn" style="background:#e2e8f0; color:#334155; border:none; padding:6px 14px; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer;">✉️ Email My Transcript</button>
      `;
      chatBody.appendChild(csatCard);
      shadow.getElementById('jix-csat-email-transcript-btn')?.addEventListener('click', handleRequestTranscript);
      return;
    }

    csatCard.innerHTML = `
      <div style="font-weight:700; font-size:14px; color:#0f172a; margin-bottom:4px;">How was our support?</div>
      <div style="font-size:12px; color:#64748b; margin-bottom:10px;">This chat has ended. Please rate your experience:</div>
      <div id="jix-star-rating-box" style="font-size:26px; cursor:pointer; margin-bottom:10px; letter-spacing:4px; user-select:none;">
        <span data-star="1" style="opacity:0.4;">⭐</span>
        <span data-star="2" style="opacity:0.4;">⭐</span>
        <span data-star="3" style="opacity:0.4;">⭐</span>
        <span data-star="4" style="opacity:0.4;">⭐</span>
        <span data-star="5" style="opacity:0.4;">⭐</span>
      </div>
      <input type="text" id="jix-csat-comment" placeholder="Optional comments / feedback..." style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; margin-bottom:10px; box-sizing:border-box;" />
      <div style="display:flex; gap:8px; justify-content:center;">
        <button id="jix-submit-csat" style="background:var(--primary); color:#ffffff; border:none; padding:7px 16px; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer;">Submit Feedback</button>
        <button id="jix-csat-transcript-btn" style="background:#e2e8f0; color:#334155; border:none; padding:7px 14px; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer;">✉️ Transcript</button>
      </div>
    `;
    chatBody.appendChild(csatCard);

    let selectedStar = 5;
    const starSpans = csatCard.querySelectorAll('#jix-star-rating-box span');
    function updateStars(val) {
      selectedStar = val;
      starSpans.forEach(s => {
        const starNum = parseInt(s.dataset.star, 10);
        s.style.opacity = starNum <= val ? '1' : '0.3';
      });
    }
    updateStars(5);

    starSpans.forEach(s => {
      s.addEventListener('click', () => updateStars(parseInt(s.dataset.star, 10)));
    });

    shadow.getElementById('jix-csat-transcript-btn')?.addEventListener('click', handleRequestTranscript);
    shadow.getElementById('jix-submit-csat')?.addEventListener('click', async () => {
      const comment = shadow.getElementById('jix-csat-comment')?.value.trim() || '';
      try {
        const res = await fetch(`${API_BASE}/conversations/${state.conversation.id}/rate`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            property_key: config.propertyKey,
            visitor_token: state.visitor.token,
            rating: selectedStar,
            rating_feedback: comment,
          }),
        });
        const json = await res.json();
        if (json.success) {
          state.conversation.rating = selectedStar;
          renderCsatCard();
        }
      } catch(e) {}
    });
  }

  function appendMessageToDOM(msg, playSound = false) {
    if (msg.sender_type === 'system') {
      const sysEl = document.createElement('div');
      sysEl.className = 'jix-sys-msg';
      sysEl.style.cssText = 'align-self: center; margin: 10px auto; text-align: center; max-width: 90%; animation: jixFadeIn 0.25s ease;';
      const cleanTxt = (msg.content || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
      sysEl.innerHTML = `
        <span style="display:inline-flex; align-items:center; gap:6px; background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; font-size:11.5px; font-weight:600; padding:4px 14px; border-radius:20px; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
          ${cleanTxt}
        </span>
      `;
      chatBody.appendChild(sysEl);
      scrollToBottom();
      return;
    }

    const isVisitor = msg.sender_type === 'visitor';
    const isBot = msg.sender_type === 'bot';
    const msgEl = document.createElement('div');
    msgEl.className = `jix-msg ${isVisitor ? 'visitor' : 'agent'}`;

    const time = new Date(msg.created_at || Date.now()).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const botBadge = isBot ? '<div style="font-size:10.5px; font-weight:700; color:#4f46e5; margin-bottom:5px; display:flex; align-items:center; gap:4px;">🤖 BOT ASSISTANT</div>' : '';

    let attachmentHtml = '';
    if (msg.attachment_url) {
      const isImg = msg.message_type === 'image' || /\.(jpe?g|png|gif|webp|svg)($|\?)/i.test(msg.attachment_url);
      if (isImg) {
        attachmentHtml = `<div style="margin-top:8px;"><a href="${msg.attachment_url}" target="_blank" rel="noopener noreferrer"><img src="${msg.attachment_url}" style="max-width:100%; border-radius:8px; display:block; max-height:180px; object-fit:cover; border:1px solid rgba(0,0,0,0.1);" alt="Attachment" /></a></div>`;
      } else {
        attachmentHtml = `<div style="margin-top:8px;"><a href="${msg.attachment_url}" target="_blank" rel="noopener noreferrer" style="display:inline-flex; align-items:center; gap:6px; background:rgba(0,0,0,0.06); padding:6px 12px; border-radius:6px; text-decoration:none; font-size:12px; font-weight:600; color:inherit;">📎 Download Document</a></div>`;
      }
    }

    // Convert newlines to <br> for clean formatting
    const formattedContent = (msg.content || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\n/g, '<br/>');

    msgEl.innerHTML = `
      <div class="jix-msg-bubble">${botBadge}${formattedContent}${attachmentHtml}</div>
      <div class="jix-msg-meta">${time}</div>
    `;

    chatBody.appendChild(msgEl);
    scrollToBottom();

    if (playSound && !isVisitor) {
      playNotificationSound();
      if (!state.isOpen) {
        state.unreadCount++;
        updateBadge();
      }
    }
  }

  function appendTypingToDOM() {
    const existing = shadow.getElementById('jix-typing-indicator');
    if (existing) return;

    const typingEl = document.createElement('div');
    typingEl.id = 'jix-typing-indicator';
    typingEl.className = 'jix-typing-bubble';
    typingEl.innerHTML = `
      <div class="jix-typing-dot"></div>
      <div class="jix-typing-dot"></div>
      <div class="jix-typing-dot"></div>
    `;
    chatBody.appendChild(typingEl);
    scrollToBottom();
  }

  function removeTypingFromDOM() {
    const existing = shadow.getElementById('jix-typing-indicator');
    if (existing) existing.remove();
  }

  // Pre-chat Form Submission
  async function handlePrechatSubmit() {
    const nameInput = shadow.getElementById('jix-prechat-name');
    const emailInput = shadow.getElementById('jix-prechat-email');
    const deptSelect = shadow.getElementById('jix-prechat-dept');
    const msgInput = shadow.getElementById('jix-prechat-msg');
    const submitBtn = shadow.getElementById('jix-prechat-submit');

    const message = msgInput.value.trim();
    if (!message) {
      msgInput.focus();
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Connecting...';

    try {
      const vToken = (state.visitor && state.visitor.token) 
        ? state.visitor.token 
        : (visitorToken || localStorage.getItem(STORAGE_KEY));

      const res = await fetch(`${API_BASE}/start`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
          property_key: config.propertyKey,
          visitor_token: vToken,
          name: nameInput.value.trim(),
          email: emailInput.value.trim(),
          department_id: (deptSelect && deptSelect.value) ? deptSelect.value : null,
          initial_message: message,
          _bot_trap: shadow.getElementById('jix-bot-trap')?.value || '',
        }),
      });

      const json = await res.json();
      if (json.success && json.data) {
        state.conversation = json.data.conversation;
        state.messages = json.data.conversation.messages || [json.data.message];
        render();
        setupWebSocket();
      } else {
        alert(json.message || 'Failed to start chat.');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Start Chat';
      }
    } catch (e) {
      console.error('[ChatBox] Start chat error:', e);
      alert('Unable to start conversation. Please check your internet connection and try again.');
      submitBtn.disabled = false;
      submitBtn.textContent = 'Start Chat';
    }
  }

  // Send Message
  async function handleSendMessage() {
    const content = chatInput.value.trim();
    if (!content || !state.conversation) return;

    chatInput.value = '';
    sendBtn.disabled = true;

    // Optimistic render
    const optimisticMsg = {
      id: 'temp_' + Date.now(),
      sender_type: 'visitor',
      content: content,
      created_at: new Date().toISOString(),
    };
    state.messages.push(optimisticMsg);
    appendMessageToDOM(optimisticMsg, false);

    try {
      const res = await fetch(`${API_BASE}/messages`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          property_key: config.propertyKey,
          visitor_token: state.visitor.token,
          conversation_id: state.conversation.id,
          content: content,
          _bot_trap: shadow.getElementById('jix-bot-trap')?.value || '',
        }),
      });
      const json = await res.json();
      if (json.success) {
        // Replace temp msg
        const idx = state.messages.findIndex(m => m.id === optimisticMsg.id);
        if (idx !== -1) state.messages[idx] = json.data;

        if (json.bot_reply && !state.messages.some(m => m.id === json.bot_reply.id)) {
          state.messages.push(json.bot_reply);
          appendMessageToDOM(json.bot_reply, true);
        }
      }
    } catch (err) {
      console.error('Failed to send message:', err);
    } finally {
      sendBtn.disabled = false;
      chatInput.focus();
    }
  }

  chatInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSendMessage();
    }
  });
  sendBtn.addEventListener('click', handleSendMessage);

  // File & Screenshot Upload Handlers
  async function handleSendAttachment(file) {
    if (!file || !state.conversation || !state.visitor) return;
    const formData = new FormData();
    formData.append('file', file);
    formData.append('property_key', config.propertyKey);
    formData.append('visitor_token', state.visitor.token);

    if (attachBtn) {
      attachBtn.disabled = true;
      attachBtn.textContent = '⏳';
    }

    try {
      const upRes = await fetch(`${API_BASE}/upload`, {
        method: 'POST',
        body: formData,
      });
      const upJson = await upRes.json();
      if (!upJson.success) {
        alert(upJson.message || 'File upload failed');
        return;
      }

      // Send message with attachment
      const res = await fetch(`${API_BASE}/messages`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          property_key: config.propertyKey,
          visitor_token: state.visitor.token,
          conversation_id: state.conversation.id,
          content: 'Sent an attachment: ' + upJson.file_name,
          attachment_url: upJson.url,
          message_type: upJson.message_type,
        }),
      });
      const json = await res.json();
      if (json.success) {
        state.messages.push(json.data);
        appendMessageToDOM(json.data, false);
      }
    } catch (err) {
      console.error('Failed to upload file:', err);
      alert('Error uploading file. Please try again.');
    } finally {
      if (attachBtn) {
        attachBtn.disabled = false;
        attachBtn.textContent = '📎';
      }
      if (fileInput) fileInput.value = '';
    }
  }

  attachBtn?.addEventListener('click', () => fileInput?.click());
  fileInput?.addEventListener('change', (e) => {
    if (e.target.files && e.target.files[0]) {
      handleSendAttachment(e.target.files[0]);
    }
  });

  // Request Transcript Handler
  async function handleRequestTranscript() {
    if (!state.conversation) return;
    const defaultEmail = state.visitor?.email || '';
    const email = prompt('Enter your email address to receive this chat transcript:', defaultEmail);
    if (!email || !email.includes('@')) return;

    try {
      const res = await fetch(`${API_BASE}/conversations/${state.conversation.id}/transcript`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          property_key: config.propertyKey,
          visitor_token: state.visitor.token,
          email: email,
        }),
      });
      const json = await res.json();
      alert(json.message || 'Transcript sent to your email!');
    } catch(e) {
      alert('Error sending transcript.');
    }
  }
  transcriptHeaderBtn?.addEventListener('click', handleRequestTranscript);

  // Debounced Typing Indicator
  let lastTypingTime = 0;
  chatInput.addEventListener('input', () => {
    const now = Date.now();
    if (now - lastTypingTime > 2500 && state.conversation) {
      lastTypingTime = now;
      fetch(`${API_BASE}/typing`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          conversation_id: state.conversation.id,
          visitor_token: state.visitor.token,
          is_typing: true,
        }),
      }).catch(() => {});
    }
  });

  // Real-time WebSocket / Lightweight Message Sync (Low Server Resource Usage)
  function setupWebSocket() {
    if (!state.conversation) return;

    if (!window.__JixChatPollInterval) {
      window.__JixChatPollInterval = setInterval(async () => {
        // Do NOT consume server CPU if chat window is closed or tab is hidden
        if (!state.conversation || !state.isOpen || document.hidden) return;

        try {
          const lastMsg = state.messages[state.messages.length - 1];
          const lastId = lastMsg && !lastMsg.id.startsWith('temp_') ? lastMsg.id : '';
          const url = `${API_BASE}/conversations/${state.conversation.id}/sync?visitor_token=${encodeURIComponent(state.visitor.token)}${lastId ? `&after_id=${encodeURIComponent(lastId)}` : ''}`;

          const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
          const json = await res.json();
          if (json.success && json.data) {
            if (json.data.assigned_agent) {
              state.conversation.assigned_agent = json.data.assigned_agent;
              updateHeaderAgentInfo(json.data.assigned_agent, json.data.started_at);
            }
            if (json.data.status) {
              state.conversation.status = json.data.status;
            }
            if (json.data.messages) {
              const newMsgs = json.data.messages;
              newMsgs.forEach(m => {
                if (!state.messages.some(existing => existing.id === m.id)) {
                  state.messages.push(m);
                  appendMessageToDOM(m, true);
                }
              });
            }
          }
        } catch (e) {
          // Silent catch to prevent console noise
        }
      }, 4000); // 4s responsive interval for active live chat
    }
  }

  // Initialize Widget
  async function init() {
    try {
      const res = await fetch(`${API_BASE}/init`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          property_key: config.propertyKey,
          visitor_token: visitorToken,
          origin: window.location.origin,
          current_page_url: window.location.href,
          browser: navigator.userAgent.includes('Chrome') ? 'Chrome' : 'Browser',
          os: navigator.platform || 'Unknown',
        }),
      });

      if (res.status === 403) {
        console.error('[JixChat] 403 Forbidden: Origin not whitelisted for this property.');
        container.remove();
        return;
      }

      const json = await res.json();
      if (!json.success) {
        console.warn('[ChatBox] Init response:', json.message);
        chatBody.innerHTML = `
          <div style="padding: 40px 20px; text-align: center; color: #64748b; font-size: 13px;">
            <div style="font-size: 26px; margin-bottom: 8px;">💬</div>
            <div style="font-weight: 700; color: #1e293b; margin-bottom: 6px;">Live Support</div>
            <div>${json.message || 'Connecting to support...'}</div>
          </div>
        `;
        return;
      }

      const data = json.data;
      state.visitor = data.visitor;
      state.property = data.property;
      state.departments = data.departments || [];
      state.faqs = data.faqs || [];
      state.broadcasting = data.broadcasting;

      // Cache visitor token
      if (data.visitor && data.visitor.token) {
        localStorage.setItem(STORAGE_KEY, data.visitor.token);
      }

      // Configure Theme Colors
      if (data.property.widget_config?.primary_color) {
        state.primaryColor = data.property.widget_config.primary_color;
        container.style.setProperty('--primary', state.primaryColor);
        bubbleBtn.style.background = state.primaryColor;
      }

      if (data.property.site_name) {
        headerTitleEl.textContent = data.property.site_name;
        shadow.getElementById('jix-avatar-text').textContent = data.property.site_name.substring(0, 2).toUpperCase();
      }

      // Check Operating Hours & Real-time Agent Status
      try {
        const stRes = await fetch(`${API_BASE}/status?property_key=${encodeURIComponent(config.propertyKey)}`);
        const stJson = await stRes.json();
        if (stJson.success) {
          state.isOnline = stJson.is_online;
          state.offlineNotice = stJson.offline_notice;
          const statusEl = shadow.getElementById('jix-header-status');
          if (statusEl) {
            statusEl.textContent = stJson.is_online 
              ? '🟢 Online • Typically replies instantly' 
              : '🌙 Away • Leave a message';
          }
        }
      } catch (e) {}

      if (data.conversation) {
        state.conversation = data.conversation;
        state.messages = data.conversation.messages || [];
        setupWebSocket();
      }

      render();
    } catch (err) {
      console.error('[JixChat] Initialization failed:', err);
      chatBody.innerHTML = `
        <div style="padding: 40px 20px; text-align: center; color: #64748b; font-size: 13px;">
          <div style="font-size: 26px; margin-bottom: 8px;">⚠️</div>
          <div style="font-weight: 700; color: #1e293b; margin-bottom: 6px;">Connection Error</div>
          <div>Please refresh the page to restart live chat.</div>
        </div>
      `;
    }
  }

  // Real-time Visitor Presence Heartbeat & Tab Leave Detector
  function setupPresenceTracking() {
    // 1. Heartbeat every 45s while visitor is on page
    setInterval(() => {
      const vToken = (state.visitor && state.visitor.token) || localStorage.getItem(STORAGE_KEY);
      if (!document.hidden && vToken && config.propertyKey) {
        fetch(`${API_BASE}/heartbeat`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ property_key: config.propertyKey, visitor_token: vToken })
        }).catch(() => {});
      }
    }, 45000);

    // 2. Tab close / page exit detector (sendBeacon to instantly mark left/offline)
    window.addEventListener('beforeunload', () => {
      const vToken = (state.visitor && state.visitor.token) || localStorage.getItem(STORAGE_KEY);
      if (vToken && config.propertyKey && navigator.sendBeacon) {
        const blob = new Blob([JSON.stringify({ property_key: config.propertyKey, visitor_token: vToken })], { type: 'application/json' });
        navigator.sendBeacon(`${API_BASE}/leave`, blob);
      }
    });
  }

  // Start initialization & presence tracking
  init();
  setupPresenceTracking();
})();
