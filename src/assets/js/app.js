// app.js — Cluster Chat core logic

// ── Minimal markdown renderer ──────────────────────────────────────────────
const MD = {
  render(text) {
    if (!text) return '';
    let h = text
      // Code blocks
      .replace(/```(\w*)\n?([\s\S]*?)```/g, (_, lang, code) =>
        `<pre><code class="lang-${lang}">${escHtml(code.trim())}</code></pre>`)
      // Inline code
      .replace(/`([^`\n]+)`/g, (_, c) => `<code>${escHtml(c)}</code>`)
      // Bold + italic
      .replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>')
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.+?)\*/g, '<em>$1</em>')
      // Headings
      .replace(/^### (.+)$/gm, '<h3>$1</h3>')
      .replace(/^## (.+)$/gm, '<h2>$1</h2>')
      .replace(/^# (.+)$/gm, '<h1>$1</h1>')
      // Tables
      .replace(/^\|(.+)\|$/gm, '<tr><td>' + '$1'.replace(/\|/g,'</td><td>') + '</td></tr>')
      // Blockquote
      .replace(/^> (.+)$/gm, '<blockquote>$1</blockquote>')
      // HR
      .replace(/^---$/gm, '<hr>')
      // Unordered list
      .replace(/^[\-\*] (.+)$/gm, '<li>$1</li>')
      // Ordered list
      .replace(/^\d+\. (.+)$/gm, '<li>$1</li>')
      // Links
      .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
      // Line breaks → paragraphs
      ;
    // Wrap <li> in <ul>
    h = h.replace(/(<li>.*<\/li>(\n)?)+/gs, m => `<ul>${m}</ul>`);
    // Wrap table rows
    h = h.replace(/(<tr>.*<\/tr>(\n)?)+/gs, m => `<table><thead></thead><tbody>${m}</tbody></table>`);
    // Paragraphs (double newline)
    h = h.replace(/\n{2,}/g, '</p><p>');
    h = '<p>' + h + '</p>';
    // Clean up empty tags
    h = h.replace(/<p>\s*<\/p>/g, '').replace(/<p>(<[^>]+>)/g, '$1').replace(/(<\/[^>]+>)<\/p>/g, '$1');
    return h;
  }
};

function escHtml(t) {
  return t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── State ──────────────────────────────────────────────────────────────────
const state = window.state = {
  agents: [],
  currentAgent: null,
  chats: [],
  currentChat: null,
  messages: [],
  streaming: false,
  pendingFiles: [],   // [{name, type, path, url}]
};

// ── API helpers ────────────────────────────────────────────────────────────
const api = {
  async get(url) {
    const r = await fetch(url);
    if (!r.ok) throw new Error(await r.text());
    return r.json();
  },
  async post(url, data) {
    const r = await fetch(url, { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data) });
    if (!r.ok) throw new Error(await r.text());
    return r.json();
  },
  async put(url, data) {
    const r = await fetch(url, { method:'PUT', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data) });
    if (!r.ok) throw new Error(await r.text());
    return r.json();
  },
  async patch(url, data) {
    const r = await fetch(url, { method:'PATCH', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data) });
    if (!r.ok) throw new Error(await r.text());
    return r.json();
  },
  async del(url) {
    const r = await fetch(url, { method:'DELETE' });
    if (!r.ok) throw new Error(await r.text());
    return r.json();
  },
};

// ── Toast ──────────────────────────────────────────────────────────────────
function toast(msg, type = '') {
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.textContent = msg;
  document.getElementById('toast-container').appendChild(el);
  setTimeout(() => el.remove(), 3500);
}

// ── Init ───────────────────────────────────────────────────────────────────
async function init() {
  await loadAgents();
  bindEvents();
  autoResize(document.getElementById('msg-input'));
  document.getElementById('msg-input').addEventListener('keydown', onMsgKey);
}

// ── Agents ─────────────────────────────────────────────────────────────────
async function loadAgents() {
  state.agents = await api.get('/api/agents.php');
  renderAgentSelect();
  if (state.agents.length) selectAgent(state.agents[0]);
}

function renderAgentSelect() {
  const sel = document.getElementById('agent-select');
  sel.innerHTML = state.agents.length
    ? state.agents.map(a => `<option value="${a.id}">${escHtml(a.display_name)}</option>`).join('')
    : '<option value="">No agents — add one</option>';
  if (state.currentAgent) sel.value = state.currentAgent.id;
}

async function selectAgent(agent) {
  if (typeof agent === 'number' || typeof agent === 'string') {
    agent = state.agents.find(a => a.id == agent);
  }
  if (!agent) return;
  state.currentAgent = agent;
  document.getElementById('agent-select').value = agent.id;
  await loadChats();
  openNewChat();
}

// ── Chats ──────────────────────────────────────────────────────────────────
async function loadChats() {
  if (!state.currentAgent) return;
  state.chats = await api.get(`/api/chats.php?agent_id=${state.currentAgent.id}`);
  renderChatList();
}

function renderChatList() {
  const el = document.getElementById('chat-history');
  if (!state.chats.length) {
    el.innerHTML = '<div style="padding:12px 16px;font-size:12px;color:var(--txt3)">No chats yet</div>';
    return;
  }
  // Group by date
  const groups = {};
  state.chats.forEach(c => {
    const d = new Date(c.updated_at);
    const today = new Date(); today.setHours(0,0,0,0);
    const yesterday = new Date(today); yesterday.setDate(today.getDate()-1);
    let label;
    if (d >= today) label = 'Today';
    else if (d >= yesterday) label = 'Yesterday';
    else label = d.toLocaleDateString(undefined, { month:'short', day:'numeric' });
    if (!groups[label]) groups[label] = [];
    groups[label].push(c);
  });
  el.innerHTML = Object.entries(groups).map(([date, chats]) => `
    <div class="history-date">${date}</div>
    ${chats.map(c => `
      <div class="chat-item${state.currentChat?.id == c.id ? ' active' : ''}" data-id="${c.id}">
        <div class="chat-item-title">${escHtml(c.title)}</div>
        <div class="chat-item-actions">
          <button class="chat-item-btn" data-action="delete" data-id="${c.id}" title="Delete">✕</button>
        </div>
      </div>
    `).join('')}
  `).join('');
}

function openNewChat() {
  state.currentChat = null;
  state.messages = [];
  renderMessages();
  updateTopbar('New Chat');
  renderChatList();
  document.getElementById('msg-input').focus();
}

async function openChat(id) {
  const data = await api.get(`/api/chats.php?id=${id}`);
  state.currentChat = data;
  state.messages = data.messages || [];
  renderMessages();
  updateTopbar(data.title);
  renderChatList();
  scrollBottom();
}

async function deleteChat(id) {
  await api.del(`/api/chats.php?id=${id}`);
  if (state.currentChat?.id == id) openNewChat();
  await loadChats();
}

function updateTopbar(title) {
  document.getElementById('chat-title-input').value = title;
  const badge = document.getElementById('agent-badge');
  badge.textContent = state.currentAgent?.display_name || '';
}

// ── Messages ───────────────────────────────────────────────────────────────
function renderMessages() {
  const area = document.getElementById('chat-area');
  if (!state.messages.length) {
    area.innerHTML = `
      <div class="empty-state">
        <div class="empty-state-logo">CLUSTER CHAT</div>
        <p>Select an agent and start a conversation</p>
        <div class="empty-state-hint">↑ type a message or use voice</div>
      </div>`;
    return;
  }
  area.innerHTML = `<div class="message-group">
    ${state.messages.filter(m => m.role !== 'system').map(m => renderMessage(m)).join('')}
  </div>`;
  scrollBottom();
}

function renderMessage(m) {
  const isUser = m.role === 'user';
  const agent = state.currentAgent;
  const color = agent?.avatar_color || '#00c8ff';
  const agentInitial = (agent?.display_name || 'AI')[0].toUpperCase();
  const avatarStyle = isUser ? '' : `style="color:${color};border-color:${color};background:${color}18"`;

  let attachmentsHtml = '';
  if (m.media_path) {
    try {
      const files = JSON.parse(m.media_path);
      attachmentsHtml = `<div class="msg-attachments">${files.map(f => {
        if (f.type?.startsWith('image/')) {
          return `<div class="msg-attachment"><img src="${f.url}" alt="${escHtml(f.name)}"><div class="msg-attachment-info">${escHtml(f.name)}</div></div>`;
        }
        return `<div class="msg-attachment"><div class="msg-attachment-info">📎 ${escHtml(f.name)}</div></div>`;
      }).join('')}</div>`;
    } catch {}
  }

  return `
    <div class="message" data-id="${m.id}">
      <div class="msg-avatar ${m.role}" ${avatarStyle}>${isUser ? 'YOU' : agentInitial}</div>
      <div class="msg-content">
        <div class="msg-name ${m.role}">${isUser ? 'You' : escHtml(agent?.display_name || 'Assistant')}</div>
        <div class="msg-body">${MD.render(m.content)}</div>
        ${attachmentsHtml}
      </div>
    </div>`;
}

function appendMessage(m) {
  const area = document.getElementById('chat-area');
  let group = area.querySelector('.message-group');
  if (!group) {
    area.innerHTML = '<div class="message-group"></div>';
    group = area.querySelector('.message-group');
  }
  group.insertAdjacentHTML('beforeend', renderMessage(m));
  scrollBottom();
}

function appendTypingIndicator() {
  const area = document.getElementById('chat-area');
  let group = area.querySelector('.message-group');
  if (!group) {
    area.innerHTML = '<div class="message-group"></div>';
    group = area.querySelector('.message-group');
  }
  const agent = state.currentAgent;
  const color = agent?.avatar_color || '#00c8ff';
  const agentInitial = (agent?.display_name || 'AI')[0].toUpperCase();
  group.insertAdjacentHTML('beforeend', `
    <div class="message typing-msg">
      <div class="msg-avatar assistant" style="color:${color};border-color:${color};background:${color}18">${agentInitial}</div>
      <div class="msg-content">
        <div class="msg-name">${escHtml(agent?.display_name || 'Assistant')}</div>
        <div class="msg-body"><div class="msg-typing"><span></span><span></span><span></span></div></div>
      </div>
    </div>`);
  scrollBottom();
}

function scrollBottom() {
  const area = document.getElementById('chat-area');
  area.scrollTop = area.scrollHeight;
}

// ── Send message ───────────────────────────────────────────────────────────
async function sendMessage(content) {
  if (state.streaming) return;
  if (!content && !state.pendingFiles.length) return;
  if (!state.currentAgent) { toast('Select an agent first', 'error'); return; }

  const files = [...state.pendingFiles];
  clearPendingFiles();

  state.streaming = true;
  setSendLoading(true);

  // Optimistic user message
  const userMsg = {
    id: Date.now(),
    role: 'user',
    content,
    media_path: files.length ? JSON.stringify(files) : null,
  };
  state.messages.push(userMsg);
  appendMessage(userMsg);
  appendTypingIndicator();

  let assistantContent = '';
  let assistantMsgEl = null;

  try {
    await new Promise((resolve, reject) => {
      const es = new EventSource(`/api/stream.php`);
      // EventSource is GET-only; use fetch+streams for POST with SSE
      es.close();

      // Use fetch with ReadableStream
      fetch('/api/stream.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          chat_id: state.currentChat?.id || 0,
          content,
          files,
          agent_id: state.currentAgent.id,
        }),
      }).then(async resp => {
        const reader = resp.body.getReader();
        const decoder = new TextDecoder();

        while (true) {
          const { done, value } = await reader.read();
          if (done) break;
          const chunk = decoder.decode(value);
          const lines = chunk.split('\n');
          for (const line of lines) {
            if (!line.startsWith('data: ')) continue;
            let evt;
            try { evt = JSON.parse(line.slice(6)); } catch { continue; }

            if (evt.type === 'chat_created') {
              state.currentChat = { id: evt.chat_id, title: evt.title };
              await loadChats();
              renderChatList();
            } else if (evt.type === 'token') {
              // Remove typing indicator on first token
              document.querySelector('.typing-msg')?.remove();
              if (!assistantMsgEl) {
                const agent = state.currentAgent;
                const color = agent?.avatar_color || '#00c8ff';
                const agentInitial = (agent?.display_name || 'AI')[0].toUpperCase();
                let group = document.querySelector('.message-group');
                group.insertAdjacentHTML('beforeend', `
                  <div class="message stream-msg">
                    <div class="msg-avatar assistant" style="color:${color};border-color:${color};background:${color}18">${agentInitial}</div>
                    <div class="msg-content">
                      <div class="msg-name">${escHtml(agent?.display_name || 'Assistant')}</div>
                      <div class="msg-body"></div>
                    </div>
                  </div>`);
                assistantMsgEl = group.querySelector('.stream-msg .msg-body');
              }
              assistantContent += evt.content;
              assistantMsgEl.innerHTML = MD.render(assistantContent);
              scrollBottom();
            } else if (evt.type === 'done') {
              state.currentChat = state.currentChat || { id: evt.chat_id };
              if (state.currentChat) state.currentChat.id = evt.chat_id;
              state.messages.push({ id: evt.message_id, role: 'assistant', content: assistantContent });
              document.querySelector('.stream-msg')?.classList.remove('stream-msg');
              await loadChats();
              resolve();
            } else if (evt.type === 'error') {
              reject(new Error(evt.message));
            }
          }
        }
        resolve();
      }).catch(reject);
    });
  } catch (err) {
    document.querySelector('.typing-msg')?.remove();
    document.querySelector('.stream-msg')?.remove();
    toast('Error: ' + err.message, 'error');
    // Remove optimistic user message
    state.messages.pop();
    renderMessages();
  } finally {
    state.streaming = false;
    setSendLoading(false);
  }
}

function setSendLoading(loading) {
  const btn = document.getElementById('send-btn');
  btn.disabled = loading;
  btn.classList.toggle('loading', loading);
  btn.innerHTML = loading
    ? `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10" stroke-dasharray="31.4" stroke-dashoffset="10"><animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur=".8s" repeatCount="indefinite"/></circle></svg>`
    : `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22,2 15,22 11,13 2,9"/></svg>`;
}

// ── File attachments ───────────────────────────────────────────────────────
async function handleFileSelect(files) {
  const formData = new FormData();
  [...files].forEach(f => formData.append('files[]', f));
  try {
    const resp = await fetch('/api/upload.php', { method: 'POST', body: formData });
    const data = await resp.json();
    if (data.error) throw new Error(data.error);
    data.files.forEach(f => state.pendingFiles.push(f));
    renderPendingFiles();
  } catch (err) {
    toast('Upload failed: ' + err.message, 'error');
  }
}

function renderPendingFiles() {
  const wrap = document.getElementById('input-attachments');
  wrap.classList.toggle('has-files', !!state.pendingFiles.length);
  wrap.innerHTML = state.pendingFiles.map((f, i) => {
    if (f.type?.startsWith('image/')) {
      return `<div class="attach-preview">
        <img src="${f.url}" alt="${escHtml(f.name)}">
        <button class="attach-remove" data-index="${i}">✕</button>
      </div>`;
    }
    return `<div class="attach-preview">${escHtml(f.name)}<button class="attach-remove" data-index="${i}">✕</button></div>`;
  }).join('');
}

function clearPendingFiles() {
  state.pendingFiles = [];
  renderPendingFiles();
}

// ── Event bindings ─────────────────────────────────────────────────────────
function bindEvents() {
  // Sidebar toggle — toggle 'sidebar-open' on #app.
  // On mobile CSS treats sidebar as an overlay; on desktop it pushes content.
  // 'sidebar-closeable' is added to #sidebar after first close so desktop
  // collapse only triggers after the user has explicitly closed it.
  document.getElementById('sidebar-toggle').addEventListener('click', () => {
    const app = document.getElementById('app');
    const sidebar = document.getElementById('sidebar');
    const isMobile = window.matchMedia('(max-width: 640px)').matches;
    if (isMobile) {
      app.classList.toggle('sidebar-open');
    } else {
      // Desktop: toggle collapsed state directly on sidebar
      sidebar.classList.toggle('sidebar-closeable');
      sidebar.classList.toggle('collapsed');
      // Keep sidebar-open in sync so overlay logic doesn't interfere
      app.classList.remove('sidebar-open');
    }
  });

  // Close sidebar when tapping the dim overlay on mobile
  document.getElementById('main').addEventListener('click', e => {
    // Only close if the tap was on the overlay pseudo-element, not a chat item etc.
    // Simplest heuristic: close if tap target is #main itself or #chat-area
    if (e.target.id === 'main' || e.target.id === 'chat-area' || e.target.id === 'topbar') {
      document.getElementById('app').classList.remove('sidebar-open');
    }
  });

  // Agent select
  document.getElementById('agent-select').addEventListener('change', e => {
    const agent = state.agents.find(a => a.id == e.target.value);
    if (agent) selectAgent(agent);
  });

  // New chat
  document.getElementById('new-chat-btn').addEventListener('click', openNewChat);

  // Chat list click
  document.getElementById('chat-history').addEventListener('click', e => {
    const item = e.target.closest('.chat-item');
    const btn = e.target.closest('[data-action]');
    if (btn && btn.dataset.action === 'delete') {
      e.stopPropagation();
      e.preventDefault();
      if (confirm('Delete this chat?')) {
        // Mark as deleting to prevent the item click from firing on touch
        btn.closest('.chat-item')?.setAttribute('data-deleting', '1');
        deleteChat(btn.dataset.id);
      }
      return;
    }
    if (item?.getAttribute('data-deleting')) return;
    if (item) openChat(item.dataset.id);
  });

  // Title edit
  const titleInput = document.getElementById('chat-title-input');
  titleInput.addEventListener('change', async () => {
    if (!state.currentChat) return;
    await api.patch(`/api/chats.php?id=${state.currentChat.id}`, { title: titleInput.value });
    await loadChats();
  });

  // Send button
  document.getElementById('send-btn').addEventListener('click', () => {
    const inp = document.getElementById('msg-input');
    const txt = inp.value.trim();
    inp.value = '';
    autoResize(inp);
    sendMessage(txt);
  });

  // File upload button
  document.getElementById('attach-btn').addEventListener('click', () => {
    document.getElementById('file-input').click();
  });
  document.getElementById('file-input').addEventListener('change', e => {
    handleFileSelect(e.target.files);
    e.target.value = '';
  });

  // Drag-drop on chat area
  const chatArea = document.getElementById('chat-area');
  chatArea.addEventListener('dragover', e => { e.preventDefault(); });
  chatArea.addEventListener('drop', e => {
    e.preventDefault();
    if (e.dataTransfer.files.length) handleFileSelect(e.dataTransfer.files);
  });

  // Remove attachment
  document.getElementById('input-attachments').addEventListener('click', e => {
    const btn = e.target.closest('[data-index]');
    if (btn) {
      state.pendingFiles.splice(+btn.dataset.index, 1);
      renderPendingFiles();
    }
  });

  // Voice memo button
  document.getElementById('voice-memo-btn').addEventListener('click', toggleVoiceMemo);

  // Voice call button
  document.getElementById('voice-call-btn').addEventListener('click', () => {
    startVoiceCall();
  });
}

function onMsgKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    const inp = e.target;
    const txt = inp.value.trim();
    inp.value = '';
    autoResize(inp);
    sendMessage(txt);
  }
}

function autoResize(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 160) + 'px';
}

// ── Voice memo ─────────────────────────────────────────────────────────────
let memoRecorder = null, memoChunks = [];

async function toggleVoiceMemo() {
  const btn = document.getElementById('voice-memo-btn');
  const badge = document.getElementById('recording-badge');
  if (memoRecorder && memoRecorder.state === 'recording') {
    memoRecorder.stop();
    return;
  }
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    memoChunks = [];
    memoRecorder = new MediaRecorder(stream, { mimeType: 'audio/webm' });
    memoRecorder.ondataavailable = e => memoChunks.push(e.data);
    memoRecorder.onstop = async () => {
      stream.getTracks().forEach(t => t.stop());
      btn.classList.remove('active');
      badge.classList.remove('active');
      const blob = new Blob(memoChunks, { type: 'audio/webm' });
      await transcribeMemo(blob);
    };
    memoRecorder.start();
    btn.classList.add('active');
    badge.classList.add('active');
  } catch (err) {
    toast('Microphone access denied', 'error');
  }
}

async function transcribeMemo(blob) {
  if (!state.currentAgent) { toast('Select an agent first', 'error'); return; }
  try {
    const fd = new FormData();
    fd.append('audio', blob, 'voice.webm');
    fd.append('agent_id', state.currentAgent.id);
    const resp = await fetch('/api/transcribe.php', { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.error) throw new Error(data.error);
    // Auto-send the transcription directly
    sendMessage(data.text.trim());
  } catch (err) {
    toast('Transcription failed: ' + err.message, 'error');
  }
}

document.addEventListener('DOMContentLoaded', init);
