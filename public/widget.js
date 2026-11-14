/**
 * ChatBox website widget (cPanel friendly: AJAX polling, no WebSockets).
 *
 * Embed:   <script src="https://chat.example.com/widget.js" data-property-key="pk_live_..." async></script>
 *   or     window.ChatBoxConfig = { propertyKey: 'pk_live_...', apiUrl: 'https://chat.example.com' }
 *          (window.JixChatConfig is still accepted for older embeds)
 *
 * Security: rendered inside a Shadow DOM; every server/visitor value is inserted with textContent
 * (never innerHTML); attachment links must be http(s); the customer session token is sent only to
 * the ChatBox API in the X-Customer-Token header.
 */
(function () {
  'use strict';
  if (window.__ChatBoxWidgetLoaded) return;
  window.__ChatBoxWidgetLoaded = true;

  // ------------------------------------------------------------------ config
  var script = document.currentScript || document.querySelector('script[data-property-key]');
  var cfg = Object.assign({}, window.ChatBoxConfig || window.JixChatConfig || {});
  if (!cfg.propertyKey && script && script.dataset && script.dataset.propertyKey) cfg.propertyKey = script.dataset.propertyKey;
  if (!cfg.apiUrl && script && script.src) { try { cfg.apiUrl = new URL(script.src).origin; } catch (e) { /* ignore */ } }
  if (!cfg.propertyKey) { console.warn('[ChatBox] Missing propertyKey'); return; }

  var API = (cfg.apiUrl || window.location.origin).replace(/\/$/, '') + '/api/v1/widget';
  var KEY = cfg.propertyKey.slice(0, 24);
  var store = {
    get: function (k) { try { return localStorage.getItem('chatbox_' + k + '_' + KEY); } catch (e) { return null; } },
    set: function (k, v) { try { if (v) localStorage.setItem('chatbox_' + k + '_' + KEY, v); else localStorage.removeItem('chatbox_' + k + '_' + KEY); } catch (e) { /* storage blocked */ } },
  };

  var S = {
    open: false, tab: 'chat', screen: 'loading', property: null, visitor: null, customer: null,
    conversation: null, messages: [], lastSeq: 0, departments: [], faqs: [], identityMode: 'pin_optional',
    sessionTimeoutMinutes: 10, chosenDeptId: null, chosenDeptName: null,
    tickets: [], conversations: [], aiPending: false, handler: 'ai', openTicket: null, status: null,
    unread: 0, lastActivity: Date.now(), pollTimer: null, sending: false, error: '', notice: '', forgotEmail: '',
    idStep: 'email', idEmail: '', createPin: '', guestChosen: false,
    waitingSenior: false, seniorWaitRemaining: 0, seniorTimer: null,
  };

  function uid() { return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (Date.now() + '-' + Math.random().toString(16).slice(2)); }

  // ------------------------------------------------------------------ http
  function call(method, path, body, isForm) {
    var headers = { Accept: 'application/json' };
    var token = store.get('customer');
    if (token) headers['X-Customer-Token'] = token;
    var opts = { method: method, headers: headers };
    if (body && !isForm) { headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    if (isForm) opts.body = body;
    return fetch(API + path, opts).then(function (res) {
      if (res.status === 204) return { ok: true, status: 204, json: {} };
      return res.json().catch(function () { return {}; }).then(function (json) {
        if (res.status === 401 && json.code === 'customer_unauthenticated') { store.set('customer', null); S.customer = null; }
        var msg = json.message;
        if (json.errors) { var first = Object.values(json.errors)[0]; if (first && first[0]) msg = first[0]; }
        return { ok: res.ok && json.success !== false, status: res.status, json: json, message: msg || ('Request failed (' + res.status + ')') };
      });
    }).catch(function () { return { ok: false, status: 0, json: {}, message: 'Connection problem. Please check your internet and try again.' }; });
  }
  function qs(o) { return Object.keys(o).filter(function (k) { return o[k] !== undefined && o[k] !== null; }).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(o[k]); }).join('&'); }

  // ------------------------------------------------------------------ DOM helpers (text only)
  function h(tag, props) {
    var el = document.createElement(tag);
    var p = props || {};
    Object.keys(p).forEach(function (k) {
      if (k === 'text') el.textContent = p[k];
      else if (k === 'class') el.className = p[k];
      else if (k.indexOf('on') === 0 && typeof p[k] === 'function') el.addEventListener(k.slice(2), p[k]);
      else if (k === 'value') el.value = p[k];
      else if (p[k] !== undefined && p[k] !== null && p[k] !== false) el.setAttribute(k, p[k] === true ? '' : p[k]);
    });
    for (var i = 2; i < arguments.length; i++) append(el, arguments[i]);
    return el;
  }
  function append(el, c) {
    if (c === null || c === undefined || c === false) return;
    if (Array.isArray(c)) { c.forEach(function (x) { append(el, x); }); return; }
    el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
  }
  function safeUrl(u) { return /^https?:\/\//i.test(String(u || '')) ? String(u) : null; }
  function time(iso) { try { return new Date(iso || Date.now()).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); } catch (e) { return ''; } }

  // ------------------------------------------------------------------ host + styles
  var host = document.createElement('div');
  host.id = 'chatbox-widget-host';
  document.body.appendChild(host);
  var root = host.attachShadow({ mode: 'open' });
  var style = document.createElement('style');
  root.appendChild(style);
  function applyStyles(color, position) {
    var side = position === 'left' ? 'left' : 'right';
    style.textContent = [
      ':host{all:initial}',
      '*{box-sizing:border-box;font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans","Noto Sans Devanagari",sans-serif}',
      '.jix-bubble-btn{position:fixed;bottom:22px;' + side + ':22px;background:#0F1A16;color:#fff;box-shadow:0 0 0 2px #D5F26B,0 10px 30px rgba(15,26,22,.22);border-radius:999px;padding:12px 20px;font-weight:700;font-size:14.5px;display:flex;align-items:center;gap:10px;cursor:pointer;border:0;z-index:2147483000;transition:transform .15s ease}',
      '.jix-bubble-btn:hover{transform:translateY(-2px)}',
      '.jix-bubble-btn:focus-visible{outline:3px solid #D5F26B;outline-offset:3px}',
      '.ehc-dot{width:9px;height:9px;border-radius:50%;background:#7BE07B;box-shadow:0 0 0 3px rgba(123,224,123,.25);flex-shrink:0}',
      '.badge{position:absolute;top:-2px;right:-2px;background:#dc2626;color:#fff;border-radius:999px;font-size:11px;font-weight:700;padding:2px 6px}',
      '.panel{position:fixed;bottom:84px;' + side + ':22px;width:385px;max-width:calc(100vw - 24px);height:610px;max-height:calc(100vh - 100px);background:#fff;border-radius:20px;box-shadow:0 24px 70px rgba(15,26,22,.28);z-index:2147483000;display:flex;flex-direction:column;overflow:hidden;color:#0F1A16;font-size:14.5px;line-height:1.45}',
      '.head{background:#0E2A22;color:#fff;padding:14px 16px 0;display:flex;flex-direction:column;gap:10px}',
      '.head-row{display:flex;align-items:center;gap:10px}',
      '.head-avatar{width:36px;height:36px;border-radius:50%;background:#D5F26B;color:#0F1A16;font-weight:700;font-size:16px;display:flex;align-items:center;justify-content:center;flex-shrink:0}',
      '.head-title{font-weight:700;font-size:15px;color:#fff}',
      '.x{margin-left:auto;background:transparent;border:0;color:#fff;font-size:22px;cursor:pointer;line-height:1;padding:2px 8px;opacity:.85}',
      '.x:hover{opacity:1}',
      '.tabs{display:flex;gap:4px;margin-top:2px}',
      '.tab{flex:1;background:transparent;border:0;color:#B9CBC3;font-weight:600;font-size:13.5px;padding:10px 14px;border-radius:12px 12px 0 0;cursor:pointer;transition:all .15s ease}',
      '.tab.on{background:#fff;color:#0F1A16;font-weight:700}',
      '.body{flex:1;overflow-y:auto;padding:16px;background:#FAFBF8}',
      '.foot{border-top:1px solid #E1E4DC;padding:10px 14px;background:#fff}',
      '.msg{display:flex;margin-bottom:10px}',
      '.msg.me{justify-content:flex-end}',
      '.bub{max-width:85%;padding:10px 13px;border-radius:16px;background:#fff;border:1px solid #E1E4DC;white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.45;color:#0F1A16}',
      '.bot .bub{border-bottom-left-radius:5px}',
      '.me .bub{background:#0F1A16;color:#fff;border-color:#0F1A16;border-bottom-right-radius:5px}',
      '.staff .bub{background:#EEF8D0;border-color:#D5F26B;border-bottom-left-radius:5px}',
      '.lbl{font-size:11px;font-weight:700;color:#166534;margin-bottom:2px}',
      '.tm{font-size:10px;opacity:.65;margin-top:3px}',
      '.sys{background:#E3F4D3;font-size:12.5px;max-width:100%;text-align:center;border-radius:12px;padding:6px 12px;margin:8px 0;color:#0F1A16}',
      '.typing{display:inline-flex;gap:4px;padding:10px 14px;background:#fff;border:1px solid #E1E4DC;border-radius:16px;margin:4px 0 10px;align-items:center}',
      '.typing i{width:6px;height:6px;border-radius:50%;background:#9AA59F;display:inline-block;animation:ehcb 1s infinite}',
      '.typing i:nth-child(2){animation-delay:.15s}',
      '.typing i:nth-child(3){animation-delay:.3s}',
      '@keyframes ehcb{0%,100%{opacity:.3}50%{opacity:1}}',
      '.pill-row{display:flex;gap:8px;align-items:center;background:#fff;padding:10px 14px;border-top:1px solid #E1E4DC}',
      '.pill-input{border:1px solid #CDD3CA;border-radius:999px;padding:10px 16px;font-size:14px;background:#fff;color:#0F1A16;flex:1}',
      '.pill-input:focus{outline:none;border-color:#0F1A16}',
      '.pill-send{background:#D5F26B;color:#0F1A16;border-radius:999px;padding:10px 20px;font-weight:700;font-size:14px;border:0;cursor:pointer;flex-shrink:0}',
      '.pill-send:hover{background:#c6e65a}',
      '.pill-send:disabled{opacity:.5;cursor:not-allowed}',
      '.dept-chips{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 12px}',
      '.dept-chip{border:1.5px solid #0F1A16;border-radius:999px;background:#fff;color:#0F1A16;font-weight:600;font-size:13.5px;padding:8px 16px;cursor:pointer;transition:all .15s ease}',
      '.dept-chip:hover,.dept-chip.on{background:#D5F26B}',
      '.pin{display:flex;gap:8px;justify-content:center;margin:8px 0}',
      '.pin input{width:42px;height:50px;text-align:center;font-size:22px;font-weight:700;border:1.5px solid #CDD3CA;border-radius:12px;padding:0}',
      '.pin input:focus{outline:none;border-color:#0F1A16}',
      '.pin.bad input{border-color:#fca5a5;background:#fef2f2}',
      '.card{background:#fff;border:1px solid #E1E4DC;border-radius:14px;padding:14px;margin-bottom:10px}',
      '.card h3{margin:0 0 6px;font-size:15px}.muted{color:#64748b;font-size:12.5px}',
      '.field{margin-bottom:10px}.field label{display:block;font-size:12.5px;font-weight:600;margin-bottom:4px}.hint{font-size:12px;color:#64748b;margin-top:3px}',
      '.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:8px 10px;border-radius:8px;font-size:13px;margin-bottom:10px}',
      '.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;padding:8px 10px;border-radius:8px;font-size:13px;margin-bottom:10px}',
      '.banner{background:#fff7ed;color:#9a3412;border-bottom:1px solid #fed7aa;padding:8px 14px;font-size:12.5px}',
      '.item{display:block;width:100%;text-align:left;background:#fff;border:1px solid #E1E4DC;border-radius:10px;padding:10px 12px;margin-bottom:8px;cursor:pointer;color:#0F1A16;font-size:13.5px}',
      '.item:hover{border-color:#0F1A16}.st{display:inline-block;font-size:11px;font-weight:700;border-radius:999px;padding:1px 8px;background:#eff6ff;color:#1d4ed8;margin-left:6px}',
      '.att{display:block;margin-top:6px;color:inherit;font-weight:600}.att img{max-width:220px;max-height:160px;border-radius:8px;display:block}',
      '.tools{display:flex;gap:8px;margin-bottom:6px;align-items:center}',
      '.stars button{background:none;border:0;font-size:24px;cursor:pointer;color:#f59e0b}',
      '.faq summary{cursor:pointer;font-weight:600;padding:6px 0}.faq p{margin:4px 0 8px;white-space:pre-wrap}',
      '.link{background:none;border:0;color:#0F1A16;font-weight:600;cursor:pointer;padding:4px 0;font-size:13px;text-decoration:underline}',
      '.btn{border:0;border-radius:999px;padding:10px 18px;font-weight:700;cursor:pointer;background:#D5F26B;color:#0F1A16;font-size:14px}',
      '.btn:disabled{opacity:.6;cursor:not-allowed}.btn.ghost{background:#fff;color:#0F1A16;border:1.5px solid #CDD3CA}.btn.block{width:100%}',
      '.btn.sm{padding:6px 12px;font-size:12.5px}',
      '.idcard{background:#fff;border:1px solid #E1E4DC;border-radius:14px;padding:14px;margin:4px 0 10px}',
      '.idcard h4{margin:0;font-size:14.5px}',
      '.choice{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-top:6px}',
      '.hidden{display:none!important}',
      '@media (max-width:480px){.panel{inset:0;width:100%;max-width:100%;height:100%;max-height:100%;border-radius:0;bottom:0}}',
    ].join('');
  }
  applyStyles('#0F1A16', 'right');

  var launcher = h('button', { class: 'jix-bubble-btn', 'aria-label': 'Open live chat', onclick: function () { toggle(!S.open); } });
  launcher.innerHTML = '<span class="ehc-dot"></span><span>Live chat</span>';
  var badgeEl = h('span', { class: 'badge hidden' });
  launcher.appendChild(badgeEl);
  var panel = h('div', { class: 'panel hidden', role: 'dialog', 'aria-label': 'Live chat' });
  root.appendChild(launcher);
  root.appendChild(panel);

  // ------------------------------------------------------------------ sound (new replies)
  function ding() {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      var ctx = new Ctx(); var o = ctx.createOscillator(); var g = ctx.createGain();
      o.frequency.value = 880; g.gain.value = 0.05; o.connect(g); g.connect(ctx.destination);
      o.start(); o.stop(ctx.currentTime + 0.15);
    } catch (e) { /* audio not allowed */ }
  }

  function touchActivity() {
    S.lastActivity = Date.now();
    store.set('last_act', String(S.lastActivity));
  }

  function broadcastTabSync() {
    store.set('sync', String(Date.now()));
  }

  function checkSessionTimeout() {
    if (!S.sessionTimeoutMinutes || S.sessionTimeoutMinutes <= 0 || !S.customer) return false;
    var last = Number(store.get('last_act') || 0);
    if (!last) { touchActivity(); return false; }
    if (Date.now() - last > S.sessionTimeoutMinutes * 60000) {
      store.set('customer', null);
      S.customer = null; S.tickets = []; S.conversations = [];
      S.conversation = null; S.messages = [];
      S.idStep = 'email'; S.screen = S.identityMode !== 'guest_only' ? 'identify' : 'start';
      S.notice = 'Your chat session timed out due to inactivity.';
      clearTimeout(S.pollTimer);
      render();
      return true;
    }
    return false;
  }

  // ------------------------------------------------------------------ boot
  function init() {
    call('POST', '/init', { property_key: cfg.propertyKey, visitor_token: store.get('vtok'), current_page_url: location.origin + location.pathname, browser: navigator.userAgent.slice(0, 90), os: (navigator.platform || '').slice(0, 90) }).then(function (r) {
      if (!r.ok) { if (r.status === 403) host.remove(); else console.warn('[ChatBox]', r.message); return; }
      var d = r.json.data;
      store.set('vtok', d.visitor.token);
      S.visitor = d.visitor; S.property = d.property; S.customer = d.customer;
      S.identityMode = d.property.identity_mode || 'guest_only';
      S.sessionTimeoutMinutes = Number(d.property.session_timeout_minutes ?? 30);
      S.departments = d.departments || []; S.faqs = d.faqs || [];
      var wc = d.property.widget_config || {};
      applyStyles(/^#[0-9a-f]{3,8}$/i.test(wc.primary_color || '') ? wc.primary_color : '#2563eb', wc.position);
      if (d.conversation) loadConversation(d.conversation);
      else { S.conversation = null; S.messages = []; }
      call('GET', '/status?' + qs({ property_key: cfg.propertyKey })).then(function (s) { if (s.ok) S.status = s.json; render(); });
      if (!checkSessionTimeout()) {
        if (S.customer) { touchActivity(); refreshCustomer(); }
        S.screen = S.conversation ? 'chat' : 'start';
        render();
        schedulePoll();
      }
    });
  }

  function loadConversation(c) {
    S.conversation = { id: c.id, status: c.status };
    S.messages = c.messages || [];
    S.lastSeq = S.messages.reduce(function (m, x) { return Math.max(m, x.seq || 0); }, c.last_seq || 0);
    S.handler = c.handler || 'ai'; S.openTicket = c.open_ticket || null; S.aiPending = !!c.ai_pending;
    S.waitingSenior = !!c.waiting_senior;
    S.seniorWaitRemaining = typeof c.senior_wait_remaining === 'number' ? c.senior_wait_remaining : 0;
    startSeniorTimer();
  }

  function startSeniorTimer() {
    clearInterval(S.seniorTimer);
    if (!S.waitingSenior || S.seniorWaitRemaining <= 0) return;
    S.seniorTimer = setInterval(function () {
      if (S.seniorWaitRemaining > 0) {
        S.seniorWaitRemaining--;
        var el = root ? root.getElementById('ehc-senior-timer') : null;
        if (el) {
          el.textContent = '0:' + (S.seniorWaitRemaining < 10 ? '0' : '') + S.seniorWaitRemaining;
        }
      }
      if (S.seniorWaitRemaining <= 0) {
        clearInterval(S.seniorTimer);
        S.waitingSenior = false;
        poll();
      }
    }, 1000);
  }

  function refreshCustomer() {
    return call('GET', '/customer/me?' + qs({ property_key: cfg.propertyKey })).then(function (r) {
      if (r.ok) { S.customer = r.json.data.customer; S.tickets = r.json.data.tickets || []; S.conversations = r.json.data.conversations || []; }
      else if (r.status === 401) { store.set('customer', null); S.customer = null; S.tickets = []; S.conversations = []; S.idStep = 'email'; } // PIN changed or signed out elsewhere
      render();
    });
  }

  // ------------------------------------------------------------------ polling (adaptive, cheap when idle)
  function schedulePoll() {
    clearTimeout(S.pollTimer);
    if (!S.conversation) return;
    var idle = Date.now() - S.lastActivity > 60000;
    var delay = document.hidden ? 30000 : (!S.open ? 20000 : ((S.aiPending || S.waitingSenior) ? 2500 : (idle ? 10000 : 3500)));
    S.pollTimer = setTimeout(poll, delay);
  }
  function poll() {
    if (!S.conversation) return;
    clearTimeout(S.pollTimer);
    call('GET', '/conversations/' + encodeURIComponent(S.conversation.id) + '/sync?' + qs({ visitor_token: S.visitor.token, after_seq: S.lastSeq })).then(function (r) {
      if (r.ok && r.json.data) {
        var d = r.json.data; var fresh = false; var changed = false;
        (d.messages || []).forEach(function (m) {
          if (m.seq > S.lastSeq) S.lastSeq = m.seq;
          if (!S.messages.some(function (x) { return x.id === m.id; })) {
            S.messages = S.messages.filter(function (x) { return !(x.pending && x.client_message_id && x.client_message_id === m.client_message_id); });
            S.messages.push(m); changed = true;
            if (m.sender_type === 'agent' || m.sender_type === 'bot') fresh = true;
          }
        });
        if (d.waiting_senior !== undefined) {
          if (S.waitingSenior !== !!d.waiting_senior) {
            S.waitingSenior = !!d.waiting_senior;
            changed = true;
          }
        }
        if (typeof d.senior_wait_remaining === 'number' && d.senior_wait_remaining > 0) {
          if (Math.abs(S.seniorWaitRemaining - d.senior_wait_remaining) > 3) {
            S.seniorWaitRemaining = d.senior_wait_remaining;
          }
          startSeniorTimer();
        } else if (!d.waiting_senior) {
          S.seniorWaitRemaining = 0;
          clearInterval(S.seniorTimer);
        }
        var sig = JSON.stringify([!!d.ai_pending, d.handler, d.open_ticket, d.status, !!d.waiting_senior]);
        if (sig !== S.syncSig) { S.syncSig = sig; changed = true; }
        S.aiPending = !!d.ai_pending; S.handler = d.handler; S.openTicket = d.open_ticket; S.conversation.status = d.status;
        if (fresh) { if (!S.open) S.unread++; ding(); }
        if (changed) render();
      } else if (r.status === 404) {
        S.conversation = null; S.screen = 'start'; if (S.customer) refreshCustomer(); render(); return;
      }
      schedulePoll();
    });
  }
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      if (!checkSessionTimeout() && S.conversation) poll();
    }
  });

  // Cross-tab session sync: when the visitor signs in or chats in one tab, enable it in all open tabs.
  window.addEventListener('storage', function (e) {
    if (!e || !e.key || e.key.indexOf('chatbox_') !== 0 || e.key.indexOf('_' + KEY) === -1) return;
    if (e.key === 'chatbox_customer_' + KEY || e.key === 'chatbox_sync_' + KEY) {
      init();
    }
  });

  // ------------------------------------------------------------------ actions
  function toggle(open) {
    S.open = open;
    if (open) { S.unread = 0; touchActivity(); checkSessionTimeout(); }
    render();
    if (open && S.conversation) poll();
  }

  function startChat(text, extra) {
    S.sending = true; S.error = ''; render();
    call('POST', '/start', Object.assign({ property_key: cfg.propertyKey, visitor_token: S.visitor.token, initial_message: text, client_message_id: uid(), _bot_trap: '' }, extra || {})).then(function (r) {
      S.sending = false;
      if (!r.ok) {
        if (r.json && r.json.code === 'customer_unauthenticated') S.screen = 'signin';
        S.error = r.message; render(); return;
      }
      loadConversation(r.json.data.conversation);
      S.screen = 'chat'; S.tab = 'chat'; touchActivity(); broadcastTabSync();
      render(); poll();
    });
  }

  function sendMessage(text, attachmentId) {
    var cid = uid();
    var temp = { id: 'tmp-' + cid, client_message_id: cid, sender_type: 'visitor', content: text || 'Sending attachment…', pending: true, created_at: new Date().toISOString() };
    S.messages.push(temp); touchActivity(); render();
    var body = { property_key: cfg.propertyKey, visitor_token: S.visitor.token, conversation_id: S.conversation.id, content: text || null, attachment_id: attachmentId || null, client_message_id: cid, _bot_trap: '' };
    var attempt = function (n) {
      call('POST', '/messages', body).then(function (r) {
        if (r.ok) {
          var m = r.json.data;
          S.messages = S.messages.filter(function (x) { return x.id !== temp.id && x.id !== m.id; });
          S.messages.push(m);
          if (m.seq > S.lastSeq) S.lastSeq = m.seq;
          if (r.json.bot_reply) { S.messages.push(r.json.bot_reply); S.lastSeq = Math.max(S.lastSeq, r.json.bot_reply.seq || 0); }
          render(); poll();
        } else if (r.status === 0 && n < 2) {
          setTimeout(function () { attempt(n + 1); }, 1500 * (n + 1)); // same client_message_id: the server never duplicates
        } else {
          temp.failed = true; temp.pending = false; S.error = r.message; render();
        }
      });
    };
    attempt(0);
  }

  function talkToHuman(btn) {
    if (!S.conversation) return;
    var key = store.get('esc_' + S.conversation.id) || uid();
    store.set('esc_' + S.conversation.id, key);
    btn.disabled = true; btn.textContent = 'Contacting the team…';
    call('POST', '/conversations/' + encodeURIComponent(S.conversation.id) + '/escalate', { visitor_token: S.visitor.token, idempotency_key: key }).then(function (r) {
      if (r.ok) {
        var dd = r.json.data;
        S.notice = dd.mode === 'live' ? 'A team member has been asked to join this chat.' : ('Ticket ' + (dd.ticket ? dd.ticket.reference : '') + ' is open — our team will reply here.');
        S.error = '';
        if (S.customer) refreshCustomer();
      } else {
        S.error = r.message + ' Tap “Talk to a human” to try again.';
      }
      poll(); render();
    });
  }

  function upload(file) {
    var fd = new FormData();
    fd.append('property_key', cfg.propertyKey); fd.append('visitor_token', S.visitor.token); fd.append('conversation_id', S.conversation ? S.conversation.id : ''); fd.append('file', file);
    S.notice = 'Uploading…'; render();
    call('POST', '/upload', fd, true).then(function (r) {
      S.notice = '';
      if (!r.ok) { S.error = r.message; render(); return; }
      sendMessage('', r.json.attachment_id);
    });
  }

  function customerAuth(kind, form) {
    S.error = ''; S.notice = ''; S.sending = true; render();
    var body = Object.assign({ property_key: cfg.propertyKey, visitor_token: S.visitor.token }, form);
    var path = { signup: '/customer/signup', login: '/customer/login', forgot: '/customer/forgot-pin', reset: '/customer/reset-pin' }[kind];
    call('POST', path, body).then(function (r) {
      S.sending = false;
      if (!r.ok) {
        if (kind === 'signup' && r.json && r.json.code === 'account_exists') { S.idStep = 'enter'; S.screen = 'identify'; S.error = 'This e-mail already has a Support PIN. Please enter it.'; S.clearPin = true; render(); S.clearPin = false; return; }
        S.error = r.message;
        if (kind === 'signup' || kind === 'login') S.clearPin = true;
        render(); S.clearPin = false; return;
      }
      if (kind === 'forgot') { S.forgotEmail = form.email; S.notice = r.json.message; S.screen = 'reset'; render(); return; }
      store.set('customer', r.json.data.token);
      touchActivity(); broadcastTabSync();
      S.customer = r.json.data.customer; S.tickets = r.json.data.tickets || []; S.conversations = r.json.data.conversations || [];
      S.notice = kind === 'reset' ? r.json.message : '';
      var open = S.conversations.filter(function (c) { return c.status !== 'closed'; })[0];
      if (S.conversation) { S.screen = 'chat'; render(); poll(); } else if (open) { openConversation(open.id); } else { S.screen = 'start'; render(); }
    });
  }

  function openConversation(id) {
    call('GET', '/customer/conversations/' + encodeURIComponent(id) + '?' + qs({ property_key: cfg.propertyKey })).then(function (r) {
      if (!r.ok) { S.error = r.message; render(); return; }
      var c = r.json.data.conversation;
      loadConversation({ id: c.id, status: c.status, handler: c.handler, messages: r.json.data.messages, last_seq: r.json.data.last_seq });
      S.screen = 'chat'; S.tab = 'chat'; render(); poll();
    });
  }

  function logout() {
    call('POST', '/customer/logout', {}).then(function () {
      store.set('customer', null); store.set('last_act', null); broadcastTabSync();
      S.customer = null; S.tickets = []; S.conversations = [];
      S.conversation = null; S.messages = []; S.screen = 'start'; S.idStep = 'email'; S.idEmail = ''; render();
    });
  }

  // ------------------------------------------------------------------ render
  function render() {
    badgeEl.textContent = S.unread > 9 ? '9+' : String(S.unread);
    badgeEl.classList.toggle('hidden', !S.unread || S.open);
    panel.classList.toggle('hidden', !S.open);
    launcher.setAttribute('aria-expanded', S.open ? 'true' : 'false');
    if (!S.open) return;

    var active = root.activeElement;
    var focusedName = active && active.getAttribute('aria-label');
    var caret = active && typeof active.selectionStart === 'number' ? [active.selectionStart, active.selectionEnd] : null;
    var viewKey = S.tab + '|' + S.screen;
    var typed = {};
    if (viewKey === S.lastViewKey) {
      panel.querySelectorAll('input[aria-label], textarea[aria-label]').forEach(function (el) { if (el.type !== 'file' && el.value) typed[el.getAttribute('aria-label')] = el.value; });
    }
    var oldScroller = panel.querySelector('.scroll');
    var stickBottom = !oldScroller || viewKey !== S.lastViewKey || oldScroller.scrollHeight - oldScroller.scrollTop - oldScroller.clientHeight < 60;
    var oldTop = oldScroller ? oldScroller.scrollTop : 0;
    S.lastViewKey = viewKey;
    var brand = (S.property && ((S.property.widget_config || {}).brand_name || S.property.site_name)) || 'Support';
    var initial = (brand || 'S').trim().charAt(0).toUpperCase();
    panel.replaceChildren(
      h('div', { class: 'head' },
        h('div', { class: 'head-row' },
          h('div', { class: 'head-avatar', text: initial }),
          h('div', { style: 'flex:1;min-width:0' },
            h('div', { class: 'head-title', text: brand })),
          h('button', { class: 'x', 'aria-label': 'Close chat', onclick: function () { toggle(false); } }, '✕')),
        h('div', { class: 'tabs', role: 'tablist' },
          h('button', { class: 'tab' + (S.tab === 'chat' ? ' on' : ''), role: 'tab', 'aria-selected': S.tab === 'chat' ? 'true' : 'false', onclick: function () { S.tab = 'chat'; render(); } }, 'Chat'),
          h('button', { class: 'tab' + (S.tab === 'tickets' ? ' on' : ''), role: 'tab', 'aria-selected': S.tab === 'tickets' ? 'true' : 'false', onclick: function () { S.tab = 'tickets'; if (S.customer) refreshCustomer(); render(); } }, 'Tickets'))),
      S.tab === 'tickets' ? ticketsView() : chatTab()
    );
    var scroller = panel.querySelector('.scroll');
    if (scroller) scroller.scrollTop = stickBottom ? scroller.scrollHeight : oldTop;
    // Keep what the visitor is typing (any field) across polling re-renders of the same screen.
    panel.querySelectorAll('input[aria-label], textarea[aria-label]').forEach(function (el) {
      var name = el.getAttribute('aria-label');
      if (S.clearPin && /^PIN digit/.test(name)) return; // a wrong PIN starts again with empty boxes
      var v = typed[name];
      if (v && !el.value) el.value = v;
    });
    if (focusedName) {
      var again = panel.querySelector('[aria-label="' + focusedName.replace(/"/g, '') + '"]');
      if (again) { again.focus(); if (caret && typeof again.setSelectionRange === 'function') { try { again.setSelectionRange(caret[0], caret[1]); } catch (e) { /* type without selection */ } } }
    }
  }

  function alerts() {
    return [S.error ? h('div', { class: 'err', role: 'alert', text: S.error }) : null, S.notice ? h('div', { class: 'ok', text: S.notice }) : null];
  }

  function chatTab() {
    if (S.screen === 'loading') return h('div', { class: 'body' }, h('p', { class: 'muted', text: 'Loading…' }));
    if (S.screen === 'signin' || S.screen === 'signup' || S.screen === 'identify') return identifyView();
    if (S.screen === 'forgot') return h('div', { class: 'body' }, alerts(), forgotForm());
    if (S.screen === 'reset') return h('div', { class: 'body' }, alerts(), resetForm());
    if (S.screen === 'chat' && S.conversation) return chatView();
    if (!S.customer && S.identityMode !== 'guest_only' && !S.guestChosen) return identifyView();
    return startView();
  }

  // ------------------------------------------------------------------ chat-style sign-in (email → PIN)
  function botSay(text) { return h('div', { class: 'msg bot' }, h('div', { class: 'bub', text: text })); }
  function meSay(text) { return h('div', { class: 'msg me' }, h('div', { class: 'bub', text: text })); }

  function pinBoxes(onComplete) {
    var boxes = [];
    var wrap = h('div', { class: 'pin' + (S.error ? ' bad' : ''), role: 'group', 'aria-label': 'Chat PIN' });
    function value() { return boxes.map(function (b) { return b.value; }).join(''); }
    function check() { var v = value(); if (/^[0-9]{6}$/.test(v)) onComplete(v); }
    for (var i = 0; i < 6; i++) {
      (function (i) {
        var b = h('input', { type: 'password', inputmode: 'numeric', autocomplete: i === 0 ? 'one-time-code' : 'off', maxlength: '1', 'aria-label': 'PIN digit ' + (i + 1) });
        b.addEventListener('input', function () {
          var d = b.value.replace(/[^0-9]/g, '');
          b.value = d.slice(-1);
          if (b.value && boxes[i + 1]) boxes[i + 1].focus();
          check();
        });
        b.addEventListener('keydown', function (e) {
          if (e.key === 'Backspace' && !b.value && boxes[i - 1]) { boxes[i - 1].focus(); boxes[i - 1].value = ''; }
        });
        b.addEventListener('paste', function (e) {
          var t = ((e.clipboardData || window.clipboardData).getData('text') || '').replace(/[^0-9]/g, '').slice(0, 6);
          if (!t) return;
          e.preventDefault();
          t.split('').forEach(function (c, k) { if (boxes[k]) boxes[k].value = c; });
          (boxes[t.length] || boxes[5]).focus();
          check();
        });
        boxes.push(b);
        wrap.appendChild(b);
      })(i);
    }
    setTimeout(function () { var first = boxes.filter(function (b) { return !b.value; })[0]; if (first && root.activeElement !== first) first.focus(); }, 0);
    return wrap;
  }

  function identifyView() {
    var welcome = ((S.property || {}).widget_config || {}).welcome_message || 'Namaste! 👋 How can we help you today?';
    var body = h('div', { class: 'body scroll', 'aria-live': 'polite' });
    body.appendChild(botSay(welcome));

    if (S.departments && S.departments.length > 0) {
      var chipWrap = h('div', { class: 'dept-chips' });
      S.departments.forEach(function (d) {
        var on = S.chosenDeptId === d.id;
        chipWrap.appendChild(h('button', {
          type: 'button',
          class: 'dept-chip' + (on ? ' on' : ''),
          onclick: function () {
            S.chosenDeptId = d.id;
            S.chosenDeptName = d.name;
            render();
          }
        }, d.name));
      });
      body.appendChild(chipWrap);
    }

    if (S.chosenDeptName) {
      body.appendChild(meSay(S.chosenDeptName));
    }

    body.appendChild(botSay('Please share your email address.'));
    var foot = null;

    if (S.idStep === 'email' || !S.idEmail) {
      var email = h('input', {
        type: 'email',
        required: true,
        placeholder: 'you@example.com',
        'aria-label': 'E-mail',
        maxlength: '255',
        autocomplete: 'email',
        class: 'pill-input',
        value: S.idEmail || ''
      });
      var sendBtn = h('button', { class: 'pill-send', type: 'submit', disabled: S.sending }, S.sending ? '…' : 'Send');
      foot = h('form', { class: 'pill-row', onsubmit: function (e) {
        e.preventDefault();
        var v = email.value.trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) { S.error = 'Please enter a valid email address.'; render(); return; }
        S.sending = true; S.error = ''; render();
        call('POST', '/customer/identify', { property_key: cfg.propertyKey, email: v }).then(function (r) {
          S.sending = false;
          if (!r.ok) { S.error = r.message; render(); return; }
          S.idEmail = v;
          S.idStep = r.json.data.exists ? 'enter' : 'create';
          if (r.json.data.locked) S.error = 'Too many wrong PINs. Please wait a little, or use "Forgot PIN".';
          render();
        });
      } }, email, sendBtn);
    } else {
      body.appendChild(meSay(S.idEmail));
      var isCreate = S.idStep === 'create';
      var isConfirm = S.idStep === 'confirm';
      var isEnter = S.idStep === 'enter';

      var promptMsg = isEnter
        ? 'Welcome back! Enter your 6-digit Chat PIN to continue where you left off.'
        : (isConfirm
          ? 'Please re-enter your 6-digit Chat PIN to confirm.'
          : 'Set a 6-digit Chat PIN. Next time, your email + this PIN opens all your chats and tickets.');
      body.appendChild(botSay(promptMsg));

      var cardTitle = isEnter ? 'Enter Chat PIN' : (isConfirm ? 'Confirm Chat PIN' : 'Create Chat PIN');
      var hintMsg = isEnter
        ? (S.sending ? 'Checking…' : '6 digits, numbers only.')
        : (isConfirm ? 'Re-enter the same 6 digits.' : '6 digits, numbers only. Keep it safe.');

      var onPinComplete = function (pin) {
        if (isEnter) {
          customerAuth('login', { email: S.idEmail, pin: pin });
        } else if (isCreate) {
          S.createPin = pin;
          S.idStep = 'confirm';
          S.error = '';
          render();
        } else if (isConfirm) {
          if (pin === S.createPin) {
            customerAuth('signup', { email: S.idEmail, pin: pin });
          } else {
            S.createPin = '';
            S.idStep = 'create';
            S.error = 'PINs do not match. Please enter your PIN again.';
            render();
          }
        }
      };

      var choices = [];
      if (isConfirm) {
        choices.push(h('button', { class: 'link', type: 'button', onclick: function () { S.idStep = 'create'; S.createPin = ''; S.error = ''; render(); } }, 'Re-enter PIN'));
      }
      if (isEnter) {
        choices.push(h('button', { class: 'link', type: 'button', onclick: function () { S.screen = 'forgot'; S.forgotEmail = S.idEmail; S.error = ''; render(); } }, 'Forgot PIN?'));
        choices.push(h('button', { class: 'link', type: 'button', onclick: function () { S.idStep = 'create'; S.createPin = ''; S.error = ''; render(); } }, 'Create a new PIN'));
      }
      if (isCreate) {
        choices.push(h('button', { class: 'link', type: 'button', onclick: function () { S.idStep = 'enter'; S.error = ''; render(); } }, 'Already have a PIN? Enter it'));
        choices.push(h('button', { class: 'link', type: 'button', onclick: function () { S.screen = 'forgot'; S.forgotEmail = S.idEmail; S.error = ''; render(); } }, 'Forgot PIN?'));
      }
      choices.push(h('button', { class: 'link', type: 'button', onclick: function () { S.idStep = 'email'; S.createPin = ''; S.error = ''; render(); } }, 'Use a different email'));

      body.appendChild(h('div', { class: 'idcard' },
        h('h4', { text: cardTitle }),
        pinBoxes(onPinComplete),
        h('div', { class: 'hint', text: hintMsg }),
        h('div', { class: 'choice' }, choices)));
      foot = h('div', { class: 'foot' }, alerts());
    }
    return h('div', { style: 'display:flex;flex-direction:column;flex:1;min-height:0' }, alerts(), body, foot);
  }

  function startView() {
    if (!S.customer && S.identityMode !== 'guest_only' && !S.guestChosen) {
      return identifyView();
    }
    var welcome = ((S.property || {}).widget_config || {}).welcome_message || 'Namaste! 👋 How can we help you today?';
    var body = h('div', { class: 'body scroll', 'aria-live': 'polite' });
    body.appendChild(botSay(welcome));

    if (S.departments && S.departments.length > 0) {
      var chipWrap = h('div', { class: 'dept-chips' });
      S.departments.forEach(function (d) {
        var on = S.chosenDeptId === d.id;
        chipWrap.appendChild(h('button', {
          type: 'button',
          class: 'dept-chip' + (on ? ' on' : ''),
          onclick: function () {
            S.chosenDeptId = d.id;
            S.chosenDeptName = d.name;
            render();
          }
        }, d.name));
      });
      body.appendChild(chipWrap);
    }
    if (S.chosenDeptName) {
      body.appendChild(meSay(S.chosenDeptName));
    }

    if (S.conversations && S.conversations.length > 0) {
      body.appendChild(h('div', { class: 'card' },
        h('h3', { text: 'Your previous chats' }),
        S.conversations.slice(0, 3).map(function (c) {
          return h('button', { class: 'item', onclick: function () { openConversation(c.id); } },
            h('div', { text: c.preview || 'Conversation' }),
            h('div', { class: 'muted', text: new Date(c.last_message_at || c.created_at).toLocaleString() }));
        })));
    }

    var msgInput = h('input', {
      type: 'text',
      required: true,
      placeholder: 'Type your message…',
      'aria-label': 'Your message',
      class: 'pill-input'
    });
    var sendBtn = h('button', { class: 'pill-send', type: 'submit', disabled: S.sending }, S.sending ? '…' : 'Send');
    var foot = h('form', { class: 'pill-row', onsubmit: function (e) {
      e.preventDefault();
      var t = msgInput.value.trim();
      if (!t) return;
      msgInput.value = '';
      startChat(t, { email: S.customer ? S.customer.email : (S.idEmail || undefined), department_id: S.chosenDeptId || undefined });
    } }, msgInput, sendBtn);

    var userBar = S.customer ? h('div', { style: 'display:flex;justify-content:space-between;align-items:center;padding:6px 14px;background:#f1f5f9;font-size:12px;color:#64748b;border-top:1px solid #E1E4DC;' },
      h('span', { text: 'Signed in as ' + S.customer.email }),
      h('button', { class: 'link', type: 'button', onclick: logout }, 'Sign out')) : null;

    return h('div', { style: 'display:flex;flex-direction:column;flex:1;min-height:0' }, alerts(), body, userBar, foot);
  }

  function pinInput(placeholder) {
    return h('input', { type: 'password', inputmode: 'numeric', pattern: '[0-9]{6}', maxlength: '6', minlength: '6', autocomplete: 'off', placeholder: placeholder, 'aria-label': placeholder, required: true });
  }
  function backLink() { return h('button', { class: 'link', type: 'button', onclick: function () { S.screen = S.conversation ? 'chat' : (S.customer ? 'start' : 'identify'); S.error = ''; S.notice = ''; render(); } }, '← Back'); }

  function forgotForm() {
    var email = h('input', { type: 'email', required: true, placeholder: 'you@example.com', 'aria-label': 'E-mail', maxlength: '255', value: S.forgotEmail || S.idEmail || '' });
    return h('form', { class: 'card', onsubmit: function (e) { e.preventDefault(); customerAuth('forgot', { email: email.value.trim() }); } },
      h('h3', { text: 'Forgot your PIN?' }), h('p', { class: 'muted', text: 'We will email you a 6-digit code. It expires in 10 minutes and works once.' }),
      h('div', { class: 'field' }, email), h('button', { class: 'btn block', type: 'submit', disabled: S.sending }, 'Send code'), backLink());
  }
  function resetForm() {
    var code = h('input', { inputmode: 'numeric', pattern: '[0-9]{6}', maxlength: '6', required: true, placeholder: '6-digit code from email', 'aria-label': 'Code', autocomplete: 'one-time-code' });
    var pin = pinInput('Enter your new PIN');
    return h('form', { class: 'card', onsubmit: function (e) { e.preventDefault(); customerAuth('reset', { email: S.forgotEmail, code: code.value.trim(), pin: pin.value }); } },
      h('h3', { text: 'Set a new PIN' }),
      h('div', { class: 'field' }, h('label', { text: 'Code' }), code),
      h('div', { class: 'field' }, h('label', { text: 'New Chat PIN' }), pin, h('div', { class: 'hint', text: '6 digits, numbers only.' })),
      h('button', { class: 'btn block', type: 'submit', disabled: S.sending }, 'Save new PIN'),
      h('div', null, h('button', { class: 'link', type: 'button', onclick: function () { S.screen = 'forgot'; render(); } }, 'Send a new code'), ' · ', backLink()));
  }

  function chatView() {
    var body = h('div', { class: 'body scroll', 'aria-live': 'polite' });
    S.messages.forEach(function (m) {
      if (m.sender_type === 'system') { body.appendChild(h('div', { class: 'sys', text: m.content })); return; }
      var mine = m.sender_type === 'visitor';
      var isStaff = m.sender_type === 'agent';
      // Bot NEVER has a label (no "BOT ASSISTANT", no "Assistant") — clean like EnergyHost!
      var label = isStaff ? 'Senior team' : null;
      var url = safeUrl(m.attachment_url);
      var att = url ? (m.message_type === 'image'
        ? h('a', { class: 'att', href: url, target: '_blank', rel: 'noopener noreferrer' }, h('img', { src: url, alt: 'Attachment' }))
        : h('a', { class: 'att', href: url, target: '_blank', rel: 'noopener noreferrer', text: 'Download attachment' })) : null;
      body.appendChild(h('div', { class: 'msg' + (mine ? ' me' : '') + (m.sender_type === 'bot' ? ' bot' : '') + (isStaff ? ' staff' : '') },
        h('div', { class: 'bub' }, label ? h('div', { class: 'lbl', text: label }) : null, h('div', { text: m.content }), att,
          h('div', { class: 'tm', text: m.failed ? 'Not sent' : (m.pending ? 'Sending…' : time(m.created_at)) }))));
    });
    if (S.aiPending && S.handler === 'ai') {
      var typ = h('div', { class: 'typing', 'aria-label': 'Typing' });
      typ.appendChild(h('i', null));
      typ.appendChild(h('i', null));
      typ.appendChild(h('i', null));
      body.appendChild(typ);
    }

    var closed = S.conversation.status === 'closed';
    var input = h('input', {
      type: 'text',
      placeholder: closed ? 'Write to reopen this chat…' : 'Type your message…',
      'aria-label': 'Message',
      maxlength: '3000',
      class: 'pill-input',
      onkeydown: function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(); } }
    });
    function submit() { var t = input.value.trim(); if (!t) return; input.value = ''; S.error = ''; sendMessage(t); }
    var file = h('input', { type: 'file', class: 'hidden', accept: 'image/*,.pdf,.txt,.doc,.docx,.zip', onchange: function () { if (file.files[0]) upload(file.files[0]); } });
    var attachOn = !S.status || S.status.attachments_enabled !== false;

    var csat = null;
    if (closed && (!S.status || S.status.csat_enabled !== false)) {
      csat = h('div', { class: 'card' }, h('div', { class: 'muted', text: 'How did we do?' }), h('div', { class: 'stars' }, [1, 2, 3, 4, 5].map(function (n) {
        return h('button', { 'aria-label': n + ' stars', onclick: function () {
          call('POST', '/conversations/' + encodeURIComponent(S.conversation.id) + '/rate', { property_key: cfg.propertyKey, visitor_token: S.visitor.token, rating: n }).then(function (r) { S.notice = r.ok ? 'Thank you for your feedback!' : r.message; render(); });
        } }, '★');
      })));
    }

    var seniorBanner = (S.waitingSenior && S.seniorWaitRemaining > 0 && !S.openTicket) ? h('div', {
      style: 'background:#eff6ff;color:#1e40af;border-bottom:1px solid #bfdbfe;padding:8px 14px;font-size:12px;font-weight:500;display:flex;align-items:center;justify-content:space-between;'
    },
      h('span', { text: '⏳ Connecting to senior department…' }),
      h('span', { id: 'ehc-senior-timer', style: 'font-variant-numeric:tabular-nums;font-weight:600;background:#dbeafe;padding:2px 8px;border-radius:12px;', text: '0:' + (S.seniorWaitRemaining < 10 ? '0' : '') + S.seniorWaitRemaining })
    ) : null;

    return h('div', { style: 'display:flex;flex-direction:column;flex:1;min-height:0' },
      seniorBanner,
      S.openTicket ? h('div', { class: 'banner', text: 'Ticket ' + S.openTicket.reference + ' · ' + S.openTicket.status_label + ' — replies appear here and under Tickets.' }) : null,
      body,
      h('div', { class: 'foot' }, alerts(), csat,
        h('div', { class: 'tools' },
          attachOn ? h('button', { class: 'btn ghost sm', onclick: function () { file.click(); }, 'aria-label': 'Attach a file' }, 'Attach') : null, file,
          h('button', { class: 'link', onclick: function () { S.conversation = null; S.messages = []; S.screen = 'start'; S.notice = ''; render(); } }, 'New chat')),
        h('div', { class: 'pill-row', style: 'border-top:0;padding:0' }, input, h('button', { class: 'pill-send', onclick: submit, 'aria-label': 'Send' }, 'Send'))));
  }

  function ticketsView() {
    if (!S.customer) {
      return h('div', { class: 'body' }, alerts(), h('div', { class: 'card' }, h('h3', { text: 'My tickets' }),
        h('p', { class: 'muted', text: 'Sign in with your email and Chat PIN to see your tickets and our replies.' }),
        S.identityMode !== 'guest_only' ? h('button', { class: 'btn block', onclick: function () { S.tab = 'chat'; S.screen = 'identify'; S.idStep = 'email'; render(); } }, 'Sign in with email & PIN') : null));
    }
    return h('div', { class: 'body' }, alerts(),
      h('div', { class: 'muted', text: 'Signed in as ' + S.customer.email }),
      S.tickets.length === 0 ? h('div', { class: 'card' }, h('p', { class: 'muted', text: 'You have no tickets yet. If a request needs senior team attention, a ticket is created automatically.' })) :
        S.tickets.map(function (t) {
          return h('button', { class: 'item', onclick: function () { if (t.conversation_id) openConversation(t.conversation_id); } },
            h('div', null, h('strong', { text: t.reference }), h('span', { class: 'st', text: t.status_label })),
            h('div', { text: t.subject }),
            h('div', { class: 'muted', text: 'Updated ' + new Date(t.updated_at).toLocaleString() }));
        }));
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
