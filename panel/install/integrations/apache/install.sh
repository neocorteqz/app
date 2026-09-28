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
if command -v a2enmod >/dev/null; then
  a2enmod proxy proxy_http headers >/dev/null
  CONF_DIR="/etc/apache2/conf-available"
  SERVICE="apache2"
elif command -v httpd >/dev/null; then
  CONF_DIR="/etc/httpd/conf.d"
  SERVICE="httpd"
else
  echo "Apache (apache2/httpd) was not found." >&2; exit 1
fi
install -d -m 0755 "$CONF_DIR"
CONF="$CONF_DIR/apexnode-${DOMAIN}.conf"
if grep -R -Eiq --exclude="$(basename "$CONF")" "^[[:space:]]*ServerName[[:space:]]+${DOMAIN//./\\.}([[:space:]]|$)" /etc/apache2/sites-enabled /etc/apache2/conf-enabled /etc/httpd/conf.d 2>/dev/null; then
  echo "Apache already has a vhost for ${DOMAIN}; configure the existing vhost to proxy to 127.0.0.1:${PORT} instead of adding a duplicate." >&2
  exit 1
fi
if ! apachectl -M 2>/dev/null | grep -q 'headers_module'; then
  echo "Apache mod_headers is required. Enable it (for example, a2enmod headers) and rerun." >&2
  exit 1
fi
TMP_CONF=$(mktemp)
trap 'rm -f "$TMP_CONF"' EXIT
cat >"$TMP_CONF" <<APACHE
<VirtualHost *:80>
    ServerName ${DOMAIN}
    ProxyPreserveHost On
    RequestHeader set X-Forwarded-Proto "http"
    ProxyPass / http://127.0.0.1:${PORT}/ retry=0
    ProxyPassReverse / http://127.0.0.1:${PORT}/
</VirtualHost>
APACHE
install -m 0644 "$TMP_CONF" "$CONF"
if command -v a2enconf >/dev/null; then a2enconf "apexnode-${DOMAIN}" >/dev/null; fi
apachectl configtest
systemctl reload "$SERVICE"
echo "Apache proxy configured for http://${DOMAIN}; configure TLS in your existing certificate manager if needed."
