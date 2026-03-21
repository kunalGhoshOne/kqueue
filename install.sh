#!/usr/bin/env bash
# KQueue Installer
# Usage: curl -fsSL https://raw.githubusercontent.com/kunalGhoshOne/kqueue/main/install.sh | sudo bash
# Or:    wget -qO- https://raw.githubusercontent.com/kunalGhoshOne/kqueue/main/install.sh | sudo bash

set -e

# ── Colours ──────────────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
RESET='\033[0m'

ok()   { echo -e "${GREEN}✓${RESET} $*"; }
info() { echo -e "${CYAN}→${RESET} $*"; }
warn() { echo -e "${YELLOW}!${RESET} $*"; }
fail() { echo -e "${RED}✗${RESET} $*"; exit 1; }
hr()   { echo -e "${CYAN}──────────────────────────────────────────${RESET}"; }

hr
echo -e "${BOLD}  KQueue Installer — Non-blocking Laravel Queue${RESET}"
hr
echo ""

# ── Must run as root ──────────────────────────────────────────────────────────
if [[ "$EUID" -ne 0 ]]; then
    fail "Please run as root: curl ... | sudo bash"
fi

# ── Detect PHP ───────────────────────────────────────────────────────────────
info "Detecting PHP installation..."

PHP_BIN=$(command -v php 2>/dev/null || true)
if [[ -z "$PHP_BIN" ]]; then
    fail "PHP not found. Install PHP 8.1+ first, then re-run this script."
fi

PHP_VERSION=$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
PHP_MAJOR=$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION;')
PHP_MINOR=$("$PHP_BIN" -r 'echo PHP_MINOR_VERSION;')

if [[ "$PHP_MAJOR" -lt 8 ]] || { [[ "$PHP_MAJOR" -eq 8 ]] && [[ "$PHP_MINOR" -lt 1 ]]; }; then
    fail "PHP 8.1+ required (found PHP $PHP_VERSION)."
fi

ok "PHP $PHP_VERSION found at $PHP_BIN"

# ── Detect PHP ini ───────────────────────────────────────────────────────────
PHP_INI=$("$PHP_BIN" --ini 2>/dev/null | grep "Loaded Configuration" | awk '{print $NF}')
if [[ -z "$PHP_INI" ]] || [[ "$PHP_INI" == "(none)" ]]; then
    warn "Could not auto-detect php.ini — Swoole will still load via extension directory."
    PHP_INI=""
else
    ok "php.ini: $PHP_INI"
fi

# ── Check if Swoole already installed ────────────────────────────────────────
if "$PHP_BIN" -m 2>/dev/null | grep -qi swoole; then
    ok "Swoole already installed — skipping install"
    SWOOLE_INSTALLED=1
else
    SWOOLE_INSTALLED=0
fi

# ── Detect distro and install Swoole ─────────────────────────────────────────
if [[ "$SWOOLE_INSTALLED" -eq 0 ]]; then
    info "Detecting Linux distribution..."

    DISTRO=""
    if [[ -f /etc/os-release ]]; then
        . /etc/os-release
        DISTRO="${ID,,}"
    elif [[ -f /etc/alpine-release ]]; then
        DISTRO="alpine"
    elif command -v sw_vers &>/dev/null; then
        DISTRO="macos"
    fi

    echo ""
    info "Distro: ${DISTRO:-unknown}"
    echo ""

    case "$DISTRO" in
        ubuntu|debian|linuxmint|pop)
            info "Installing Swoole via apt + PECL (Ubuntu/Debian)..."
            apt-get update -qq
            apt-get install -y -qq php-dev php-pear gcc make autoconf libssl-dev
            pecl channel-update pecl.php.net || true
            printf "\n\n\n\n\n" | pecl install swoole 2>&1 | tail -5
            ;;

        centos|rhel|fedora|rocky|almalinux)
            info "Installing Swoole via PECL (CentOS/RHEL/Fedora)..."
            if command -v dnf &>/dev/null; then
                dnf install -y -q php-devel gcc make autoconf openssl-devel
            else
                yum install -y -q php-devel gcc make autoconf openssl-devel
            fi
            printf "\n\n\n\n\n" | pecl install swoole 2>&1 | tail -5
            ;;

        alpine)
            info "Installing Swoole on Alpine Linux..."
            apk add --no-cache php82-dev gcc musl-dev make autoconf openssl-dev php82-pear
            printf "\n\n\n\n\n" | pecl install swoole 2>&1 | tail -5
            ;;

        macos|darwin)
            info "Installing Swoole on macOS via PECL..."
            if ! command -v pecl &>/dev/null; then
                fail "PECL not found. Install PHP via Homebrew: brew install php"
            fi
            printf "\n\n\n\n\n" | pecl install swoole 2>&1 | tail -5
            ;;

        *)
            warn "Unrecognised distro '${DISTRO}'. Attempting PECL install directly..."
            if ! command -v pecl &>/dev/null; then
                fail "PECL not found. Install php-dev/php-pear for your distro first."
            fi
            printf "\n\n\n\n\n" | pecl install swoole 2>&1 | tail -5
            ;;
    esac

    # ── Enable in php.ini ─────────────────────────────────────────────────────
    if [[ -n "$PHP_INI" ]] && [[ -f "$PHP_INI" ]]; then
        if ! grep -q "extension=swoole" "$PHP_INI" 2>/dev/null; then
            info "Adding extension=swoole to $PHP_INI"
            echo "extension=swoole" >> "$PHP_INI"
            ok "php.ini updated"
        else
            ok "extension=swoole already present in php.ini"
        fi
    else
        # Try conf.d drop-in
        CONF_DIR=$(dirname "$PHP_INI" 2>/dev/null)/conf.d
        if [[ -d "$CONF_DIR" ]]; then
            echo "extension=swoole" > "$CONF_DIR/20-swoole.ini"
            ok "Created $CONF_DIR/20-swoole.ini"
        fi
    fi
fi

# ── Verify Swoole loaded ──────────────────────────────────────────────────────
echo ""
info "Verifying Swoole..."
if "$PHP_BIN" -m 2>/dev/null | grep -qi swoole; then
    SWOOLE_VER=$("$PHP_BIN" -r 'echo phpversion("swoole");' 2>/dev/null)
    ok "Swoole $SWOOLE_VER is active"
else
    fail "Swoole installation failed. Check pecl output above or try: pecl install swoole"
fi

# ── Detect Composer ───────────────────────────────────────────────────────────
echo ""
info "Detecting Composer..."
COMPOSER_BIN=$(command -v composer 2>/dev/null || true)

if [[ -z "$COMPOSER_BIN" ]]; then
    info "Composer not found — installing globally..."
    EXPECTED_SIG=$(curl -fsSL https://composer.github.io/installer.sig 2>/dev/null || true)
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php

    if [[ -n "$EXPECTED_SIG" ]]; then
        ACTUAL_SIG=$("$PHP_BIN" -r "echo hash_file('sha384', '/tmp/composer-setup.php');")
        if [[ "$EXPECTED_SIG" != "$ACTUAL_SIG" ]]; then
            rm /tmp/composer-setup.php
            fail "Composer installer signature mismatch — aborting for security."
        fi
    fi

    "$PHP_BIN" /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm /tmp/composer-setup.php
    COMPOSER_BIN=/usr/local/bin/composer
    ok "Composer installed at $COMPOSER_BIN"
else
    ok "Composer found at $COMPOSER_BIN"
fi

# ── Install kqueue/kqueue ─────────────────────────────────────────────────────
echo ""
info "Installing kqueue/kqueue..."

# Check if we're inside a Laravel project
if [[ -f "$(pwd)/artisan" ]] && [[ -f "$(pwd)/composer.json" ]]; then
    info "Laravel project detected at $(pwd)"
    "$COMPOSER_BIN" require kqueue/kqueue --no-interaction
    ok "kqueue/kqueue installed"
else
    warn "Not inside a Laravel project directory."
    warn "Run the following inside your Laravel project root:"
    echo ""
    echo "    composer require kqueue/kqueue"
    echo ""
fi

# ── Done ──────────────────────────────────────────────────────────────────────
echo ""
hr
echo -e "${GREEN}${BOLD}  KQueue installation complete!${RESET}"
hr
echo ""
echo "  Next steps:"
echo "    1. cd /your/laravel/project"
echo "    2. composer require kqueue/kqueue   (if not done above)"
echo "    3. php artisan kqueue:work"
echo ""
echo "  Optional — publish config:"
echo "    php artisan vendor:publish --tag=kqueue-config"
echo ""
hr
