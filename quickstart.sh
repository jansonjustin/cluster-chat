#!/usr/bin/env bash
# cluster-chat quickstart
# Clones the repo, builds the image, and runs it locally.
# Requirements: git, docker
# Usage: curl -fsSL https://raw.githubusercontent.com/yourusername/cluster-chat/main/quickstart.sh | bash

set -euo pipefail

REPO="https://github.com/yourusername/cluster-chat.git"
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

# Check if a container with this name is already running
if docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
  echo "  A container named '$CONTAINER' is already running."
  echo "  Stop it first with: docker rm -f $CONTAINER"
  exit 1
fi

# Clone
if [ -d "cluster-chat" ]; then
  echo "  Directory 'cluster-chat' already exists, pulling latest..."
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
echo "    1. Go to /models.php and add a model (point it at your Ollama or OpenAI-compatible host)"
echo "    2. Go to /agents.php and create an agent"
echo "    3. Select the agent on the main page and start chatting"
echo ""
echo "  Data is stored in: $DATA_DIR"
echo "  To stop:  docker rm -f $CONTAINER"
echo "  To update: git pull && docker build -t $IMAGE . && docker rm -f $CONTAINER && docker run ..."
echo ""
