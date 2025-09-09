#!/bin/sh
set -e

# Normalize LETSENCRYPT to lowercase in a portable way (POSIX sh doesn't support ${VAR,,})
LETSENCRYPT_LOWER="$(printf '%s' "$LETSENCRYPT" | tr '[:upper:]' '[:lower:]')"

# Helpers
has_certs() {
  [ -n "$DOMAIN" ] && [ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ] && [ -f "/etc/letsencrypt/live/$DOMAIN/privkey.pem" ]
}

# Build upstream config file from env vars
write_upstream_conf() {
  conf_path="/usr/local/nginx/conf/hls_upstream.conf"
  mkdir -p "$(dirname "$conf_path")"

  # Decide servers list
  servers=""
  if [ -n "$ORIGIN_SERVERS" ]; then
    # Comma or whitespace separated host[:port][ options]
    IFS=','
    for item in $ORIGIN_SERVERS; do
      # trim whitespace
      item_trimmed=$(printf '%s' "$item" | awk '{$1=$1;print}')
      [ -n "$item_trimmed" ] && servers="$servers\n    server $item_trimmed;"
    done
    unset IFS
  elif [ -n "$ORIGIN_HOST" ] || [ -n "$ORIGIN_PORT" ]; then
    host_val=${ORIGIN_HOST:-127.0.0.1}
    port_val=${ORIGIN_PORT:-80}
    servers="\n    server ${host_val}:${port_val};"
  else
    # Fallback to the original example
    servers="\n    server origin-test.origin-videos.com:80;"
  fi

  cat >"$conf_path" <<EOF
upstream hls_upstream {${servers}
}
EOF
  echo "ENTRYPOINT: wrote upstream config to $conf_path" >&2
}

# Decide Host header override for upstream requests.
# If ORIGIN_HOST_HEADER not provided:
#   - use ORIGIN_HOST when set
#   - otherwise fall back to literal nginx variable $host
ensure_host_header_var() {
  if [ -z "$ORIGIN_HOST_HEADER" ]; then
    if [ -n "$ORIGIN_HOST" ]; then
      ORIGIN_HOST_HEADER="$ORIGIN_HOST"
    else
      ORIGIN_HOST_HEADER='$host'
    fi
    export ORIGIN_HOST_HEADER
  fi
}

echo "ENTRYPOINT: DOMAIN='$DOMAIN' EMAIL='$EMAIL' LETSENCRYPT='${LETSENCRYPT:-false}' ORIGIN_HOST='${ORIGIN_HOST}' ORIGIN_PORT='${ORIGIN_PORT}' ORIGIN_SERVERS='${ORIGIN_SERVERS}'"

# Prepare upstream and header variables before any templating
write_upstream_conf
ensure_host_header_var

if [ -n "$DOMAIN" ] && [ "$LETSENCRYPT_LOWER" = "true" ]; then
  # Start a background watcher that reloads nginx if certificates change
  if [ -x /usr/local/bin/watch-reload.sh ]; then
    /usr/local/bin/watch-reload.sh &
  fi
  # If certs already present, render final config and start normally
  if has_certs; then
    echo "Certificates already present for $DOMAIN - rendering final nginx config"
    envsubst '\$DOMAIN \$EMAIL \$ORIGIN_HOST_HEADER' < /usr/local/nginx/conf/nginx.conf.template > /usr/local/nginx/conf/nginx.conf
    # Exec the provided CMD (start nginx in foreground)
    exec "$@"
  fi

  # No certs yet - start nginx with a safe no-SSL config so the container can run
  echo "No certificates found for $DOMAIN - starting temporary HTTP-only nginx to allow validation"
  envsubst '\$DOMAIN \$EMAIL \$ORIGIN_HOST_HEADER' < /usr/local/nginx/conf/nginx.nossl.template > /usr/local/nginx/conf/nginx.conf

  # Start nginx as daemon so we can run certbot standalone which needs port 80
  /usr/local/nginx/sbin/nginx || true

  # Prepare certbot args
  if [ -n "$EMAIL" ]; then
    certbot_args="--non-interactive --agree-tos --email $EMAIL"
  else
    certbot_args="--non-interactive --agree-tos --register-unsafely-without-email"
  fi

  echo "Running certbot using webroot to obtain cert for $DOMAIN"
  # Use webroot so existing temporary nginx serves the HTTP-01 challenge on port 80
  certbot certonly --webroot -w /var/lib/letsencrypt $certbot_args -d "$DOMAIN" || echo "certbot failed (continuing)"

  # If certs obtained, render final SSL config
  if has_certs; then
    echo "Certificates obtained, rendering final SSL nginx config"
    envsubst '\$DOMAIN \$EMAIL \$ORIGIN_HOST_HEADER' < /usr/local/nginx/conf/nginx.conf.template > /usr/local/nginx/conf/nginx.conf
  else
    echo "Certificates still missing after certbot run - leaving temporary config in place"
  fi

  # Stop the daemon nginx (we'll start it in foreground)
  /usr/local/nginx/sbin/nginx -s stop || true

  # Start nginx in foreground (PID 1) so docker keeps the container alive
  exec /usr/local/nginx/sbin/nginx -g 'daemon off;'
else
  # No domain/letsencrypt requested - leave config as-is or render if DOMAIN provided
  if [ -n "$DOMAIN" ]; then
    echo "Rendering nginx config from template for domain: $DOMAIN (LETSENCRYPT not enabled)"
    envsubst '\$DOMAIN \$EMAIL \$ORIGIN_HOST_HEADER' < /usr/local/nginx/conf/nginx.conf.template > /usr/local/nginx/conf/nginx.conf
  else
    echo "No DOMAIN provided - rendering temporary HTTP-only config (nossl template)"
    envsubst '\$DOMAIN \$EMAIL \$ORIGIN_HOST_HEADER' < /usr/local/nginx/conf/nginx.nossl.template > /usr/local/nginx/conf/nginx.conf
  fi
  exec "$@"
fi
