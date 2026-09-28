#!/usr/bin/env bash
set -euo pipefail

DOMAIN=""
PANEL_USER=""
PORT="8443"
while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain) DOMAIN="${2:?}"; shift 2 ;;
    --panel-user) PANEL_USER="${2:?}"; shift 2 ;;
    --port) PORT="${2:?}"; shift 2 ;;
    *) echo "Unknown option: $1" >&2; exit 2 ;;
  esac
done
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root." >&2; exit 1; }
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || { echo "Use --domain example.com." >&2; exit 2; }
[[ "$PANEL_USER" =~ ^[A-Za-z0-9_-]+$ ]] || { echo "Use --panel-user CPANEL_ACCOUNT." >&2; exit 2; }
[[ "$PORT" =~ ^[0-9]+$ ]] && (( PORT > 0 && PORT < 65536 )) || { echo "Invalid port." >&2; exit 2; }
[[ -x /scripts/ensure_vhost_includes && -x /scripts/rebuildhttpdconf ]] || { echo "cPanel Apache include tools were not found." >&2; exit 1; }

for protocol in std ssl; do
  dir="/etc/apache2/conf.d/userdata/${protocol}/2_4/${PANEL_USER}/${DOMAIN}"
  install -d -m 0755 "$dir"
  cat >"$dir/apexnode.conf" <<APACHE
<IfModule proxy_module>
  ProxyPreserveHost On
  $([[ "$protocol" == "ssl" ]] && printf 'RequestHeader set X-Forwarded-Proto "https"\n' || printf 'Redirect permanent / https://%s/\n' "$DOMAIN")
  $([[ "$protocol" == "ssl" ]] && printf 'ProxyPass / http://127.0.0.1:%s/ retry=0\nProxyPassReverse / http://127.0.0.1:%s/\n' "$PORT" "$PORT")
</IfModule>
APACHE
done
/scripts/ensure_vhost_includes --user="$PANEL_USER"
/scripts/rebuildhttpdconf
/scripts/restartsrv_httpd
echo "ApexNode proxy configured for ${DOMAIN} (${PANEL_USER}) on port ${PORT}."