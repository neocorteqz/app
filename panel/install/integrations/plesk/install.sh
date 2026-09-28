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
PLESK="/usr/local/psa/admin/sbin/httpdmng"
DOMAIN_CONF="/var/www/vhosts/system/${DOMAIN}/conf"
[[ -x "$PLESK" && -d "$DOMAIN_CONF" ]] || { echo "Plesk domain configuration not found for ${DOMAIN}." >&2; exit 1; }

install -d -m 0755 "$DOMAIN_CONF"
custom_conf="$DOMAIN_CONF/vhost_nginx.conf"
tmp_conf=$(mktemp)
if [[ -f "$custom_conf" ]]; then
  sed '/# BEGIN APEXNODE PROXY/,/# END APEXNODE PROXY/d' "$custom_conf" >"$tmp_conf"
fi
cat >>"$tmp_conf" <<NGINX
# BEGIN APEXNODE PROXY
location / {
    proxy_pass http://127.0.0.1:${PORT};
    proxy_http_version 1.1;
    proxy_set_header Host \$host;
    proxy_set_header X-Real-IP \$remote_addr;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto \$scheme;
}
# END APEXNODE PROXY
NGINX
install -m 0644 "$tmp_conf" "$custom_conf"
rm -f "$tmp_conf"
"$PLESK" --reconfigure-domain "$DOMAIN"
echo "ApexNode proxy configured for ${DOMAIN} on port ${PORT}."