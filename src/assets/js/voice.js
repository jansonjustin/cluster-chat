// voice.js — Full voice call mode

const Voice = {
  active: false,
  muted: false,
  recorder: null,
  audioCtx: null,
  analyser: null,
  silenceTimer: null,
  chunks: [],
  currentAudio: null,
  SILENCE_THRESHOLD: 10,    // RMS amplitude below this = silence
  SILENCE_DURATION: 1800,   // ms of silence before sending
  MIN_SPEECH_MS: 400,       // ignore very short blips
  speechStart: null,

  async start() {
    if (this.active) return;
    if (!window.state?.currentAgent) { toast('Select an agent first', 'error'); return; }
    const overlay = document.getElementById('voice-overlay');
    overlay.classList.add('active');
    this.active = true;
    this.muted = false;
    this.setTranscript('');
    this.updateStatus('Initializing…');
    try {
      this.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      this.audioCtx = new AudioContext();
      const source = this.audioCtx.createMediaStreamSource(this.stream);
      this.analyser = this.audioCtx.createAnalyser();
      this.analyser.fftSize = 256;
      source.connect(this.analyser);
      this.listen();
    } catch (err) {
      this.stop();
      toast('Microphone access denied', 'error');
    }
  },

  listen() {
    if (!this.active) return;
    this.chunks = [];
    this.speechStart = null;
    this.setTranscript('');
    let isRecording = false;

    const mime = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : 'audio/ogg';
    this.recorder = new MediaRecorder(this.stream, { mimeType: mime });
    this.recorder.ondataavailable = e => { if (e.data.size) this.chunks.push(e.data); };
    this.recorder.onstop = () => {
      if (!this.active) return;
      const elapsed = this.speechStart ? Date.now() - this.speechStart : 0;
      if (elapsed >= this.MIN_SPEECH_MS && this.chunks.length) {
        const blob = new Blob(this.chunks, { type: mime });
        this.processAudio(blob);
      } else {
        this.listen(); // too short, listen again
      }
    };

    const bufLen = this.analyser.frequencyBinCount;
    const dataArr = new Uint8Array(bufLen);
    let speaking = false;

    const checkVAD = () => {
      if (!this.active) return;
      if (this.muted) { requestAnimationFrame(checkVAD); return; }
      if (window.state?.streaming) {
        this.updateOrb('idle');
        this.updateStatus('Waiting…');
        requestAnimationFrame(checkVAD);
        return;
      }

      this.analyser.getByteTimeDomainData(dataArr);
      // RMS amplitude
      let sum = 0;
      for (let i = 0; i < bufLen; i++) { const v = (dataArr[i] - 128) / 128; sum += v * v; }
      const rms = Math.sqrt(sum / bufLen) * 100;

      if (rms > this.SILENCE_THRESHOLD) {
        if (!speaking) {
          speaking = true;
          this.speechStart = Date.now();
          this.recorder.start(100);
          clearTimeout(this.silenceTimer);
          this.updateOrb('listening');
          this.updateStatus('Listening…');
          this.setTranscript('');
        } else {
          clearTimeout(this.silenceTimer);
          this.silenceTimer = setTimeout(() => {
            if (speaking && this.recorder.state === 'recording') {
              this.recorder.stop();
              this.updateOrb('idle');
              this.updateStatus('Processing…');
              speaking = false;
            }
          }, this.SILENCE_DURATION);
        }
      } else if (!speaking) {
        this.updateOrb('idle');
        this.updateStatus('Ready — speak to begin');
      }
      requestAnimationFrame(checkVAD);
    };

    requestAnimationFrame(checkVAD);
    this.updateStatus('Ready — speak to begin');
  },

  async processAudio(blob) {
    this.updateStatus('Transcribing…');
    try {
      const fd = new FormData();
      fd.append('audio', blob, 'voice.webm');
      fd.append('agent_id', window.state?.currentAgent?.id || 0);
      const resp = await fetch('/api/transcribe.php', { method:'POST', body: fd });
      const data = await resp.json();
      if (data.error) throw new Error(data.error);
      const text = data.text?.trim();
      if (!text) { this.listen(); return; }
      this.setTranscript('"' + text + '"');
      await this.sendAndSpeak(text);
    } catch (err) {
      this.updateStatus('Transcription failed: ' + err.message);
      setTimeout(() => this.listen(), 2000);
    }
  },

  async sendAndSpeak(text) {
    this.updateStatus('Thinking…');
    // Set streaming=true so the VAD loop pauses and doesn't overwrite
    // status messages or restart the recorder while we're processing.
    if (window.state) window.state.streaming = true;
    let fullResponse = '';

    try {
      await new Promise((resolve, reject) => {
        let sseBuffer = ''; // accumulates bytes across chunks — SSE lines can span packets
        fetch('/api/stream.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            chat_id: window.state?.currentChat?.id || 0,
            content: text,
            files: [],
            agent_id: window.state?.currentAgent?.id,
          }),
        }).then(async resp => {
          if (!resp.ok) { reject(new Error('HTTP ' + resp.status)); return; }
          const reader = resp.body.getReader();
          const decoder = new TextDecoder();
          while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            sseBuffer += decoder.decode(value, { stream: true });
            // Process all complete lines in the buffer
            let nl;
            while ((nl = sseBuffer.indexOf('\n')) !== -1) {
              const line = sseBuffer.slice(0, nl).trimEnd();
              sseBuffer = sseBuffer.slice(nl + 1);
              if (!line.startsWith('data: ')) continue;
              let evt;
              try { evt = JSON.parse(line.slice(6)); } catch { continue; }
              if (evt.type === 'chat_created') {
                if (window.state) window.state.currentChat = { id: evt.chat_id, title: evt.title };
                if (typeof loadChats === 'function') loadChats();
              } else if (evt.type === 'token') {
                fullResponse += evt.content;
                this.updateStatus('Thinking… (' + fullResponse.length + ' chars)');
              } else if (evt.type === 'done') {
                if (window.state) {
                  if (evt.chat_id) window.state.currentChat = { id: evt.chat_id };
                }
                if (typeof loadChats === 'function') loadChats();
                resolve();
                return;
              } else if (evt.type === 'error') {
                reject(new Error(evt.message));
                return;
              }
            }
          }
          // Stream ended — resolve with whatever we got
          resolve();
        }).catch(reject);
      });

      if (fullResponse && this.active) {
        await this.speak(fullResponse);
      } else if (!fullResponse) {
        this.updateStatus('No response received');
        setTimeout(() => { if (this.active) this.listen(); }, 2000);
        return;
      }
    } catch (err) {
      this.updateStatus('Error: ' + err.message);
      setTimeout(() => { if (this.active) this.listen(); }, 3000);
      return;
    } finally {
      if (window.state) window.state.streaming = false;
    }

    if (this.active) this.listen();
  },

  async speak(text) {
    this.updateStatus('Speaking…');
    this.updateOrb('speaking');
    const agentId = window.state?.currentAgent?.id;
    try {
      const resp = await fetch('/api/tts.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ text, agent_id: agentId }),
      });
      if (!resp.ok) throw new Error('TTS unavailable');
      const blob = await resp.blob();
      const url = URL.createObjectURL(blob);
      await new Promise((resolve, reject) => {
        this.currentAudio = new Audio(url);
        this.currentAudio.onended = resolve;
        this.currentAudio.onerror = reject;
        this.currentAudio.play();
      });
      URL.revokeObjectURL(url);
    } catch {
      // No TTS or failed — just continue
    } finally {
      this.updateOrb('idle');
    }
  },

  stop() {
    this.active = false;
    clearTimeout(this.silenceTimer);
    if (this.recorder && this.recorder.state !== 'inactive') this.recorder.stop();
    if (this.stream) this.stream.getTracks().forEach(t => t.stop());
    if (this.audioCtx) this.audioCtx.close();
    if (this.currentAudio) { this.currentAudio.pause(); this.currentAudio = null; }
    document.getElementById('voice-overlay').classList.remove('active');
  },

  toggleMute() {
    this.muted = !this.muted;
    const btn = document.querySelector('.voice-ctrl-btn.mute');
    btn.textContent = this.muted ? '🔇 Unmute' : '🎤 Mute';
    if (this.muted) this.updateStatus('Muted');
  },

  updateOrb(state) {
    const orb = document.getElementById('voice-orb');
    orb.className = 'voice-orb ' + state;
  },

  updateStatus(msg) {
    const el = document.getElementById('voice-status');
    if (el) el.textContent = msg;
  },

  setTranscript(text) {
    const el = document.getElementById('voice-transcript');
    if (el) el.textContent = text;
  },
};

function startVoiceCall() {
  Voice.start();
}

// Bind overlay buttons (called after DOM ready)
document.addEventListener('DOMContentLoaded', () => {
  document.querySelector('.voice-ctrl-btn.end')?.addEventListener('click', () => Voice.stop());
  document.querySelector('.voice-ctrl-btn.mute')?.addEventListener('click', () => Voice.toggleMute());
  // Update agent name in voice overlay
  document.getElementById('agent-select')?.addEventListener('change', () => {
    const sel = document.getElementById('agent-select');
    const txt = sel.options[sel.selectedIndex]?.text || '';
    const el = document.getElementById('voice-agent-name');
    if (el) el.textContent = txt;
  });
});
