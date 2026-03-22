#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

IMAGE_TAG="${IMAGE_TAG:-latest}"
export IMAGE_TAG

echo "==> Pulling image (tag: ${IMAGE_TAG})..."
docker compose -f "$SCRIPT_DIR/compose.prod.yaml" pull php worker

echo "==> Running migrations..."
docker compose -f "$SCRIPT_DIR/compose.prod.yaml" run --rm php bin/console doctrine:migrations:migrate --no-interaction

echo "==> Restarting services..."
docker compose -f "$SCRIPT_DIR/compose.prod.yaml" up -d --remove-orphans

echo "==> Done. Running tag: ${IMAGE_TAG}"
