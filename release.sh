#!/usr/bin/env bash
# KQueue Release Script
# Usage: ./release.sh 1.0.0
# Output: kqueue-v1.0.0.zip ready for GitHub release

set -e

# ── Colours ───────────────────────────────────────────────────────────────────
GREEN='\033[0;32m'
CYAN='\033[0;36m'
RED='\033[0;31m'
BOLD='\033[1m'
RESET='\033[0m'

ok()   { echo -e "${GREEN}✓${RESET} $*"; }
info() { echo -e "${CYAN}→${RESET} $*"; }
fail() { echo -e "${RED}✗${RESET} $*"; exit 1; }
hr()   { echo -e "${CYAN}──────────────────────────────────────────${RESET}"; }

# ── Version argument ──────────────────────────────────────────────────────────
VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
    fail "Usage: ./release.sh <version>  (e.g. ./release.sh 1.0.0)"
fi

# Strip leading 'v' if provided
VERSION="${VERSION#v}"
TAG="v${VERSION}"
ZIP_NAME="kqueue-${TAG}.zip"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUTPUT_DIR="$(dirname "$SCRIPT_DIR")"
ZIP_PATH="${OUTPUT_DIR}/${ZIP_NAME}"

hr
echo -e "${BOLD}  KQueue Release Builder — ${TAG}${RESET}"
hr
echo ""

# ── Check Docker is available ─────────────────────────────────────────────────
info "Checking Docker..."
if ! command -v docker &>/dev/null; then
    fail "Docker not found. Please install Docker first."
fi
ok "Docker found"

# ── Check the built image exists, offer to build if not ──────────────────────
info "Checking for kqueue_laravel-test Docker image..."
if ! docker images --format "{{.Repository}}" 2>/dev/null | grep -q "kqueue_laravel-test"; then
    echo ""
    echo "  Image kqueue_laravel-test not found."
    read -rp "  Build it now? (y/n): " BUILD_NOW
    if [[ "${BUILD_NOW,,}" == "y" ]]; then
        info "Building Docker image (this takes a few minutes — Swoole compiles from source)..."
        docker-compose build laravel-test
        ok "Image built"
    else
        fail "Docker image required. Run: docker-compose build laravel-test"
    fi
else
    ok "Docker image found"
fi

# ── Install production composer packages ─────────────────────────────────────
echo ""
info "Installing production Composer packages via Docker..."

docker run --rm \
    -v "${SCRIPT_DIR}:/app" \
    -w /app \
    kqueue_laravel-test \
    composer update --no-dev --optimize-autoloader --no-interaction --ignore-platform-req=ext-swoole 2>&1 \
    | grep -v "^$" \
    | grep -E "(Updating|Installing|Removing|Lock file|Generating|security|warning)" \
    || true

ok "Composer packages installed (production only)"

# ── Create zip ────────────────────────────────────────────────────────────────
echo ""
info "Creating ${ZIP_NAME}..."

# Remove old zip if exists
rm -f "${ZIP_PATH}"

docker run --rm \
    -v "${OUTPUT_DIR}:/workspace" \
    -w /workspace \
    debian:bookworm-slim \
    bash -c "
        apt-get update -qq && apt-get install -y zip -qq 2>/dev/null
        zip -r ${ZIP_NAME} kqueue \
            --exclude 'kqueue/.git/*' \
            --exclude 'kqueue/laravel-test/*' \
            --exclude 'kqueue/Dockerfile' \
            --exclude 'kqueue/Dockerfile.laravel' \
            --exclude 'kqueue/docker-compose.yml' \
            --exclude 'kqueue/release.sh' \
            --exclude 'kqueue/idea.txt' \
            --exclude 'kqueue/disscuss.txt' \
            --exclude 'kqueue/analyze-5-jobs.php' \
            --exclude 'kqueue/dispatch-jobs.php' \
            --exclude 'kqueue/test-analyzer-only.php' \
            --exclude 'kqueue/tests/*' \
            --exclude 'kqueue/vendor/*/test/*' \
            --exclude 'kqueue/vendor/*/tests/*' \
            --exclude 'kqueue/vendor/*/Tests/*' \
        2>&1 | grep -v 'adding:' || true
        chown $(id -u):$(id -g) ${ZIP_NAME} 2>/dev/null || true
    " 2>/dev/null

# Fix ownership if needed
[[ -f "$ZIP_PATH" ]] && chown "$(id -u):$(id -g)" "$ZIP_PATH" 2>/dev/null || true

ok "Zip created"

# ── Summary ───────────────────────────────────────────────────────────────────
SIZE=$(du -sh "$ZIP_PATH" 2>/dev/null | cut -f1)

echo ""
hr
echo -e "${GREEN}${BOLD}  Release ready!${RESET}"
hr
echo ""
echo -e "  File:    ${BOLD}${ZIP_PATH}${RESET}"
echo -e "  Size:    ${SIZE}"
echo -e "  Tag:     ${TAG}"
echo ""
echo "  Next steps:"
echo "    1. git tag ${TAG} && git push origin ${TAG}"
echo "    2. Go to GitHub → Releases → New release"
echo "    3. Pick tag ${TAG}, upload ${ZIP_NAME}"
echo ""
hr
