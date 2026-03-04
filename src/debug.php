<?php
// ============================================================
// Cluster Chat — Stream Debug Page
// Access: https://chat.cluster.home/debug.php?host=https://ollama-peter.cluster.home&model=deepseek-r1:14b&fmt=ollama
// ============================================================

$host  = rtrim($_GET['host']  ?? '', '/');
$model = $_GET['model'] ?? 'deepseek-r1:14b';
$fmt   = $_GET['fmt']   ?? 'ollama';  // ollama | ollama-generate | openai
$run   = isset($_GET['run']);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Stream Debug — Cluster Chat</title>
<style>
body { background:#05080c; color:#d0dce8; font-family:monospace; font-size:13px; padding:20px; }
h2 { color:#00c8ff; }
label { color:#6a8aa8; display:block; margin-top:10px; font-size:11px; text-transform:uppercase; letter-spacing:.1em; }
input, select { background:#0d1219; border:1px solid #243545; color:#d0dce8; padding:6px 10px; border-radius:4px; width:400px; font-family:monospace; }
button { margin-top:16px; padding:8px 20px; background:#00c8ff; color:#05080c; border:none; border-radius:4px; font-weight:bold; cursor:pointer; }
#out { margin-top:20px; background:#090d13; border:1px solid #1a2a3a; border-radius:6px; padding:16px; white-space:pre-wrap; min-height:200px; max-height:600px; overflow-y:auto; }
.ok   { color:#00e57a; }
.err  { color:#ff4466; }
.warn { color:#ffaa00; }
.dim  { color:#304050; }
.tok  { color:#d0dce8; }
</style>
</head>
<body>
<h2>🔬 Stream Debug</h2>
<form method="get">
  <label>Host URL</label>
  <input name="host" value="<?= htmlspecialchars($host) ?>" placeholder="https://ollama-peter.cluster.home">
  <label>Model</label>
  <input name="model" value="<?= htmlspecialchars($model) ?>">
  <label>API Format</label>
  <select name="fmt">
    <option value="ollama"          <?= $fmt==='ollama'?'selected':'' ?>>Ollama (/api/chat)</option>
    <option value="ollama-generate" <?= $fmt==='ollama-generate'?'selected':'' ?>>Ollama Generate (/api/generate)</option>
    <option value="openai"          <?= $fmt==='openai'?'selected':'' ?>>OpenAI (/v1/chat/completions)</option>
  </select>
  <button type="submit" name="run" value="1">▶ Run Test</button>
</form>

<div id="out"><?php if (!$run): ?>Enter a host and click Run Test.<?php endif; ?></div>

<?php if ($run && $host && $model): ?>
<script>
// Stream the debug output via SSE
const out = document.getElementById('out');
out.textContent = '';

function line(text, cls) {
    const s = document.createElement('span');
    s.className = cls || '';
    s.textContent = text + '\n';
    out.appendChild(s);
    out.scrollTop = out.scrollHeight;
}

const url = '/debug_run.php?host=<?= urlencode($host) ?>&model=<?= urlencode($model) ?>&fmt=<?= urlencode($fmt) ?>';
fetch(url).then(async resp => {
    const reader = resp.body.getReader();
    const dec = new TextDecoder();
    let buf = '';
    while (true) {
        const {done, value} = await reader.read();
        if (done) break;
        buf += dec.decode(value);
        const lines = buf.split('\n');
        buf = lines.pop();
        for (const l of lines) {
            if (!l.startsWith('data: ')) continue;
            let evt;
            try { evt = JSON.parse(l.slice(6)); } catch { continue; }
            line(evt.text, evt.cls);
        }
    }
    if (buf.startsWith('data: ')) {
        try { const evt = JSON.parse(buf.slice(6)); line(evt.text, evt.cls); } catch {}
    }
}).catch(e => line('Fetch error: ' + e, 'err'));
</script>
<?php endif; ?>
</body>
</html>
