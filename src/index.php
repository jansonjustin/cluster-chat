<?php require_once __DIR__ . '/db.php'; get_db(); // init db ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Cluster Chat</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div id="app">

  <!-- ── Sidebar ──────────────────────────────────── -->
  <nav id="sidebar">
    <div class="sidebar-header">
      <div class="logo">
        <div class="logo-dot"></div>
        CLUSTER CHAT
      </div>
    </div>

    <div class="agent-section">
      <div class="section-label">Agent</div>
      <div class="agent-select-wrap">
        <select id="agent-select"></select>
      </div>
    </div>

    <button class="new-chat-btn" id="new-chat-btn">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      New Chat
    </button>

    <div class="chat-history" id="chat-history"></div>

    <nav class="sidebar-nav">
      <a class="nav-link active" href="/">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        Chat
      </a>
      <a class="nav-link" href="/agents.php">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        Agents
      </a>
      <a class="nav-link" href="/models.php">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
        Models
      </a>
    </nav>
  </nav>

  <!-- ── Main ─────────────────────────────────────── -->
  <div id="main">

    <!-- Topbar -->
    <div id="topbar">
      <button class="topbar-toggle" id="sidebar-toggle" title="Toggle sidebar">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
      </button>
      <div class="topbar-title">
        <input type="text" id="chat-title-input" value="New Chat" placeholder="Chat title">
      </div>
      <div class="topbar-agent-badge" id="agent-badge"></div>
    </div>

    <!-- Chat area -->
    <div id="chat-area">
      <div class="empty-state">
        <div class="empty-state-logo">CLUSTER CHAT</div>
        <p>Select an agent and start a conversation</p>
        <div class="empty-state-hint">↑ type a message or use voice</div>
      </div>
    </div>

    <!-- Input area -->
    <div id="input-area">
      <div class="input-wrap">
        <div class="input-attachments" id="input-attachments"></div>
        <div class="input-row">
          <textarea id="msg-input" rows="1" placeholder="Message… (Enter to send, Shift+Enter for newline)"></textarea>

          <!-- Attach -->
          <button class="input-btn" id="attach-btn" title="Attach file">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
            </svg>
          </button>

          <!-- Voice memo -->
          <button class="input-btn" id="voice-memo-btn" title="Voice memo (click to record, click again to send)">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
              <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
              <line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/>
            </svg>
          </button>

          <!-- Voice call -->
          <button class="input-btn" id="voice-call-btn" title="Voice call mode">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13 19.79 19.79 0 0 1 1.62 4.41 2 2 0 0 1 3.59 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.06 6.06l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
            </svg>
          </button>

          <!-- Send -->
          <button class="send-btn" id="send-btn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22,2 15,22 11,13 2,9"/>
            </svg>
          </button>
        </div>

        <div class="recording-badge" id="recording-badge">
          <div class="rec-dot"></div>
          RECORDING
        </div>
      </div>
    </div>

  </div><!-- /main -->

</div><!-- /app -->

<!-- Voice call overlay -->
<div id="voice-overlay">
  <div class="voice-agent-name" id="voice-agent-name">CLUSTER ASSISTANT</div>
  <div class="voice-orb" id="voice-orb">
    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity=".6">
      <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
      <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
    </svg>
  </div>
  <div class="voice-status" id="voice-status">Initializing…</div>
  <div class="voice-transcript" id="voice-transcript"></div>
  <div class="voice-controls">
    <button class="voice-ctrl-btn mute">🎤 Mute</button>
    <button class="voice-ctrl-btn end">✕ End Call</button>
  </div>
</div>

<!-- Toast container -->
<div id="toast-container"></div>

<!-- Hidden file input -->
<input type="file" id="file-input" multiple style="display:none" accept="image/*,audio/*,video/*,.pdf,.txt,.md,.json,.csv,.log">

<script>
// Fix Android soft keyboard causing 100vh to jump.
// Sets --app-h on html to window.innerHeight which excludes the keyboard.
function setAppHeight() {
  document.documentElement.style.setProperty('--app-h', window.innerHeight + 'px');
}
setAppHeight();
// visualViewport is the most reliable on Android Chrome
if (window.visualViewport) {
  window.visualViewport.addEventListener('resize', setAppHeight);
} else {
  window.addEventListener('resize', setAppHeight);
}
</script>
<script src="/assets/js/app.js"></script>
<script src="/assets/js/voice.js"></script>
</body>
</html>
