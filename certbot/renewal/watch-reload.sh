#!/bin/sh
set -e

# Watch Let's Encrypt certs and reload the local nginx process when they change.
# Runs inside the nginx container (no docker CLI required).

LIVE_DIR=${LETSENCRYPT_LIVE_DIR:-/etc/letsencrypt/live}
INTERVAL=${WATCH_INTERVAL:-30}
MIN_INTERVAL=${RELOAD_MIN_INTERVAL:-10}   # minimum seconds between reloads (debounce)

# If a DOMAIN is specified and its dir exists, watch only that subtree for fewer spurious reloads.
if [ -n "$DOMAIN" ] && [ -d "$LIVE_DIR/$DOMAIN" ]; then
  WATCH_PATH="$LIVE_DIR/$DOMAIN"
else
  WATCH_PATH="$LIVE_DIR"
fi

echo "[watch-reload] Watching: $WATCH_PATH (interval=${INTERVAL}s, debounce=${MIN_INTERVAL}s)"

calc_state() {
  # Hash mtimes of relevant files; fall back to directory mtime if find/sha256sum missing
  if command -v find >/dev/null 2>&1 && command -v sha256sum >/dev/null 2>&1; then
    # Consider PEM files and symlinks in live/; sorting ensures stable hash
    find "$WATCH_PATH" -type f \( -name '*.pem' -o -name 'privkey*.pem' -o -name 'fullchain*.pem' \) \
      -printf '%P %T@\n' 2>/dev/null | sort | sha256sum | awk '{print $1}'
  else
    stat -c %Y "$WATCH_PATH" 2>/dev/null || stat -f %m "$WATCH_PATH" 2>/dev/null || echo 0
  fi
}

last_state=""
last_reload_ts=0

has_certs() {
  [ -n "$DOMAIN" ] && [ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ] && [ -f "/etc/letsencrypt/live/$DOMAIN/privkey.pem" ]
}

render_https_if_possible() {
  # If certs exist, render the HTTPS config from template; otherwise keep current config
  if has_certs; then
    echo "[watch-reload] Rendering HTTPS config from template (certs present for $DOMAIN)"
    # Use same variables exported by entrypoint
    envsubst '\$DOMAIN \$EMAIL \$ORIGIN_HOST_HEADER \$HLS_PROXY_MAX_SIZE' < /usr/local/nginx/conf/nginx.conf.template > /usr/local/nginx/conf/nginx.conf || true
  fi
}

while true; do
  if [ -d "$WATCH_PATH" ]; then
    state=$(calc_state || echo "")
    now=$(date +%s)
    if [ "$state" != "$last_state" ]; then
      if [ $(( now - last_reload_ts )) -lt "$MIN_INTERVAL" ]; then
        echo "[watch-reload] Change detected but debounced (last reload $(( now - last_reload_ts ))s ago)"
      else
        echo "[watch-reload] Certificate change detected. Updating config, validating, and reloading nginx..."
        render_https_if_possible
        if /usr/local/nginx/sbin/nginx -t; then
          /usr/local/nginx/sbin/nginx -s reload || true
          last_reload_ts=$now
          echo "[watch-reload] Reloaded nginx"
        else
          echo "[watch-reload] nginx -t failed; skipping reload" >&2
        fi
      fi
      last_state="$state"
    fi
  fi
  sleep "$INTERVAL"
done
