<?php require_once __DIR__ . '/db.php'; get_db(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Agents — Cluster Chat</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div id="app">

  <nav id="sidebar">
    <div class="sidebar-header">
      <div class="logo"><div class="logo-dot"></div>CLUSTER CHAT</div>
    </div>
    <div style="flex:1"></div>
    <nav class="sidebar-nav">
      <a class="nav-link" href="/">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        Chat
      </a>
      <a class="nav-link active" href="/agents.php">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        Agents
      </a>
      <a class="nav-link" href="/models.php">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
        Models
      </a>
    </nav>
  </nav>

  <div id="main">
    <div id="topbar">
      <button class="topbar-toggle" onclick="history.length > 1 ? history.back() : window.location='/'" title="Back">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15,18 9,12 15,6"/></svg>
      </button>
      <div class="topbar-title"><span style="font-weight:600">Agents</span></div>
    </div>

    <div class="page-wrap">
      <div class="page-header">
        <div>
          <h1>Agents</h1>
          <p>Configure AI personas with specific models and system prompts</p>
        </div>
        <button class="btn btn-primary" onclick="openModal()">+ Add Agent</button>
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>Agent</th>
            <th>Chat Model</th>
            <th>TTS</th>
            <th>Whisper</th>
            <th>Vision</th>
            <th style="width:100px">Actions</th>
          </tr>
        </thead>
        <tbody id="agents-tbody">
          <tr><td colspan="6" class="empty-table">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- Add/Edit modal -->
<div class="modal-bg" id="modal-bg">
  <div class="modal" style="max-width:520px">
    <h3 id="modal-title">Add Agent</h3>
    <input type="hidden" id="edit-id">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 12px">
      <div class="form-group" style="grid-column:1/-1">
        <label>Display Name</label>
        <input type="text" id="f-name" placeholder="Cluster Assistant">
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label>Avatar Color</label>
        <div class="color-row" id="color-row"></div>
        <input type="hidden" id="f-color" value="#00c8ff">
      </div>
      <div class="form-group">
        <label>Chat Model <span style="color:var(--red)">*</span></label>
        <select id="f-chat-model"><option value="">— none —</option></select>
      </div>
      <div class="form-group">
        <label>Vision Model</label>
        <select id="f-vision-model"><option value="">— none —</option></select>
        <div class="form-hint">Used when images are attached</div>
      </div>
      <div class="form-group">
        <label>TTS Model</label>
        <select id="f-tts-model"><option value="">— none —</option></select>
        <div class="form-hint">For voice call mode playback</div>
      </div>
      <div class="form-group">
        <label>Whisper Model</label>
        <select id="f-whisper-model"><option value="">— none —</option></select>
        <div class="form-hint">For voice memo + call STT</div>
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label>System Prompt</label>
        <textarea id="f-system-prompt" rows="4" placeholder="You are a helpful assistant…"></textarea>
      </div>
    </div>

    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
      <button class="btn btn-primary" onclick="saveAgent()">Save</button>
    </div>
  </div>
</div>

<div id="toast-container"></div>

<script>
const COLORS = ['#00c8ff','#00e57a','#ffaa00','#ff4466','#9966ff','#ff6633','#33aaff','#ff66cc','#66ffcc','#ffcc33'];
let agents = [], models = [];

async function load() {
  [agents, models] = await Promise.all([
    fetch('/api/agents.php').then(r=>r.json()),
    fetch('/api/models.php').then(r=>r.json()),
  ]);
  renderTable();
  buildColorSwatches();
}

function renderTable() {
  const tbody = document.getElementById('agents-tbody');
  if (!agents.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="empty-table">No agents yet.</td></tr>';
    return;
  }
  tbody.innerHTML = agents.map(a => `
    <tr>
      <td>
        <div style="display:flex;align-items:center;gap:8px">
          <div style="width:28px;height:28px;border-radius:50%;background:${esc(a.avatar_color)}22;border:1.5px solid ${esc(a.avatar_color)};display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:${esc(a.avatar_color)};font-family:Orbitron,monospace">
            ${esc((a.display_name||'A')[0].toUpperCase())}
          </div>
          <strong>${esc(a.display_name)}</strong>
        </div>
      </td>
      <td><span style="font-size:13px">${esc(a.chat_model_name||'—')}</span></td>
      <td><span style="font-size:12px;color:var(--txt2)">${esc(a.tts_model_name||'—')}</span></td>
      <td><span style="font-size:12px;color:var(--txt2)">${esc(a.whisper_model_name||'—')}</span></td>
      <td><span style="font-size:12px;color:var(--txt2)">${esc(a.vision_model_name||'—')}</span></td>
      <td>
        <div class="actions-cell">
          <button class="btn btn-ghost btn-sm" onclick="editAgent(${a.id})">Edit</button>
          <button class="btn btn-danger btn-sm" onclick="deleteAgent(${a.id})">Del</button>
        </div>
      </td>
    </tr>
  `).join('');
}

function esc(t) { return String(t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function buildColorSwatches() {
  const row = document.getElementById('color-row');
  row.innerHTML = COLORS.map(c => `
    <div class="color-swatch${document.getElementById('f-color').value===c?' selected':''}"
         style="background:${c}"
         data-color="${c}"
         onclick="selectColor('${c}')"></div>
  `).join('');
}

function selectColor(c) {
  document.getElementById('f-color').value = c;
  document.querySelectorAll('.color-swatch').forEach(s => s.classList.toggle('selected', s.dataset.color === c));
}

function populateModelSelects(currentValues = {}) {
  const byType = { chat:[], vision:[], tts:[], whisper:[] };
  models.forEach(m => { if (byType[m.type]) byType[m.type].push(m); });
  const selects = {
    'f-chat-model':    { type:'chat',    val: currentValues.chat_model_id },
    'f-vision-model':  { type:'vision',  val: currentValues.vision_model_id },
    'f-tts-model':     { type:'tts',     val: currentValues.tts_model_id },
    'f-whisper-model': { type:'whisper', val: currentValues.whisper_model_id },
  };
  Object.entries(selects).forEach(([id, cfg]) => {
    const sel = document.getElementById(id);
    sel.innerHTML = '<option value="">— none —</option>' +
      (byType[cfg.type]||[]).map(m => `<option value="${m.id}"${m.id==cfg.val?' selected':''}>${esc(m.display_name)}</option>`).join('');
  });
}

function openModal() {
  document.getElementById('edit-id').value = '';
  document.getElementById('modal-title').textContent = 'Add Agent';
  document.getElementById('f-name').value = '';
  document.getElementById('f-system-prompt').value = '';
  selectColor('#00c8ff');
  populateModelSelects({});
  document.getElementById('modal-bg').classList.add('open');
  document.getElementById('f-name').focus();
}

function editAgent(id) {
  const a = agents.find(x => x.id == id);
  if (!a) return;
  document.getElementById('edit-id').value = a.id;
  document.getElementById('modal-title').textContent = 'Edit Agent';
  document.getElementById('f-name').value = a.display_name;
  document.getElementById('f-system-prompt').value = a.system_prompt || '';
  selectColor(a.avatar_color || '#00c8ff');
  buildColorSwatches();
  populateModelSelects(a);
  document.getElementById('modal-bg').classList.add('open');
}

function closeModal() { document.getElementById('modal-bg').classList.remove('open'); }

async function saveAgent() {
  const id = document.getElementById('edit-id').value;
  const data = {
    display_name:      document.getElementById('f-name').value.trim(),
    chat_model_id:     document.getElementById('f-chat-model').value || null,
    vision_model_id:   document.getElementById('f-vision-model').value || null,
    tts_model_id:      document.getElementById('f-tts-model').value || null,
    whisper_model_id:  document.getElementById('f-whisper-model').value || null,
    system_prompt:     document.getElementById('f-system-prompt').value,
    avatar_color:      document.getElementById('f-color').value,
  };
  if (!data.display_name) { toast('Name required', 'error'); return; }
  try {
    const method = id ? 'PUT' : 'POST';
    const url = id ? `/api/agents.php?id=${id}` : '/api/agents.php';
    const resp = await fetch(url, { method, headers:{'Content-Type':'application/json'}, body:JSON.stringify(data) });
    const result = await resp.json();
    if (result.error) throw new Error(result.error);
    closeModal(); await load();
    toast(id ? 'Agent updated' : 'Agent created', 'success');
  } catch (err) { toast(err.message, 'error'); }
}

async function deleteAgent(id) {
  if (!confirm('Delete this agent and all its chats?')) return;
  await fetch(`/api/agents.php?id=${id}`, { method:'DELETE' });
  await load(); toast('Agent deleted');
}

function toast(msg, type='') {
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.textContent = msg;
  document.getElementById('toast-container').appendChild(el);
  setTimeout(() => el.remove(), 3000);
}

document.getElementById('modal-bg').addEventListener('click', e => { if (e.target===e.currentTarget) closeModal(); });
document.addEventListener('keydown', e => { if (e.key==='Escape') closeModal(); });
load();
</script>
</body>
</html>
