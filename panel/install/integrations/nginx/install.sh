#!/usr/bin/env bash
set -euo pipefail

DOMAIN=""
PORT="8443"
while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain) DOMAIN="${2:?}"; shift 2 ;;
    --port) PORT="${2:?}"; shift 2 ;;
    *) echo "Unknown option: $1" >&2; exit 2 ;;
  esac
done
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root." >&2; exit 1; }
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || { echo "Use --domain example.com." >&2; exit 2; }
[[ "$PORT" =~ ^[0-9]+$ ]] && (( PORT > 0 && PORT < 65536 )) || { echo "Invalid port." >&2; exit 2; }
command -v nginx >/dev/null || { echo "Nginx is not installed." >&2; exit 1; }

cat >"/etc/nginx/conf.d/apexnode-${DOMAIN}.conf" <<NGINX
server {
    listen 80;
    server_name ${DOMAIN};
    location / {
        proxy_pass http://127.0.0.1:${PORT};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
NGINX
nginx -t
systemctl reload nginx
echo "ApexNode proxy configured for ${DOMAIN} on port ${PORT}."