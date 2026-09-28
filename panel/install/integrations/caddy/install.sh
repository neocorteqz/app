#!/usr/bin/env bash
set -euo pipefail
DOMAIN=""
PORT="8443"
CADDYFILE="${CADDYFILE:-/etc/caddy/Caddyfile}"
while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain) DOMAIN="${2:?}"; shift 2 ;;
    --port) PORT="${2:?}"; shift 2 ;;
    --caddyfile) CADDYFILE="${2:?}"; shift 2 ;;
    *) echo "Unknown option: $1" >&2; exit 2 ;;
  esac
done
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root." >&2; exit 1; }
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || { echo "Use --domain example.com." >&2; exit 2; }
[[ "$PORT" =~ ^[0-9]+$ ]] && (( PORT > 0 && PORT < 65536 )) || { echo "Invalid port." >&2; exit 2; }
command -v caddy >/dev/null || { echo "Caddy was not found." >&2; exit 1; }
[[ -f "$CADDYFILE" ]] || { echo "Caddyfile not found: $CADDYFILE" >&2; exit 1; }
if grep -Eq "^[[:space:]]*${DOMAIN//./\\.}([[:space:]]|\{)" "$CADDYFILE"; then
  echo "A ${DOMAIN} site block already exists in $CADDYFILE. Add reverse_proxy 127.0.0.1:${PORT} to that block manually." >&2
  exit 1
fi
FRAGMENT_DIR="$(dirname "$CADDYFILE")/apexnode-sites"
FRAGMENT="$FRAGMENT_DIR/${DOMAIN}.caddy"
install -d -m 0755 "$FRAGMENT_DIR"
cat >"$FRAGMENT" <<CADDY
${DOMAIN} {
    reverse_proxy 127.0.0.1:${PORT}
}
CADDY
IMPORT="import ${FRAGMENT_DIR}/*.caddy"
if ! grep -Fqx "$IMPORT" "$CADDYFILE"; then
  cp -a "$CADDYFILE" "${CADDYFILE}.apexnode-backup.$(date +%Y%m%d%H%M%S)"
  printf '\n%s\n' "$IMPORT" >>"$CADDYFILE"
fi
caddy validate --config "$CADDYFILE"
systemctl reload caddy
echo "Caddy proxy configured for https://${DOMAIN}; existing Caddy TLS automation is retained."
