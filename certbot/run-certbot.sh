#!/bin/sh

# Helper to run certbot with the nginx plugin inside the container.
# Usage: docker compose exec nginx /usr/local/bin/run-certbot.sh example.com
# This script will run certbot interactively and attempt to obtain/renew certs.

if [ -n "$1" ]; then
  DOMAIN="$1"
fi

if [ -z "$DOMAIN" ]; then
  if [ -n "$DOMAIN" ]; then
    DOMAIN="$DOMAIN"
  else
    echo "Usage: $0 <your-domain> or set DOMAIN env var"
    exit 1
  fi
fi

echo "Running certbot for $DOMAIN using the nginx plugin"

if [ -n "$EMAIL" ]; then
  certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --email "$EMAIL"
else
  certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email
fi

echo "If you prefer http-01 standalone:"
echo "  certbot certonly --standalone -d $DOMAIN"
