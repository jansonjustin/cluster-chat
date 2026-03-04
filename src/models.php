<?php require_once __DIR__ . '/db.php'; get_db(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Models — Cluster Chat</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div id="app">

  <!-- Sidebar (minimal nav) -->
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
      <a class="nav-link" href="/agents.php">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        Agents
      </a>
      <a class="nav-link active" href="/models.php">
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
      <div class="topbar-title"><span style="font-weight:600">Models & Hosts</span></div>
    </div>

    <div class="page-wrap">
      <div class="page-header">
        <div>
          <h1>Models</h1>
          <p>Manage model endpoints for chat, vision, TTS, and transcription</p>
        </div>
        <button class="btn btn-primary" onclick="openModal()">+ Add Model</button>
      </div>

      <table class="data-table" id="models-table">
        <thead>
          <tr>
            <th>Display Name</th>
            <th>Type</th>
            <th>Model ID</th>
            <th>Host</th>
            <th>API</th>
            <th style="width:100px">Actions</th>
          </tr>
        </thead>
        <tbody id="models-tbody">
          <tr><td colspan="6" class="empty-table">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /app -->

<!-- Add/Edit modal -->
<div class="modal-bg" id="modal-bg">
  <div class="modal">
    <h3 id="modal-title">Add Model</h3>
    <input type="hidden" id="edit-id">

    <div class="form-group">
      <label>Display Name</label>
      <input type="text" id="f-display-name" placeholder="Qwen 2.5 7B">
    </div>
    <div class="form-group">
      <label>Type</label>
      <select id="f-type">
        <option value="chat">Chat</option>
        <option value="vision">Vision (multimodal)</option>
        <option value="tts">TTS (text-to-speech)</option>
        <option value="whisper">Whisper (transcription)</option>
      </select>
    </div>
    <div class="form-group">
      <label>Host URL</label>
      <input type="text" id="f-host-url" placeholder="http://ollama.cluster.home:11434">
      <div class="form-hint" id="host-hint">No trailing slash. For Ollama: http://host:11434</div>
    </div>
    <div class="form-group">
      <label>Model Name (on host)</label>
      <input type="text" id="f-model-name" placeholder="qwen2.5:7b">
    </div>
    <div class="form-group">
      <label>API Format</label>
      <select id="f-api-format">
        <option value="ollama">Ollama (/api/chat)</option>
        <option value="ollama-generate">Ollama Generate (/api/generate) — use for thinking models</option>
        <option value="openai">OpenAI-compatible (/v1/chat/completions)</option>
        <option value="wyoming">Wyoming TCP (Piper TTS)</option>
      </select>
      <div class="form-hint" id="api-format-hint"></div>
    </div>

    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
      <button class="btn btn-primary" onclick="saveModel()">Save</button>
    </div>
  </div>
</div>

<div id="toast-container"></div>

<script>
let models = [];

async function load() {
  const resp = await fetch('/api/models.php');
  models = await resp.json();
  render();
}

function render() {
  const tbody = document.getElementById('models-tbody');
  if (!models.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="empty-table">No models yet. Add one to get started.</td></tr>';
    return;
  }
  tbody.innerHTML = models.map(m => `
    <tr>
      <td><strong>${esc(m.display_name)}</strong></td>
      <td><span class="type-badge ${m.type}">${m.type}</span></td>
      <td style="font-family:monospace;font-size:12px;color:var(--txt2)">${esc(m.model_name)}</td>
      <td style="font-size:12px;color:var(--txt2);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(m.host_url)}</td>
      <td><span style="font-size:11px;color:var(--txt3)">${m.api_format}</span></td>
      <td>
        <div class="actions-cell">
          <button class="btn btn-ghost btn-sm" onclick="editModel(${m.id})">Edit</button>
          <button class="btn btn-danger btn-sm" onclick="deleteModel(${m.id})">Del</button>
        </div>
      </td>
    </tr>
  `).join('');
}

function esc(t) { return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function openModal(id) {
  document.getElementById('edit-id').value = '';
  document.getElementById('modal-title').textContent = 'Add Model';
  ['display-name','type','host-url','model-name','api-format'].forEach(k => {
    const el = document.getElementById('f-' + k);
    if (el.tagName === 'SELECT') el.value = el.options[0].value;
    else el.value = '';
  });
  document.getElementById('modal-bg').classList.add('open');
  document.getElementById('f-display-name').focus();
}

function editModel(id) {
  const m = models.find(x => x.id == id);
  if (!m) return;
  document.getElementById('edit-id').value = m.id;
  document.getElementById('modal-title').textContent = 'Edit Model';
  document.getElementById('f-display-name').value = m.display_name;
  document.getElementById('f-type').value = m.type;
  document.getElementById('f-host-url').value = m.host_url;
  document.getElementById('f-model-name').value = m.model_name;
  document.getElementById('f-api-format').value = m.api_format;
  document.getElementById('modal-bg').classList.add('open');
}

function closeModal() {
  document.getElementById('modal-bg').classList.remove('open');
}

async function saveModel() {
  const id = document.getElementById('edit-id').value;
  const data = {
    display_name: document.getElementById('f-display-name').value.trim(),
    type:         document.getElementById('f-type').value,
    host_url:     document.getElementById('f-host-url').value.trim(),
    model_name:   document.getElementById('f-model-name').value.trim(),
    api_format:   document.getElementById('f-api-format').value,
  };
  if (!data.display_name || !data.host_url || !data.model_name) {
    toast('Please fill all fields', 'error'); return;
  }
  try {
    const method = id ? 'PUT' : 'POST';
    const url = id ? `/api/models.php?id=${id}` : '/api/models.php';
    const resp = await fetch(url, { method, headers:{'Content-Type':'application/json'}, body:JSON.stringify(data) });
    const result = await resp.json();
    if (result.error) throw new Error(result.error);
    closeModal();
    await load();

document.getElementById('f-api-format').addEventListener('change', updateFormatHints);
function updateFormatHints() {
  const fmt = document.getElementById('f-api-format').value;
  const hostHint = document.getElementById('host-hint');
  const fmtHint  = document.getElementById('api-format-hint');
  if (fmt === 'wyoming') {
    hostHint.textContent = 'Wyoming host:port — e.g. piper:10200 or tcp://piper:10200';
    fmtHint.textContent  = 'Model name = voice name, e.g. en_US-amy-medium';
  } else if (fmt === 'ollama-generate') {
    hostHint.textContent = 'No trailing slash. e.g. https://ollama-peter.cluster.home';
    fmtHint.textContent  = 'Use this for thinking models (DeepSeek-R1, QwQ) that work with /api/generate but not /api/chat';
  } else if (fmt === 'ollama') {
    hostHint.textContent = 'No trailing slash. e.g. http://ollama.cluster.home:11434';
    fmtHint.textContent  = '';
  } else {
    hostHint.textContent = 'No trailing slash. e.g. https://whisper.cluster.home';
    fmtHint.textContent  = '';
  }
}
    toast(id ? 'Model updated' : 'Model added', 'success');
  } catch (err) { toast(err.message, 'error'); }
}

async function deleteModel(id) {
  if (!confirm('Delete this model?')) return;
  await fetch(`/api/models.php?id=${id}`, { method:'DELETE' });
  await load();

document.getElementById('f-api-format').addEventListener('change', updateFormatHints);
function updateFormatHints() {
  const fmt = document.getElementById('f-api-format').value;
  const hostHint = document.getElementById('host-hint');
  const fmtHint  = document.getElementById('api-format-hint');
  if (fmt === 'wyoming') {
    hostHint.textContent = 'Wyoming host:port — e.g. piper:10200 or tcp://piper:10200';
    fmtHint.textContent  = 'Model name = voice name, e.g. en_US-amy-medium';
  } else if (fmt === 'ollama-generate') {
    hostHint.textContent = 'No trailing slash. e.g. https://ollama-peter.cluster.home';
    fmtHint.textContent  = 'Use this for thinking models (DeepSeek-R1, QwQ) that work with /api/generate but not /api/chat';
  } else if (fmt === 'ollama') {
    hostHint.textContent = 'No trailing slash. e.g. http://ollama.cluster.home:11434';
    fmtHint.textContent  = '';
  } else {
    hostHint.textContent = 'No trailing slash. e.g. https://whisper.cluster.home';
    fmtHint.textContent  = '';
  }
}
  toast('Model deleted');
}

function toast(msg, type='') {
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.textContent = msg;
  document.getElementById('toast-container').appendChild(el);
  setTimeout(() => el.remove(), 3000);
}

document.getElementById('modal-bg').addEventListener('click', e => { if (e.target === e.currentTarget) closeModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

load();

document.getElementById('f-api-format').addEventListener('change', updateFormatHints);
function updateFormatHints() {
  const fmt = document.getElementById('f-api-format').value;
  const hostHint = document.getElementById('host-hint');
  const fmtHint  = document.getElementById('api-format-hint');
  if (fmt === 'wyoming') {
    hostHint.textContent = 'Wyoming host:port — e.g. piper:10200 or tcp://piper:10200';
    fmtHint.textContent  = 'Model name = voice name, e.g. en_US-amy-medium';
  } else if (fmt === 'ollama-generate') {
    hostHint.textContent = 'No trailing slash. e.g. https://ollama-peter.cluster.home';
    fmtHint.textContent  = 'Use this for thinking models (DeepSeek-R1, QwQ) that work with /api/generate but not /api/chat';
  } else if (fmt === 'ollama') {
    hostHint.textContent = 'No trailing slash. e.g. http://ollama.cluster.home:11434';
    fmtHint.textContent  = '';
  } else {
    hostHint.textContent = 'No trailing slash. e.g. https://whisper.cluster.home';
    fmtHint.textContent  = '';
  }
}
</script>
</body>
</html>
