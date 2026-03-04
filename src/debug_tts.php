<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>TTS Debug — Cluster Chat</title>
<style>
body { background:#05080c; color:#d0dce8; font-family:monospace; font-size:13px; padding:20px; }
h2 { color:#00c8ff; }
label { color:#6a8aa8; display:block; margin-top:10px; font-size:11px; text-transform:uppercase; letter-spacing:.1em; }
input, select, textarea { background:#0d1219; border:1px solid #243545; color:#d0dce8; padding:6px 10px; border-radius:4px; width:400px; font-family:monospace; }
button { margin-top:16px; padding:8px 20px; background:#00c8ff; color:#05080c; border:none; border-radius:4px; font-weight:bold; cursor:pointer; margin-right:8px; }
#out { margin-top:20px; background:#090d13; border:1px solid #1a2a3a; border-radius:6px; padding:16px; white-space:pre-wrap; min-height:100px; max-height:400px; overflow-y:auto; }
.ok  { color:#00e57a; } .err { color:#ff4466; } .warn { color:#ffaa00; } .dim { color:#304050; }
audio { display:block; margin-top:16px; width:400px; }
</style>
</head>
<body>
<h2>🔊 TTS / Piper Debug</h2>

<label>Host (wyoming: piper:10200 | openai: https://tts.host)</label>
<input id="host" value="piper:10200">

<label>Voice / Model name</label>
<input id="model" value="en_US-amy-medium">

<label>API Format</label>
<select id="fmt">
  <option value="wyoming">Wyoming TCP (Piper)</option>
  <option value="openai">OpenAI-compatible HTTP</option>
</select>

<label>Text to synthesize</label>
<input id="text" value="Hello! Piper TTS is working on the cluster.">

<br>
<button onclick="runTest()">▶ Test TTS</button>

<div id="out">Click Test TTS to run.</div>
<audio id="player" controls style="display:none"></audio>

<script>
function log(text, cls) {
    const out = document.getElementById('out');
    const s = document.createElement('span');
    s.className = cls || '';
    s.textContent = text + '\n';
    out.appendChild(s);
    out.scrollTop = out.scrollHeight;
}

async function runTest() {
    const out = document.getElementById('out');
    out.textContent = '';
    const player = document.getElementById('player');
    player.style.display = 'none';

    const host  = document.getElementById('host').value.trim();
    const model = document.getElementById('model').value.trim();
    const fmt   = document.getElementById('fmt').value;
    const text  = document.getElementById('text').value.trim();

    log('=== TTS Debug ===', 'ok');
    log(`Host:   ${host}`);
    log(`Model:  ${model}`);
    log(`Format: ${fmt}`);
    log(`Text:   "${text}"`);
    log('');
    log('POSTing to /api/tts.php...');

    const start = Date.now();
    try {
        const resp = await fetch('/api/tts.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                text,
                // Pass host/model/format directly for testing — tts.php will use
                // agent's configured model normally, but debug_tts passes override
                _debug_host:   host,
                _debug_model:  model,
                _debug_format: fmt,
                debug: true,
            }),
        });

        const elapsed = ((Date.now() - start) / 1000).toFixed(2);
        log(`Response: HTTP ${resp.status} in ${elapsed}s`, resp.ok ? 'ok' : 'err');
        log(`Content-Type: ${resp.headers.get('content-type')}`);

        if (!resp.ok) {
            const errText = await resp.text();
            log('Error response: ' + errText, 'err');
            return;
        }

        const blob = await resp.blob();
        log(`Audio blob: ${blob.size} bytes, type=${blob.type}`, blob.size > 0 ? 'ok' : 'err');

        if (blob.size > 0) {
            const url = URL.createObjectURL(blob);
            player.src = url;
            player.style.display = 'block';
            player.play().catch(e => log('Autoplay blocked: ' + e, 'warn'));
            log('▶ Playing audio...', 'ok');
        } else {
            log('Got 0 bytes — no audio produced', 'err');
        }
    } catch (e) {
        log('Fetch error: ' + e, 'err');
    }
}
</script>
</body>
</html>
