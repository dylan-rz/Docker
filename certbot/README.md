Automated Let's Encrypt (sidecar-only) with environment variables

This helper documents templating in the nginx image and non-interactive certbot obtain/renew in the certbot sidecar.

Environment variables (can be set in a .env file or your shell):
- DOMAIN: the domain name to issue a certificate for (e.g. example.com)
- EMAIL: optional email used to register with Let's Encrypt
- LETSENCRYPT: set to "true" to attempt automatic obtain/renew at container start
 - ORIGIN_HOST: optional hostname of your upstream origin used by the VOD module (e.g. origin.example.com)
 - ORIGIN_PORT: optional port for the upstream origin (default: 80)
 - ORIGIN_SERVERS: optional comma-separated list of upstream servers for load-balancing (e.g. "origin1:80,origin2:80")
- ORIGIN_HOST_HEADER: optional Host header to send to the origin; defaults to ORIGIN_HOST if set, otherwise uses nginx variable $host
 - HLS_PROXY_MAX_SIZE: optional max size for the HTTPS proxy cache (e.g. 8000g). Defaults to 8000g if unset.

How it works:
- On container start the entrypoint renders `/usr/local/nginx/conf/nginx.conf` from `nginx.conf.template` using `envsubst`.
- If `LETSENCRYPT=true` and `DOMAIN` is present the entrypoint will call certbot non-interactively to obtain/renew certs.
- The entrypoint also generates `/usr/local/nginx/conf/hls_upstream.conf`:
   - If `ORIGIN_SERVERS` is set, it creates multiple `server` lines (split by commas).
   - Else if `ORIGIN_HOST`/`ORIGIN_PORT` are set, it creates a single `server ORIGIN_HOST:ORIGIN_PORT;`.
   - Else it falls back to the example `origin-test.origin-videos.com:80`.
- For upstream requests from the internal VOD location, the `Host` header is set to `${ORIGIN_HOST_HEADER}`; if unset it will default to `$host` at nginx runtime.
 - The `proxy_cache_path` for `hls_proxy_cache` uses `$HLS_PROXY_MAX_SIZE`; the entrypoint defaults this to `8000g` if not provided.

Usage examples:

# Using a .env file with docker compose
DOMAIN=example.com
EMAIL=admin@example.com
LETSENCRYPT=true  # no longer used by nginx; certbot sidecar handles obtain/renew

# On Ubuntu 24.04, you can automate install/start with:
#   sudo ./scripts/install-ubuntu-24.04.sh --domain "$DOMAIN" --email "$EMAIL" --letsencrypt true \
#       --origin-servers "origin1.example.com:80,origin2.example.com:80" --origin-host-header origin.example.com
# This installs Docker + Compose, writes .env, opens ports 80/443 (ufw), builds and starts the stack,
# and installs a systemd unit named docker-hls-edge.service

Then run:

docker compose up --build

Notes:
- The certbot sidecar uses webroot validation at `/var/lib/letsencrypt` served by nginx (HTTP on port 80).
- Nginx renders a temporary HTTP-only config if certs are missing, and the watcher reloads nginx after certs arrive.
- Ensure `./letsencrypt` volumes are mounted (configured in `docker-compose.yml`) so certs persist between restarts.
