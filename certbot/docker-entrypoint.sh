#!/bin/sh
set -e

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

echo "ENTRYPOINT: DOMAIN='$DOMAIN' EMAIL='$EMAIL' ORIGIN_HOST='${ORIGIN_HOST}' ORIGIN_PORT='${ORIGIN_PORT}' ORIGIN_SERVERS='${ORIGIN_SERVERS}'"

# Prepare upstream and header variables before any templating
write_upstream_conf
ensure_host_header_var

# Default and export cache sizing variable used in nginx.conf.template
HLS_PROXY_MAX_SIZE="${HLS_PROXY_MAX_SIZE:-8000g}"
export HLS_PROXY_MAX_SIZE

# Always start a background watcher that reloads nginx if certificates change
if [ -x /usr/local/bin/watch-reload.sh ] && [ -n "$DOMAIN" ]; then
  /usr/local/bin/watch-reload.sh &
fi

# Render config based on current cert presence
if has_certs; then
  echo "Certificates present for $DOMAIN - rendering final nginx config"
  envsubst '\$DOMAIN \$EMAIL \$ORIGIN_HOST_HEADER \$HLS_PROXY_MAX_SIZE' < /usr/local/nginx/conf/nginx.conf.template > /usr/local/nginx/conf/nginx.conf
else
  echo "Certificates missing - rendering temporary HTTP-only config"
  envsubst '\$DOMAIN \$EMAIL \$ORIGIN_HOST_HEADER \$HLS_PROXY_MAX_SIZE' < /usr/local/nginx/conf/nginx.nossl.template > /usr/local/nginx/conf/nginx.conf
fi

exec "$@"
