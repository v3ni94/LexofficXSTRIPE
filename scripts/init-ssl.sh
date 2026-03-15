#!/bin/bash
set -e

DOMAIN=${1:?"Usage: $0 <domain> <email>"}
EMAIL=${2:?"Usage: $0 <domain> <email>"}

echo "=== Erstelle SSL-Zertifikat fuer $DOMAIN ==="

# Start nginx without SSL first (for ACME challenge)
echo "Starte nginx fuer ACME-Challenge..."
docker-compose up -d nginx

# Wait for nginx to be ready
sleep 5

# Request certificate
echo "Fordere Zertifikat an..."
docker-compose run --rm certbot certonly \
  --webroot \
  --webroot-path=/var/www/certbot \
  --email "$EMAIL" \
  --agree-tos \
  --no-eff-email \
  -d "$DOMAIN"

# Restart nginx with SSL config
echo "Starte nginx mit SSL-Konfiguration neu..."
docker-compose restart nginx

echo "=== SSL-Zertifikat erfolgreich erstellt ==="
echo "=== Die App ist jetzt unter https://$DOMAIN erreichbar ==="
