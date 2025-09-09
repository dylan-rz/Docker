#!/bin/sh
set -e

# Periodically ensures certificate exists (obtain if missing) and renews thereafter.

RENEW_INTERVAL_SECONDS=${RENEW_INTERVAL_SECONDS:-43200} # default 12 hours

has_certs() {
  [ -n "$DOMAIN" ] && [ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ] && [ -f "/etc/letsencrypt/live/$DOMAIN/privkey.pem" ]
}

obtain_if_missing() {
  if [ -z "$DOMAIN" ]; then
    echo "[renew.sh] DOMAIN not set; skipping obtain"
    return 0
  fi
  if has_certs; then
    return 0
  fi
  echo "[renew.sh] No certificates found for $DOMAIN - attempting initial obtain via webroot"
  if [ -n "$EMAIL" ]; then
    args="--non-interactive --agree-tos --email $EMAIL"
  else
    args="--non-interactive --agree-tos --register-unsafely-without-email"
  fi
  certbot certonly --webroot -w /var/lib/letsencrypt $args -d "$DOMAIN" || echo "[renew.sh] initial obtain failed (will retry later)"
}

while true; do
  obtain_if_missing

  echo "[renew.sh] Running certbot renew..."
  OUTPUT=$(certbot renew --quiet --deploy-hook "echo renewed" 2>&1 || true)
  echo "$OUTPUT"

  if echo "$OUTPUT" | grep -q "Congratulations" || echo "$OUTPUT" | grep -q "renewed"; then
    echo "[renew.sh] Certificates renewed; nginx in-container watcher will reload"
  else
    echo "[renew.sh] No certificates renewed"
  fi

  sleep "$RENEW_INTERVAL_SECONDS"
done
