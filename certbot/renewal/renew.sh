#!/bin/sh
set -e

# This script runs inside the certbot container. It periodically attempts to renew
# certificates and reloads nginx in the nginx container if any certs were renewed.

RENEW_INTERVAL_SECONDS=${RENEW_INTERVAL_SECONDS:-43200} # default 12 hours
NGINX_SERVICE=${NGINX_SERVICE:-nginx}

while true; do
  echo "[renew.sh] Running certbot renew..."
  # Run renew; --deploy-hook will run on each renewed cert but cannot reload nginx across containers,
  # so we check the output for 'No renewals were attempted' vs 'Congratulations' lines.
  OUTPUT=$(certbot renew --quiet --deploy-hook "echo renewed" 2>&1 || true)
  echo "$OUTPUT"

  if echo "$OUTPUT" | grep -q "Congratulations" || echo "$OUTPUT" | grep -q "renewed"; then
    echo "[renew.sh] Certificates renewed; nginx watcher will reload the server"
  else
    echo "[renew.sh] No certificates renewed"
  fi

  sleep "$RENEW_INTERVAL_SECONDS"
done
