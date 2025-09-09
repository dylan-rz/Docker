#!/usr/bin/env bash
set -euo pipefail

# Interactive helper to create or update a .env file for this stack.
# Prompts for DOMAIN, EMAIL, and origin settings, with sensible defaults.

repo_dir=$(cd "$(dirname "$0")/.." && pwd)
env_file="$repo_dir/.env"

confirm() {
  local prompt=${1:-"Are you sure?"}
  read -r -p "$prompt [y/N]: " ans || true
  case "${ans:-}" in
    [Yy]*) return 0;;
    *) return 1;;
  esac
}

ask() {
  local var="$1"; shift
  local msg="$1"; shift
  local def="${1:-}"; shift || true
  local val
  if [[ -n "${!var-}" ]]; then
    # keep exported value if present
    printf -v "$var" '%s' "${!var}"
    return 0
  fi
  if [[ -n "$def" ]]; then
    read -r -p "$msg [$def]: " val || true
    val="${val:-$def}"
  else
    read -r -p "$msg: " val || true
  fi
  printf -v "$var" '%s' "$val"
}

echo "Interactive .env setup (output: $env_file)"

if [[ -f "$env_file" ]]; then
  echo "A .env already exists at $env_file"
  if ! confirm "Overwrite it"; then
    echo "Aborted."
    exit 0
  fi
fi

# Core
ask DOMAIN "Domain (FQDN) for TLS (e.g. example.com)" ""
ask EMAIL "Admin email for Let's Encrypt (optional)" ""

# Origin selection
echo "\nConfigure upstream origin (choose ONE):"
echo " - Provide a comma-separated list for ORIGIN_SERVERS (e.g. origin1:80,origin2:80)"
echo " - OR, specify a single ORIGIN_HOST and ORIGIN_PORT"
ask ORIGIN_SERVERS "ORIGIN_SERVERS (comma-separated, leave blank to set single host)" ""
if [[ -z "${ORIGIN_SERVERS}" ]]; then
  ask ORIGIN_HOST "ORIGIN_HOST (e.g. origin.example.com)" ""
  ask ORIGIN_PORT "ORIGIN_PORT" "80"
fi

# Host header override
default_host_header="${ORIGIN_HOST:-}"
ask ORIGIN_HOST_HEADER "ORIGIN_HOST_HEADER to send upstream (default: ORIGIN_HOST or \$host)" "$default_host_header"

# Cache size
ask HLS_PROXY_MAX_SIZE "HTTPS proxy cache max size (e.g. 8000g)" "8000g"

cat >"$env_file" <<EOF
DOMAIN=${DOMAIN}
EMAIL=${EMAIL}
ORIGIN_SERVERS=${ORIGIN_SERVERS}
ORIGIN_HOST=${ORIGIN_HOST:-}
ORIGIN_PORT=${ORIGIN_PORT:-80}
ORIGIN_HOST_HEADER=${ORIGIN_HOST_HEADER}
HLS_PROXY_MAX_SIZE=${HLS_PROXY_MAX_SIZE}
EOF

echo "\nWrote $env_file"
echo "You can now run: docker compose up -d --build"

