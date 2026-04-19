(function () {
  'use strict';

  function ar(s) { return JSON.parse('"' + s + '"'); }

  var T = {
    open: ar('\u0627\u0641\u062a\u062d \u0627\u0644\u0645\u062d\u0627\u062f\u062b\u0629'),
    title: ar('\u0645\u0633\u0627\u0639\u062f \u0627\u0644\u0627\u0633\u062a\u0642\u0628\u0627\u0644'),
    status: ar('\u0645\u062d\u0627\u062f\u062b\u0629 \u0648\u0627\u0633\u062a\u0642\u0628\u0627\u0644 \u0648\u062d\u062c\u0632'),
    login: ar('\u062a\u0633\u062c\u064a\u0644 \u0627\u0644\u062f\u062e\u0648\u0644'),
    logout: ar('\u062a\u0633\u062c\u064a\u0644 \u062e\u0631\u0648\u062c'),
    register: ar('\u0625\u0646\u0634\u0627\u0621 \u062d\u0633\u0627\u0628'),
    placeholder: ar('\u0627\u0643\u062a\u0628 \u0631\u0633\u0627\u0644\u062a\u0643 \u0647\u0646\u0627...'),
    welcome: ar('\u0623\u0647\u0644\u0627\u064b \u0641\u064a\u0643. \u0623\u0646\u0627 \u0645\u0633\u0627\u0639\u062f \u0627\u0644\u0627\u0633\u062a\u0642\u0628\u0627\u0644. \u0627\u062d\u0643\u064a \u0644\u064a \u0637\u0644\u0628\u0643 \u0628\u0634\u0643\u0644 \u0637\u0628\u064a\u0639\u064a \u0648\u0633\u0623\u0633\u0627\u0639\u062f\u0643.'),
    book: ar('\u0623\u0631\u064a\u062f \u062d\u062c\u0632 \u0645\u0648\u0639\u062f'),
    heart: ar('\u0645\u064a\u0646 \u0623\u0637\u0628\u0627\u0621 \u0627\u0644\u0642\u0644\u0628\u061f'),
    services: ar('\u0634\u0648 \u0627\u0644\u062e\u062f\u0645\u0627\u062a \u0627\u0644\u0645\u062a\u0648\u0641\u0631\u0629\u061f'),
    eye: ar('\u0628\u062f\u064a \u0639\u064a\u0648\u0646'),
    badResponse: ar('\u0644\u0645 \u0623\u0633\u062a\u0637\u0639 \u0642\u0631\u0627\u0621\u0629 \u0631\u062f \u0627\u0644\u062e\u0627\u062f\u0645.'),
    serverError: ar('\u062a\u0639\u0630\u0631 \u0627\u0644\u0627\u062a\u0635\u0627\u0644 \u0628\u0627\u0644\u062e\u0627\u062f\u0645. \u062a\u0623\u0643\u062f \u0645\u0646 \u062a\u0634\u063a\u064a\u0644 \u0627\u0644\u0645\u0634\u0631\u0648\u0639 \u062b\u0645 \u062d\u0627\u0648\u0644 \u0645\u0631\u0629 \u0623\u062e\u0631\u0649.')
  };

  var PAGES_BASE = (function () {
    var loc = window.location;
    var m = loc.pathname.match(/^(\/[^\/]+\/)/);
    return m ? loc.protocol + '//' + loc.host + m[1] + 'frontend' : '/Hospital/frontend';
  })();

  var API_BASE = (function () {
    var loc = window.location;
    var m = loc.pathname.match(/^(\/[^\/]+\/)/);
    return m ? loc.protocol + '//' + loc.host + m[1] + 'public' : 'http://localhost/Hospital/public';
  })();

  var authPatient = null;
  var isLoading = false;
  var hasWelcomed = false;
  var isSessionResetting = true;
  var chatPageId = Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);

  function buildUI() {
    var container = document.createElement('div');
    container.innerHTML = [
      '<button id="ahli-chat-btn" aria-label="', T.open, '">',
      '<span id="ahli-chat-badge">1</span>',
      '<svg class="icon-chat" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>',
      '<svg class="icon-close" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>',
      '</button>',
      '<div id="ahli-chat-window" role="dialog" aria-label="', T.open, '">',
      '<div id="ahli-chat-header"><div class="bot-avatar"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg></div>',
      '<div class="bot-info"><p class="bot-name">', T.title, '</p><span class="bot-status"><span class="status-dot"></span><span id="ahli-status-text">', T.status, '</span></span></div>',
      '<div id="ahli-auth-box" style="display:flex;flex-direction:column;gap:6px;align-items:flex-end"></div></div>',
      '<div id="ahli-chat-messages"></div>',
      '<div id="ahli-chat-footer"><button id="ahli-chat-send" aria-label="send"><svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg></button>',
      '<input id="ahli-chat-input" type="text" placeholder="', T.placeholder, '" autocomplete="off"></div>',
      '</div>'
    ].join('');
    document.body.appendChild(container);
  }

  function request(url, options) {
    return fetch(url, Object.assign({ credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json; charset=utf-8' } }, options || {})).then(function (res) { return res.json(); });
  }

  function refreshAuth() {
    return request(API_BASE + '/api/auth/me').then(function (json) {
      authPatient = json && json.success ? json.data : null;
      renderAuthBox();
    }).catch(function () { authPatient = null; renderAuthBox(); });
  }

  function renderAuthBox() {
    var box = document.getElementById('ahli-auth-box');
    if (!box) return;
    if (authPatient) {
      box.innerHTML = '<span style="font-size:12px;font-weight:700;color:#fff">' + esc(authPatient.username || authPatient.full_name || 'Patient') + '</span><button id="ahli-logout-btn" style="border:none;background:rgba(255,255,255,.18);color:#fff;border-radius:999px;padding:6px 10px;cursor:pointer">' + T.logout + '</button>';
      document.getElementById('ahli-logout-btn').onclick = function () {
        request(API_BASE + '/api/auth/logout', { method: 'POST', body: '{}' }).then(function () {
          isSessionResetting = true;
          return resetServerConversation().then(function () {
            isSessionResetting = false;
            clearPageConversation();
            return refreshAuth();
          }, function () {
            isSessionResetting = false;
            clearPageConversation();
            return refreshAuth();
          });
        });
      };
    } else {
      box.innerHTML = '<a href="' + PAGES_BASE + '/login.html" style="color:#fff;font-size:12px;font-weight:700">' + T.login + '</a><a href="' + PAGES_BASE + '/register.html" style="color:#dff7ff;font-size:12px">' + T.register + '</a>';
    }
  }

  function addMessage(type, text) {
    var msgs = document.getElementById('ahli-chat-messages');
    var wrap = document.createElement('div');
    wrap.className = 'chat-msg ' + type;
    var body = document.createElement('div');
    body.className = 'msg-bubble';
    body.textContent = text || '';
    wrap.appendChild(body);
    msgs.appendChild(wrap);
    msgs.scrollTop = msgs.scrollHeight;
  }

  function addQuickReplies(items) {
    return;
  }

  function showTypingIndicator() {
    var msgs = document.getElementById('ahli-chat-messages');
    var wrap = document.createElement('div');
    wrap.id = 'typing-wrap';
    wrap.className = 'chat-msg bot';
    wrap.innerHTML = '<div class="typing-indicator"><span></span><span></span><span></span></div>';
    msgs.appendChild(wrap);
  }
  function hideTypingIndicator() { var el = document.getElementById('typing-wrap'); if (el) el.remove(); }

  function quickRepliesForResponse(json) {
    if (!json || !json.data) return [];
    if (Array.isArray(json.data.quick_replies)) return json.data.quick_replies;
    if (Array.isArray(json.data.available_slots)) return json.data.available_slots;
    if (Array.isArray(json.data.doctors)) return json.data.doctors.map(function (d) { return d.full_name; });
    if (Array.isArray(json.data) && json.data.length && json.data[0].full_name) return json.data.map(function (d) { return d.full_name; });
    return [];
  }

  function handleChatResponse(json) {
    if (!json) return addMessage('bot', T.badResponse);
    if (json.success && json.reply) {
      addMessage('bot', json.reply);
      addQuickReplies(quickRepliesForResponse(json));
    } else {
      addMessage('bot', json.message || T.badResponse);
    }
  }

  function handleUserMessage(text) {
    if (!text || !text.trim() || isLoading || isSessionResetting) return;
    var trimmed = text.trim();
    addMessage('user', trimmed);
    document.getElementById('ahli-chat-input').value = '';
    isLoading = true;
    showTypingIndicator();
    request(API_BASE + '/api/chat', { method: 'POST', body: JSON.stringify({ message: trimmed, chat_page_id: chatPageId }) }).then(function (json) {
      hideTypingIndicator(); isLoading = false; handleChatResponse(json);
    }).catch(function () { hideTypingIndicator(); isLoading = false; addMessage('bot', T.serverError); });
  }

  function resetServerConversation() {
    return request(API_BASE + '/api/chat', { method: 'POST', body: JSON.stringify({ message: 'reset', chat_page_id: chatPageId }) }).catch(function () { return null; });
  }

  function clearPageConversation() {
    var msgs = document.getElementById('ahli-chat-messages');
    var win = document.getElementById('ahli-chat-window');
    if (msgs) msgs.innerHTML = '';
    hasWelcomed = false;
    if (win && win.classList.contains('visible')) {
      addMessage('bot', T.welcome);
      hasWelcomed = true;
    }
  }

  function esc(s) { return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  function init() {
    buildUI();
    var btn = document.getElementById('ahli-chat-btn');
    var win = document.getElementById('ahli-chat-window');
    var input = document.getElementById('ahli-chat-input');
    var send = document.getElementById('ahli-chat-send');
    var badge = document.getElementById('ahli-chat-badge');
    var opened = false;
    input.disabled = true;
    send.disabled = true;
    resetServerConversation().then(function () {
      isSessionResetting = false;
      input.disabled = false;
      send.disabled = false;
      return refreshAuth();
    }, function () {
      isSessionResetting = false;
      input.disabled = false;
      send.disabled = false;
      return refreshAuth();
    });
    btn.addEventListener('click', function () {
      opened = !opened;
      btn.classList.toggle('open', opened);
      win.classList.toggle('visible', opened);
      if (opened) {
        if (badge) badge.remove();
        if (!hasWelcomed) {
          addMessage('bot', T.welcome);
          hasWelcomed = true;
        }
        refreshAuth();
        setTimeout(function () { input.focus(); }, 200);
      }
    });
    send.addEventListener('click', function () { handleUserMessage(input.value); });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); handleUserMessage(input.value); } });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
