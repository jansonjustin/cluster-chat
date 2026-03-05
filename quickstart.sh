#!/usr/bin/env bash
# cluster-chat quickstart — https://github.com/jansonjustin/cluster-chat
# Clones the repo, builds the image, and starts the container.
# Requirements: git, docker
#
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/jansonjustin/cluster-chat/main/quickstart.sh | bash
#
# Options (env vars):
#   PORT=9090      — host port to bind (default: 8080)
#   DATA_DIR=...   — where to store data (default: ./cluster-chat-data)

set -euo pipefail

REPO="https://github.com/jansonjustin/cluster-chat.git"
IMAGE="cluster-chat:local"
CONTAINER="cluster-chat"
PORT="${PORT:-8080}"
DATA_DIR="${DATA_DIR:-$(pwd)/cluster-chat-data}"

echo ""
echo "  cluster-chat quickstart"
echo "  ========================"
echo ""

# Check dependencies
for cmd in git docker; do
  if ! command -v "$cmd" &>/dev/null; then
    echo "  ERROR: '$cmd' is required but not installed." >&2
    exit 1
  fi
done

# Check for port conflict before we do any work
if docker run --rm --network host alpine sh -c "nc -z 0.0.0.0 $PORT 2>/dev/null && exit 0 || exit 1" 2>/dev/null; then
  echo "  ERROR: Port $PORT is already in use." >&2
  echo "  Run with a different port: PORT=9090 bash quickstart.sh" >&2
  exit 1
fi

# Remove any leftover container from a previous failed run
if docker ps -a --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
  echo "  Found existing container '$CONTAINER' — removing it..."
  docker rm -f "$CONTAINER"
fi

# Clone or update
if [ -d "cluster-chat/.git" ]; then
  echo "  Found existing repo, pulling latest..."
  git -C cluster-chat pull
else
  echo "  Cloning $REPO..."
  git clone "$REPO" cluster-chat
fi

cd cluster-chat

# Build
echo ""
echo "  Building Docker image '$IMAGE'..."
docker build -t "$IMAGE" .

# Create data directory
mkdir -p "$DATA_DIR/uploads"

# Run
echo ""
echo "  Starting container '$CONTAINER' on port $PORT..."
docker run -d \
  --name "$CONTAINER" \
  --restart unless-stopped \
  -p "${PORT}:80" \
  -v "${DATA_DIR}:/data" \
  "$IMAGE"

echo ""
echo "  Done!"
echo ""
echo "  Open http://localhost:${PORT} in your browser."
echo ""
echo "  Get started:"
echo "    1. Go to http://localhost:${PORT}/models.php — add a model"
echo "       (point it at your Ollama host, e.g. http://ollama.local:11434)"
echo "    2. Go to http://localhost:${PORT}/agents.php — create an agent"
echo "    3. Back on the main page, select your agent and start chatting"
echo ""
echo "  Data is stored in: $DATA_DIR"
echo "  To stop:           docker rm -f $CONTAINER"
echo "  To update:         git -C cluster-chat pull && docker build -t $IMAGE cluster-chat && docker rm -f $CONTAINER && bash quickstart.sh"
echo ""
