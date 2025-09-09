Automated Let's Encrypt with environment variables

This small helper adds templating and non-interactive certbot support to the nginx image.

Environment variables (can be set in a .env file or your shell):
- DOMAIN: the domain name to issue a certificate for (e.g. example.com)
- EMAIL: optional email used to register with Let's Encrypt
- LETSENCRYPT: set to "true" to attempt automatic obtain/renew at container start
 - ORIGIN_HOST: optional hostname of your upstream origin used by the VOD module (e.g. origin.example.com)
 - ORIGIN_PORT: optional port for the upstream origin (default: 80)
 - ORIGIN_SERVERS: optional comma-separated list of upstream servers for load-balancing (e.g. "origin1:80,origin2:80")
 - ORIGIN_HOST_HEADER: optional Host header to send to the origin; defaults to ORIGIN_HOST if set, otherwise uses nginx variable $host

How it works:
- On container start the entrypoint renders `/usr/local/nginx/conf/nginx.conf` from `nginx.conf.template` using `envsubst`.
- If `LETSENCRYPT=true` and `DOMAIN` is present the entrypoint will call certbot non-interactively to obtain/renew certs.
 - The entrypoint also generates `/usr/local/nginx/conf/hls_upstream.conf`:
   - If `ORIGIN_SERVERS` is set, it creates multiple `server` lines (split by commas).
   - Else if `ORIGIN_HOST`/`ORIGIN_PORT` are set, it creates a single `server ORIGIN_HOST:ORIGIN_PORT;`.
   - Else it falls back to the example `origin-test.origin-videos.com:80`.
 - For upstream requests from the internal VOD location, the `Host` header is set to `${ORIGIN_HOST_HEADER}`; if unset it will default to `$host` at nginx runtime.

Usage examples:

# Using a .env file with docker compose
DOMAIN=example.com
EMAIL=admin@example.com
LETSENCRYPT=true

# On Ubuntu 24.04, you can automate install/start with:
#   sudo ./scripts/install-ubuntu-24.04.sh --domain "$DOMAIN" --email "$EMAIL" --letsencrypt true \
#       --origin-servers "origin1.example.com:80,origin2.example.com:80" --origin-host-header origin.example.com
# This installs Docker + Compose, writes .env, opens ports 80/443 (ufw), builds and starts the stack,
# and installs a systemd unit named docker-hls-edge.service

Then run:

docker compose up --build

Notes:
- Certbot uses the nginx plugin and requires that the nginx process inside the container can bind to port 80.
- The entrypoint continues even if certbot fails so the container will still start.
- For production you'll want to mount `./letsencrypt` persistent volumes (already configured in `docker-compose.yml`) so certs persist between restarts.
