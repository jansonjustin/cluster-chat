# Cluster Chat

A self-hosted, multi-agent web chat application built with PHP, SQLite, and vanilla JS. Designed for homelab setups where models and services are spread across multiple hosts — Ollama instances, Whisper servers, Piper TTS, and anything OpenAI-compatible can all be wired together into agents you can talk to from a single interface.

Deployed as a Docker Swarm stack behind Traefik with internal TLS via step-ca.

## Features

- **Multi-model, multi-host** — each model has its own host URL, so chat, TTS, and transcription can all live on different machines
- **Agents** — compose a chat model, a Whisper transcription model, and a Piper TTS voice into a single named agent with an optional system prompt
- **Voice calls** — full hands-free voice call mode: VAD detects speech, Whisper transcribes it, the agent responds, Piper speaks the reply
- **Voice memos** — record and auto-send a transcribed message without leaving the keyboard
- **File & image uploads** — attach files and images to messages, with vision model support
- **Chat history** — persistent SQLite storage, collapsible sidebar, resumable conversations
- **Streaming** — SSE token streaming for all chat models including thinking models (DeepSeek-R1)

## Stack

| Layer | Tech |
|---|---|
| Backend | PHP 8.3 + Apache |
| Database | SQLite |
| Frontend | Vanilla JS + CSS |
| TTS | Piper via Wyoming TCP protocol |
| Transcription | Whisper (OpenAI-compatible or onerahmet/openai-whisper-asr-webservice) |
| Chat | Ollama, OpenAI-compatible, or any streaming SSE API |
| Deployment | Docker Swarm, Traefik reverse proxy, step-ca TLS |

## Pages

- **`/`** — main chat interface with agent/chat selector and voice controls
- **`/models.php`** — add, edit, and delete model/host configurations
- **`/agents.php`** — compose agents from available models

## Instructions

### Quick start (Docker)

The repo includes `quickstart.sh` which handles everything — clones the repo, builds the image, and starts the container. Requirements: `git` and `docker`.

```bash
curl -fsSL https://raw.githubusercontent.com/jansonjustin/cluster-chat/main/quickstart.sh | bash
```

Or if you've already cloned the repo:

```bash
bash quickstart.sh
```

Override the defaults with env vars:

```bash
PORT=9090 DATA_DIR=~/my-chat-data bash quickstart.sh
```

Once running, open `http://localhost:8080`, go to **Models** to point it at an Ollama or OpenAI-compatible host, create an **Agent**, and start chatting. Data is saved in `./cluster-chat-data` by default and survives restarts.

---

### Web server (no Docker)

Requires PHP 8.3+ with the `pdo_sqlite`, `curl`, and `fileinfo` extensions, and Apache with `mod_rewrite` enabled.

```bash
git clone https://github.com/jansonjustin/cluster-chat.git
cp -r cluster-chat/src/* /var/www/html/
mkdir -p /var/www/html/data/uploads
chown -R www-data:www-data /var/www/html/data
```

---

### Docker Compose

Uses `docker-compose.yml` — no Swarm required. Edit the file to set your port and data path, then:

```bash
git clone https://github.com/jansonjustin/cluster-chat.git
cd cluster-chat
# Edit docker-compose.yml — set ports and volume path
docker compose up -d
```

---

### Docker Swarm

Uses `stack.yml`, which adds `deploy:` blocks for placement, restart policy, and Traefik labels. These are silently ignored by `docker compose up` — use `docker stack deploy` instead.

> **Note:** `docker-compose.yml` and `stack.yml` are separate files. Use the one that matches your setup.

```bash
git clone https://github.com/jansonjustin/cluster-chat.git
cd cluster-chat

# Build and push to your registry
docker build -t registry.example.com/cluster-chat:local .
docker push registry.example.com/cluster-chat:local

# Edit stack.yml — set your hostname, volume paths, and overlay network name
docker stack deploy -c stack.yml cluster-chat
```

Key things to configure in `stack.yml`:

- Traefik labels — set your hostname (e.g. `chat.cluster.home`)
- Volume path — wherever your persistent storage is mounted
- Network name — defaults to `swarm-net`, change to match your overlay
