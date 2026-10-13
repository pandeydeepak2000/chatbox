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
            <p id="jix-header-status">Online • Typically replies instantly</p>
          </div>
        </div>
        <div class="jix-header-actions">
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

  // Render Messages or Prechat Form
  function render() {
    chatBody.innerHTML = '';

    if (!state.conversation) {
      // Render Pre-Chat Form
      chatFooter.style.display = 'none';
      const welcome = state.property?.widget_config?.welcome_message || 'Hello! How can we assist you today?';
      
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
          <h4>Start a Conversation</h4>
          <p>${welcome}</p>
          <div class="jix-form-group">
            <label>Your Name</label>
            <input type="text" class="jix-input" id="jix-prechat-name" placeholder="John Doe" value="${state.visitor?.name || ''}" />
          </div>
          <div class="jix-form-group">
            <label>Email Address</label>
            <input type="email" class="jix-input" id="jix-prechat-email" placeholder="john@example.com" value="${state.visitor?.email || ''}" />
          </div>
          ${deptOptions}
          <div class="jix-form-group">
            <label>How can we help?</label>
            <textarea class="jix-textarea" id="jix-prechat-msg" rows="3" placeholder="Write your question here..."></textarea>
          </div>
          <!-- Anti-Bot Honeypot Trap (Invisible to humans, catches automated bots) -->
          <input type="text" id="jix-bot-trap" name="_bot_trap" style="position:absolute!important;opacity:0!important;pointer-events:none!important;left:-9999px!important;top:-9999px!important;" tabindex="-1" autocomplete="off" />
          <button class="jix-btn" id="jix-prechat-submit">Start Chat</button>
        </div>
      `;
      chatBody.innerHTML = formHtml;

      shadow.getElementById('jix-prechat-submit').addEventListener('click', handlePrechatSubmit);

      return;
    }

    // Has Active Conversation
    chatFooter.style.display = 'flex';

    state.messages.forEach(msg => {
      appendMessageToDOM(msg, false);
    });

    if (state.isAgentTyping) {
      appendTypingToDOM();
    }

    scrollToBottom();
  }

  function appendMessageToDOM(msg, playSound = false) {
    const isVisitor = msg.sender_type === 'visitor';
    const isBot = msg.sender_type === 'bot';
    const msgEl = document.createElement('div');
    msgEl.className = `jix-msg ${isVisitor ? 'visitor' : 'agent'}`;

    const time = new Date(msg.created_at || Date.now()).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const botBadge = isBot ? '<div style="font-size:10.5px; font-weight:700; color:#4f46e5; margin-bottom:5px; display:flex; align-items:center; gap:4px;">🤖 BOT ASSISTANT</div>' : '';

    // Convert newlines to <br> for clean formatting
    const formattedContent = (msg.content || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\n/g, '<br/>');

    msgEl.innerHTML = `
      <div class="jix-msg-bubble">${botBadge}${formattedContent}</div>
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

  // Real-time WebSocket / Polling Setup
  function setupWebSocket() {
    if (!state.conversation) return;

    // Fast fallback polling (every 3 seconds) ensuring 100% reliable message delivery even behind firewalls
    if (!window.__JixChatPollInterval) {
      window.__JixChatPollInterval = setInterval(async () => {
        if (!state.conversation) return;
        try {
          const res = await fetch(`${API_BASE}/init`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              property_key: config.propertyKey,
              visitor_token: state.visitor.token,
            }),
          });
          const json = await res.json();
          if (json.success && json.data.conversation) {
            const serverMsgs = json.data.conversation.messages || [];
            if (serverMsgs.length > state.messages.length) {
              const newMsgs = serverMsgs.slice(state.messages.length);
              state.messages = serverMsgs;
              newMsgs.forEach(m => appendMessageToDOM(m, true));
            }
          }
        } catch (e) {}
      }, 3000);
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

  // Start initialization
  init();
})();
