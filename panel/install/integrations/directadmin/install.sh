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
[[ "$PANEL_USER" =~ ^[A-Za-z0-9_-]+$ ]] || { echo "Use --panel-user DIRECTADMIN_ACCOUNT." >&2; exit 2; }
[[ "$PORT" =~ ^[0-9]+$ ]] && (( PORT > 0 && PORT < 65536 )) || { echo "Invalid port." >&2; exit 2; }
DA_USER_DIR="/usr/local/directadmin/data/users/${PANEL_USER}"
[[ -d "$DA_USER_DIR/domains" ]] || { echo "DirectAdmin account not found: ${PANEL_USER}" >&2; exit 1; }
[[ -f "$DA_USER_DIR/domains/${DOMAIN}.conf" ]] || { echo "Domain ${DOMAIN} is not assigned to ${PANEL_USER}." >&2; exit 1; }
BUILD="/usr/local/directadmin/custombuild/build"
[[ -x "$BUILD" ]] || { echo "DirectAdmin CustomBuild executable not found." >&2; exit 1; }

custom_file="$DA_USER_DIR/domains/${DOMAIN}.cust_httpd"
install -d -m 0750 "$DA_USER_DIR/domains"
tmp_file=$(mktemp)
if [[ -f "$custom_file" ]]; then
  sed '/# BEGIN APEXNODE PROXY/,/# END APEXNODE PROXY/d' "$custom_file" >"$tmp_file"
fi
cat >>"$tmp_file" <<APACHE
# BEGIN APEXNODE PROXY
ProxyPreserveHost On
RequestHeader set X-Forwarded-Proto expr=%{REQUEST_SCHEME}
ProxyPass / http://127.0.0.1:${PORT}/ retry=0
ProxyPassReverse / http://127.0.0.1:${PORT}/
# END APEXNODE PROXY
APACHE
install -m 0640 "$tmp_file" "$custom_file"
rm -f "$tmp_file"
chown "${PANEL_USER}:${PANEL_USER}" "$custom_file"
"$BUILD" rewrite_confs
echo "ApexNode proxy configured for ${DOMAIN} (${PANEL_USER}) on port ${PORT}."