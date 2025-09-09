#!/usr/bin/env bash
set -euo pipefail

# Automates setup on Ubuntu 24.04 (Noble):
# - Installs Docker Engine + Compose plugin
# - Creates .env with provided variables
# - Creates persistent volumes (letsencrypt, logs, cache)
# - Opens firewall ports 80/443 (ufw if active)
# - Starts the stack with docker compose
# - Optionally installs a systemd unit to manage the stack

usage() {
  cat <<EOF
Usage: sudo ./scripts/install-ubuntu-24.04.sh [options]

Options (env or flags):
  --domain DOMAIN                 FQDN for TLS (e.g. example.com)
  --email EMAIL                   Email for Let's Encrypt registration
  --letsencrypt true|false        Enable automated cert provisioning (default: false)
  --origin-host HOST              Upstream origin host for VOD module
  --origin-port PORT              Upstream origin port (default: 80)
  --origin-servers LIST           Comma-separated upstream servers (e.g. host1:80,host2:80)
  --origin-host-header VALUE      Host header to send upstream (default: uses --origin-host or \$host)
  --interactive                   Prompt for missing values and write .env
  --no-service                    Do not install a systemd service (just start once)
  --help                          Show this help

Environment variables equivalent to flags are also supported:
  DOMAIN, EMAIL, LETSENCRYPT, ORIGIN_HOST, ORIGIN_PORT, ORIGIN_SERVERS, ORIGIN_HOST_HEADER

Prereqs:
  - Public DNS A record for DOMAIN should point to this server if LETSENCRYPT=true
  - Run as root (sudo)
EOF
}

require_root() {
  if [[ $(id -u) -ne 0 ]]; then
    echo "Please run as root (sudo)." >&2
    exit 1
  fi
}

parse_args() {
  NO_SERVICE=0
  INTERACTIVE=0
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --domain) DOMAIN="$2"; shift 2;;
      --email) EMAIL="$2"; shift 2;;
      --letsencrypt) LETSENCRYPT="$2"; shift 2;;
      --origin-host) ORIGIN_HOST="$2"; shift 2;;
      --origin-port) ORIGIN_PORT="$2"; shift 2;;
      --origin-servers) ORIGIN_SERVERS="$2"; shift 2;;
      --origin-host-header) ORIGIN_HOST_HEADER="$2"; shift 2;;
      --interactive) INTERACTIVE=1; shift;;
      --no-service) NO_SERVICE=1; shift;;
      --help|-h) usage; exit 0;;
      *) echo "Unknown option: $1" >&2; usage; exit 1;;
    esac
  done
}

install_docker() {
  echo "[install] Installing Docker Engine + Compose plugin..."
  apt-get update -y
  apt-get install -y ca-certificates curl gnupg lsb-release

  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  chmod a+r /etc/apt/keyrings/docker.gpg

  . /etc/os-release
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $VERSION_CODENAME stable" > /etc/apt/sources.list.d/docker.list
  apt-get update -y
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  systemctl enable --now docker

  if ! command -v docker >/dev/null; then
    echo "Docker did not install correctly (docker not in PATH)." >&2
    exit 1
  fi
}

ensure_compose() {
  if ! docker compose version >/dev/null 2>&1; then
    echo "[install] docker compose plugin not found. Ensure docker-compose-plugin is installed." >&2
    exit 1
  fi
}

setup_env_and_dirs() {
  local repo_dir
  repo_dir=$(cd "$(dirname "$0")/.." && pwd)
  echo "[setup] Repo: $repo_dir"

  # Create .env if missing
  local env_file="$repo_dir/.env"
  : "${LETSENCRYPT:=false}"
  : "${ORIGIN_PORT:=80}"

  if [[ ! -f "$env_file" ]]; then
    if [[ ${INTERACTIVE:-0} -eq 1 ]] || [[ -t 0 ]]; then
      echo "[setup] Launching interactive .env creation"
      # Use bash to execute the helper to avoid relying on the executable bit/noexec mounts
      ( cd "$repo_dir" && DOMAIN="${DOMAIN:-}" EMAIL="${EMAIL:-}" ORIGIN_HOST="${ORIGIN_HOST:-}" ORIGIN_PORT="${ORIGIN_PORT:-}" ORIGIN_SERVERS="${ORIGIN_SERVERS:-}" ORIGIN_HOST_HEADER="${ORIGIN_HOST_HEADER:-}" HLS_PROXY_MAX_SIZE="${HLS_PROXY_MAX_SIZE:-}" bash ./scripts/setup-env.sh )
    else
      echo "[setup] Writing .env"
      cat > "$env_file" <<EOF
DOMAIN=${DOMAIN:-}
EMAIL=${EMAIL:-}
LETSENCRYPT=${LETSENCRYPT}
ORIGIN_HOST=${ORIGIN_HOST:-}
ORIGIN_PORT=${ORIGIN_PORT}
ORIGIN_SERVERS=${ORIGIN_SERVERS:-}
ORIGIN_HOST_HEADER=${ORIGIN_HOST_HEADER:-}
HLS_PROXY_MAX_SIZE=${HLS_PROXY_MAX_SIZE:-}
EOF
    fi
  else
    echo "[setup] .env already exists; leaving as-is"
  fi

  # Persistent volumes
  mkdir -p "$repo_dir/letsencrypt/etc" "$repo_dir/letsencrypt/var" "$repo_dir/letsencrypt/log" \
           "$repo_dir/logs" "$repo_dir/cache"

  # Open firewall ports if ufw is active
  if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then
    echo "[setup] UFW active; allowing 80/tcp and 443/tcp"
    ufw allow 80/tcp || true
    ufw allow 443/tcp || true
  fi
}

setup_logrotate() {
  echo "[install] Setting up logrotate for host-mapped logs"
  apt-get install -y logrotate >/dev/null 2>&1 || true

  local repo_dir conf
  repo_dir=$(cd "$(dirname "$0")/.." && pwd)
  conf=/etc/logrotate.d/hls-edge

  cat > "$conf" <<EOF
# Auto-generated by install-ubuntu-24.04.sh
# Rotates host-mapped logs written by containers

${repo_dir}/logs/*.log {
  daily
  rotate 14
  size 50M
  missingok
  notifempty
  compress
  delaycompress
  copytruncate
}

${repo_dir}/letsencrypt/log/*.log {
  weekly
  rotate 8
  size 20M
  missingok
  notifempty
  compress
  delaycompress
  copytruncate
}
EOF

  echo "[install] Installed logrotate rules at $conf"
  # Validate logrotate config syntax (dry run)
  logrotate -d /etc/logrotate.conf >/dev/null 2>&1 || true
}

start_stack() {
  local repo_dir
  repo_dir=$(cd "$(dirname "$0")/.." && pwd)
  echo "[start] Bringing up stack..."
  (cd "$repo_dir" && docker compose up -d --build)
}

install_service() {
  local repo_dir service_file
  repo_dir=$(cd "$(dirname "$0")/.." && pwd)
  service_file=/etc/systemd/system/docker-hls-edge.service

  echo "[service] Installing systemd unit: $service_file"
  cat > "$service_file" <<EOF
[Unit]
Description=HLS Edge (Nginx + VOD) via Docker Compose
Requires=docker.service
After=docker.service network-online.target
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=true
WorkingDirectory=$repo_dir
Environment=COMPOSE_PROJECT_NAME=hls-edge
ExecStart=/usr/bin/docker compose up -d --build
ExecStop=/usr/bin/docker compose down
TimeoutStartSec=0

[Install]
WantedBy=multi-user.target
EOF

  systemctl daemon-reload
  systemctl enable --now docker-hls-edge.service
  systemctl status --no-pager docker-hls-edge.service || true
}

main() {
  require_root
  parse_args "$@"
  install_docker
  ensure_compose
  setup_env_and_dirs
  setup_logrotate
  start_stack
  if [[ ${NO_SERVICE:-0} -eq 0 ]]; then
    install_service
  else
    echo "[service] Skipping systemd installation (--no-service)"
  fi

  echo "\nDone. Useful commands:"
  echo "  docker compose ps"
  echo "  docker compose logs -f nginx"
  echo "  systemctl restart docker-hls-edge.service    # if installed"
}

main "$@"
