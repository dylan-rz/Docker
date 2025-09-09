# HLS Edge (Nginx + VOD) with Let’s Encrypt and PHP-FPM

A production‑ready Docker stack that builds Nginx 1.26.x with the Kaltura `nginx-vod-module` for HLS packaging and caching, with automated TLS via Certbot, multi‑origin upstream support, and a PHP‑FPM sidecar for KVS control endpoints.

## Features

- Nginx from source with `nginx-vod-module` for on‑the‑fly HLS.
- Robust CORS + caching rules for manifests and segments.
- Automated HTTPS via Let’s Encrypt (webroot validation) with periodic renewal (sidecar-only).
- Hot reload on cert changes (in‑container watcher with config validation).
- Template‑driven config (envsubst) to avoid drift; no static `nginx.conf`.
- Multi‑origin support: load balance with `ORIGIN_SERVERS` or single `ORIGIN_HOST`+`ORIGIN_PORT`.
- Safe upstream Host header override via `ORIGIN_HOST_HEADER`.
- PHP‑FPM sidecar for `remote_control.php` and related KVS utilities.
- Persistent volumes for certs, cache, and logs.

## Architecture

- `nginx` (custom image): Nginx + VOD module, renders config from templates. Does not run certbot.
- `certbot` (sidecar): obtains missing certs (webroot) and renews on schedule; watcher in nginx reloads when certs change.
- `php-fpm`: executes `remote_control.php` and other PHP endpoints within the Docker network.

## Quick Start

- One‑liner (after cloning):
  - Ubuntu 24.04: `sudo ./scripts/install-ubuntu-24.04.sh --domain example.com --email admin@example.com --letsencrypt true --origin-servers "origin1.example.com:80,origin2.example.com:80" --origin-host-header origin.example.com`
- Manual:
  - Create `.env` (see Configuration) and run: `docker compose up --build`.

## Configuration (.env)

- DOMAIN: FQDN to issue cert for (e.g. `example.com`).
- EMAIL: Email for Let’s Encrypt registration.
- LETSENCRYPT: `true` to obtain/renew automatically; `false` to skip.
- ORIGIN_SERVERS: Comma‑separated upstreams for load‑balancing, e.g. `origin1:80,origin2:80`.
- ORIGIN_HOST: Single upstream hostname (when not using `ORIGIN_SERVERS`).
- ORIGIN_PORT: Port for single upstream (default `80`).
- ORIGIN_HOST_HEADER: Host header to send to upstream; defaults to `ORIGIN_HOST` or the nginx `$host` variable.
- HLS_PROXY_MAX_SIZE: Max size for the HTTPS edge proxy cache (e.g. `8000g`). Default: `8000g`.

Advanced (optional):
- WATCH_INTERVAL: Cert watcher polling interval (seconds, default `30`).
- RELOAD_MIN_INTERVAL: Debounce for reloads (seconds, default `10`).

## Install Script (Ubuntu 24.04)

- `scripts/install-ubuntu-24.04.sh`:
  - Installs Docker Engine + Compose plugin.
  - Writes `.env` from flags or env vars.
  - Creates persistent directories: `letsencrypt/`, `logs/`, `cache/`.
  - Opens ports `80`/`443` via UFW if active.
  - Starts the stack and optionally installs `docker-hls-edge.service` systemd unit.

Examples:
- `sudo ./scripts/install-ubuntu-24.04.sh --domain example.com --email admin@example.com --letsencrypt true --origin-host origin.example.com`
- `sudo ./scripts/install-ubuntu-24.04.sh --letsencrypt false --origin-servers "o1:80,o2:80" --no-service`

## Manual Setup

- Ensure Docker + Compose plugin are installed.
- Create `.env` with variables above.
- Start: `docker compose up -d --build`.
- Logs: `docker compose logs -f nginx`.

## Interactive Setup

- Run `./scripts/setup-env.sh` to be prompted for required variables and generate a `.env` file.
- Alternatively, run the installer in interactive mode on Ubuntu 24.04:
  - `sudo ./scripts/install-ubuntu-24.04.sh --interactive`
  - This will prompt for missing values, install Docker + Compose, create volumes, set up logrotate, and start the stack.

## Operations

- Test config inside container: `docker compose exec nginx /usr/local/nginx/sbin/nginx -t`.
- Reload nginx: `docker compose exec nginx /usr/local/nginx/sbin/nginx -s reload`.
- Tail upstream access: `docker compose exec nginx sh -lc 'tail -f /usr/local/nginx/logs/upstream_access.log'`.
- Check cert renewal logs: `docker compose logs -f certbot`.

## File Layout

- `docker-compose.yml`: services, volumes, healthcheck.
- `Dockerfile`: builds Nginx + VOD and installs entrypoint/watcher.
- `certbot/`:
  - `nginx.conf.template`: final HTTPS config template.
  - `nginx.nossl.template`: HTTP‑only bootstrap template.
  - `docker-entrypoint.sh`: renders config, provisions certs, generates `hls_upstream.conf`.
  - `renewal/renew.sh`: renewal loop; `renewal/watch-reload.sh`: local reload watcher.
- `php-fpm/Dockerfile`: PHP 8.2 FPM with APCu.
- `scripts/install-ubuntu-24.04.sh`: automated installer for Ubuntu 24.04.
- `logs/`, `cache/`, `letsencrypt/`: host‑mapped runtime data (git‑ignored).

## Security Notes

- `remote_control.php` is powerful; by default it allows `*` for IP/referer in the file you provided.
  - Strongly restrict by IP/network, add authentication, or serve only internally.
- Ensure `letsencrypt/` permissions are correct; do not commit certs/keys (`.gitignore` provided).
- Reduce `error_log` level from `debug` to `warn`/`error` in production.
- Consider enabling OCSP stapling: `ssl_stapling on; ssl_stapling_verify on;` when resolvers/OCSP reachable.

## Deploy From GitHub

- Curl pinned commit (public repo):
  - `curl -fsSL https://raw.githubusercontent.com/OWNER/REPO/COMMIT_SHA/scripts/install-ubuntu-24.04.sh | sudo bash -s -- --domain example.com --email admin@example.com --letsencrypt true`
- Clone and run (public/private):
  - `sudo git clone https://github.com/OWNER/REPO.git /opt/hls-edge && cd /opt/hls-edge`
  - `sudo ./scripts/install-ubuntu-24.04.sh ...`
- GitHub Actions (push‑to‑deploy over SSH or self‑hosted): add a workflow that runs the installer with secrets.

## Troubleshooting

- Certs not issued:
  - Verify DNS A/AAAA records point to this server; port 80 reachable; no other service binding 80/443.
  - Check logs: `docker compose logs -f nginx certbot`.
- CORS issues:
  - Confirm `Access-Control-Expose-Headers` includes `Content-Length,Accept-Ranges,Content-Range,X-Cache` at HTTPS edge.
- Upstream 4xx/5xx:
  - Ensure `ORIGIN_SERVERS` or `ORIGIN_HOST`/`ORIGIN_PORT` are correct and reachable; set `ORIGIN_HOST_HEADER` if the origin validates Host.
- Disk/cache:
  - Adjust cache sizes in templates to match disk capacity.

## License

This repository does not include a license by default. Add one if you plan to distribute.
