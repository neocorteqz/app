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
[[ "$PORT" =~ ^[0-9]+$ ]] && (( PORT > 0 && PORT < 65536 )) || { echo "Invalid port." >&2; exit 2; }
if [[ -n "$DOMAIN" ]]; then [[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || { echo "Invalid domain." >&2; exit 2; }; fi
cat <<INSTRUCTIONS
ApexNode is running on 127.0.0.1:${PORT}.
Configure your existing web server to reverse proxy ${DOMAIN:-your-domain} to http://127.0.0.1:${PORT}.
Forward the original Host header and X-Forwarded-Proto (http or https).
Do not expose the private listener directly to the public internet.
INSTRUCTIONS
