#!/usr/bin/env bash
# ApexNode Panel — Linux installer (Debian/Ubuntu and RHEL-family systems)
#
# Run interactively or pass options for unattended installs. In panel mode the
# panel's web server is left untouched; ApexNode serves on loopback and installs
# a matching integration helper under the configured plugin directory.
#
#   sudo bash install.sh
#   sudo bash install.sh --port 8443 --web-server cpanel --domain apex.example.com --panel-user ACCOUNT
#   sudo bash install.sh --port 8443 --web-server caddy --domain apex.example.com
#   sudo bash install.sh --install-dir /srv/apex/app --data-dir /srv/apex/data
set -euo pipefail

APEX_DIR="/opt/apexnode"
STATE_DIR="/var/lib/apexnode"
PLUGIN_DIR="/opt/apexnode-integrations"
SOURCE_DIR=""
REPO_URL="${APEXNODE_REPO_URL:-https://github.com/neocorteqz/app.git}"
DB_NAME="apexnode"
DB_USER="apexnode"
DB_PASS="$(openssl rand -hex 16)"
DB_PROVISIONER_PASS="$(openssl rand -hex 24)"

PANEL_PORT=""
PORT_SET="no"
COEXIST="standalone"
COEXIST_SET="no"
WEB_SERVER="auto"
DOMAIN=""
PANEL_USER=""
INTERACTIVE="auto"
OPTIONS_PROVIDED="no"

usage() {
  cat <<'USAGE'
Usage: install.sh [options]
  --port PORT                 Panel port (default: 80 standalone, 8443 panel mode)
  --coexist MODE              standalone, apache, nginx, caddy, cpanel,
                              directadmin, plesk, or other
  --web-server SERVER         auto, standalone, apache, nginx, caddy, cpanel,
                              directadmin, plesk, or other
  --install-dir PATH          Application installation directory
  --data-dir PATH             Runtime data, server files, and backups directory
  --plugin-dir PATH           Web-panel integration scripts directory
  --source-dir PATH           Existing ApexNode panel source directory
  --repo URL                  Git repository used when source files are absent
  --domain DOMAIN             Domain to configure with the selected web panel
  --panel-user USER           cPanel/DirectAdmin account name (when required)
  --interactive               Prompt even when input is piped (uses /dev/tty)
  --non-interactive           Never prompt; use defaults and supplied options
  --help                      Show this help
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --port) PANEL_PORT="${2:?--port requires a value}"; PORT_SET="yes"; OPTIONS_PROVIDED="yes"; shift 2 ;;
    --coexist|--panel) COEXIST="${2:?--coexist requires a value}"; COEXIST_SET="yes"; OPTIONS_PROVIDED="yes"; shift 2 ;;
    --web-server) WEB_SERVER="${2:?--web-server requires a value}"; OPTIONS_PROVIDED="yes"; shift 2 ;;
    --install-dir) APEX_DIR="${2:?--install-dir requires a value}"; OPTIONS_PROVIDED="yes"; shift 2 ;;
    --data-dir) STATE_DIR="${2:?--data-dir requires a value}"; OPTIONS_PROVIDED="yes"; shift 2 ;;
    --plugin-dir) PLUGIN_DIR="${2:?--plugin-dir requires a value}"; OPTIONS_PROVIDED="yes"; shift 2 ;;
    --source-dir) SOURCE_DIR="${2:?--source-dir requires a value}"; OPTIONS_PROVIDED="yes"; shift 2 ;;
    --repo) REPO_URL="${2:?--repo requires a value}"; OPTIONS_PROVIDED="yes"; shift 2 ;;
    --domain) DOMAIN="${2:?--domain requires a value}"; OPTIONS_PROVIDED="yes"; shift 2 ;;
    --panel-user) PANEL_USER="${2:?--panel-user requires a value}"; OPTIONS_PROVIDED="yes"; shift 2 ;;
    --interactive) INTERACTIVE="yes"; shift ;;
    --non-interactive) INTERACTIVE="no"; shift ;;
    --help|-h) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

banner() { printf "\n\033[1;36m▸ %s\033[0m\n" "$1"; }
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root."; exit 1; }

detect_web_server() {
  if [[ -d /usr/local/cpanel ]]; then echo cpanel; return; fi
  if [[ -d /usr/local/directadmin ]]; then echo directadmin; return; fi
  if [[ -x /usr/local/psa/admin/sbin/httpdmng ]]; then echo plesk; return; fi
  if command -v systemctl >/dev/null && systemctl is-active --quiet caddy 2>/dev/null; then echo caddy; return; fi
  if command -v systemctl >/dev/null && systemctl is-active --quiet nginx 2>/dev/null; then echo nginx; return; fi
  if command -v systemctl >/dev/null && { systemctl is-active --quiet apache2 2>/dev/null || systemctl is-active --quiet httpd 2>/dev/null; }; then echo apache; return; fi
  echo standalone
}

DETECTED_WEB_SERVER="$(detect_web_server)"
if [[ "$WEB_SERVER" != "auto" ]]; then
  case "$WEB_SERVER" in standalone|apache|nginx|caddy|cpanel|directadmin|plesk|other) ;; *) echo "Invalid web server: $WEB_SERVER" >&2; exit 2 ;; esac
  if [[ "$COEXIST_SET" == "yes" && "$COEXIST" != "$WEB_SERVER" ]]; then
    echo "--coexist and --web-server select different integrations; provide only one or matching values." >&2
    exit 2
  fi
  COEXIST="$WEB_SERVER"
elif [[ "$COEXIST_SET" != "yes" ]]; then
  COEXIST="$DETECTED_WEB_SERVER"
fi

if [[ "$INTERACTIVE" == "auto" ]]; then
  if [[ "$OPTIONS_PROVIDED" == "yes" ]]; then INTERACTIVE="no"
  elif [[ -r /dev/tty ]]; then INTERACTIVE="yes"
  else INTERACTIVE="no"
  fi
fi

prompt_value() {
  local label="$1" current="$2" answer=""
  [[ "$INTERACTIVE" == "yes" && -r /dev/tty ]] || return 0
  printf '%s [%s]: ' "$label" "$current" >/dev/tty
  IFS= read -r answer </dev/tty || true
  REPLY="${answer:-$current}"
}

if [[ "$INTERACTIVE" == "yes" && -r /dev/tty ]]; then
  prompt_value "Web server / integration (standalone/apache/nginx/caddy/cpanel/directadmin/plesk/other)" "$COEXIST"; COEXIST="$REPLY"
  WEB_SERVER="$COEXIST"
  if [[ "$PORT_SET" == "no" ]]; then
    [[ "$COEXIST" == "standalone" ]] && PANEL_PORT="80" || PANEL_PORT="8443"
  fi
  prompt_value "Panel port" "$PANEL_PORT"; PANEL_PORT="$REPLY"
  prompt_value "Application install directory" "$APEX_DIR"; APEX_DIR="$REPLY"
  prompt_value "Data directory" "$STATE_DIR"; STATE_DIR="$REPLY"
  prompt_value "Integration scripts directory" "$PLUGIN_DIR"; PLUGIN_DIR="$REPLY"
  if [[ "$COEXIST" != "standalone" ]]; then
    prompt_value "Domain to configure (blank to install helper only)" "$DOMAIN"; DOMAIN="$REPLY"
    if [[ "$COEXIST" == "cpanel" || "$COEXIST" == "directadmin" ]]; then
      prompt_value "Panel account username (required by this integration)" "$PANEL_USER"; PANEL_USER="$REPLY"
    fi
  fi
fi

case "$COEXIST" in standalone|apache|cpanel|directadmin|plesk|nginx|caddy|other) ;; *) echo "Invalid install mode: $COEXIST" >&2; exit 2 ;; esac
if [[ -z "$PANEL_PORT" ]]; then
  [[ "$COEXIST" == "standalone" ]] && PANEL_PORT="80" || PANEL_PORT="8443"
fi
[[ "$PANEL_PORT" =~ ^[0-9]+$ ]] && (( PANEL_PORT >= 1 && PANEL_PORT <= 65535 )) || { echo "Invalid port: $PANEL_PORT" >&2; exit 2; }
for path in "$APEX_DIR" "$STATE_DIR" "$PLUGIN_DIR"; do
  [[ "$path" == /* && "$path" != "/" && "$path" =~ ^/[A-Za-z0-9/._-]+$ && ! "$path" =~ (^|/)\.\.(/|$) ]] || { echo "Install paths must be absolute, traversal-free, and contain only letters, numbers, / . _ -: $path" >&2; exit 2; }
done
[[ "$APEX_DIR" != "$STATE_DIR" && "$APEX_DIR" != "$STATE_DIR/"* && "$STATE_DIR" != "$APEX_DIR/"* ]] || { echo "Application and data directories must be separate and may not contain one another." >&2; exit 2; }
[[ "$PLUGIN_DIR" != "$APEX_DIR" && "$PLUGIN_DIR" != "$STATE_DIR" && "$PLUGIN_DIR" != "$APEX_DIR/"* && "$PLUGIN_DIR" != "$STATE_DIR/"* && "$APEX_DIR" != "$PLUGIN_DIR/"* && "$STATE_DIR" != "$PLUGIN_DIR/"* ]] || { echo "Integration scripts directory must be separate from the application and data directories." >&2; exit 2; }
if [[ -n "$DOMAIN" ]]; then
  [[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || { echo "Invalid domain: $DOMAIN" >&2; exit 2; }
fi
if [[ "$COEXIST" != "standalone" ]] && (( PANEL_PORT < 1024 )); then
  echo "Hosted-panel mode uses an unprivileged loopback listener; choose port 1024 or higher." >&2
  exit 2
fi
if [[ "$COEXIST" == "cpanel" || "$COEXIST" == "directadmin" ]]; then
  [[ -z "$DOMAIN" || "$PANEL_USER" =~ ^[A-Za-z0-9_-]+$ ]] || { echo "A valid --panel-user is required for $COEXIST." >&2; exit 2; }
fi
case "$COEXIST" in
  cpanel|directadmin) WEB_SERVER="apache" ;;
  plesk|nginx) WEB_SERVER="nginx" ;;
  standalone) WEB_SERVER="nginx (managed by ApexNode)" ;;
  apache|caddy|other) WEB_SERVER="$COEXIST" ;;
esac

TMP_SOURCE=""
cleanup() { [[ -z "$TMP_SOURCE" ]] || rm -rf "$TMP_SOURCE"; }
trap cleanup EXIT

is_panel_source() { [[ -f "$1/db/schema.sql" && -f "$1/public/router.php" && -d "$1/install" ]]; }

banner "ApexNode installer — port=${PANEL_PORT}, integration=${COEXIST}, web_server=${WEB_SERVER}"
banner "Application: ${APEX_DIR} | Data: ${STATE_DIR} | Integrations: ${PLUGIN_DIR}"

banner "Installing dependencies"
if command -v apt-get >/dev/null; then
  apt-get update -qq
  BASE_PACKAGES=(php-cli php-mysql php-mbstring php-curl php-xml php-zip mariadb-client redis-server default-jre-headless unzip curl git python3 python3-pip python3-venv)
  if [[ "$COEXIST" == "standalone" ]]; then BASE_PACKAGES+=(nginx php-fpm); fi
  if ! command -v mariadbd >/dev/null && ! command -v mysqld >/dev/null; then BASE_PACKAGES+=(mariadb-server); fi
  if ! command -v redis-server >/dev/null; then BASE_PACKAGES+=(redis-server); fi
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${BASE_PACKAGES[@]}"
elif command -v dnf >/dev/null; then
  BASE_PACKAGES=(php-cli php-mysqlnd php-mbstring php-curl php-xml php-zip mariadb redis java-17-openjdk-headless unzip curl git python3 python3-pip)
  if [[ "$COEXIST" == "standalone" ]]; then BASE_PACKAGES+=(nginx php-fpm); fi
  if ! command -v mariadbd >/dev/null && ! command -v mysqld >/dev/null; then BASE_PACKAGES+=(mariadb-server); fi
  dnf install -y "${BASE_PACKAGES[@]}"
elif command -v yum >/dev/null; then
  BASE_PACKAGES=(php-cli php-mysqlnd php-mbstring php-curl php-xml php-zip mariadb redis java-17-openjdk-headless unzip curl git python3 python3-pip)
  if [[ "$COEXIST" == "standalone" ]]; then BASE_PACKAGES+=(nginx php-fpm); fi
  if ! command -v mariadbd >/dev/null && ! command -v mysqld >/dev/null; then BASE_PACKAGES+=(mariadb-server)
  fi
  yum install -y "${BASE_PACKAGES[@]}"
else
  echo "Unsupported package manager. Supported systems use apt, dnf, or yum." >&2
  exit 1
fi

if [[ -z "$SOURCE_DIR" ]]; then
  SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd || true)"
  for candidate in "${SCRIPT_DIR}/.." "$PWD/panel" "$PWD"; do
    if is_panel_source "$candidate"; then SOURCE_DIR="$(cd "$candidate" && pwd)"; break; fi
  done
fi
if [[ -z "$SOURCE_DIR" ]]; then
  TMP_SOURCE="$(mktemp -d)"
  git clone --depth 1 "$REPO_URL" "$TMP_SOURCE/repo"
  if is_panel_source "$TMP_SOURCE/repo/panel"; then SOURCE_DIR="$TMP_SOURCE/repo/panel"
  elif is_panel_source "$TMP_SOURCE/repo"; then SOURCE_DIR="$TMP_SOURCE/repo"
  else echo "Could not find panel source files in $REPO_URL" >&2; exit 1; fi
fi
is_panel_source "$SOURCE_DIR" || { echo "Invalid panel source directory: $SOURCE_DIR" >&2; exit 1; }

banner "Provisioning MariaDB"
if systemctl is-active --quiet mariadb; then :
elif systemctl is-active --quiet mysql; then :
elif systemctl list-unit-files mariadb.service 2>/dev/null | grep -q '^mariadb.service'; then systemctl enable --now mariadb
elif systemctl list-unit-files mysql.service 2>/dev/null | grep -q '^mysql.service'; then systemctl enable --now mysql
else echo "No active MariaDB/MySQL service found." >&2; exit 1; fi
mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'127.0.0.1';
CREATE USER IF NOT EXISTS 'apexnode_provisioner'@'localhost' IDENTIFIED BY '${DB_PROVISIONER_PASS}';
ALTER USER 'apexnode_provisioner'@'localhost' IDENTIFIED BY '${DB_PROVISIONER_PASS}';
CREATE USER IF NOT EXISTS 'apexnode_provisioner'@'127.0.0.1' IDENTIFIED BY '${DB_PROVISIONER_PASS}';
ALTER USER 'apexnode_provisioner'@'127.0.0.1' IDENTIFIED BY '${DB_PROVISIONER_PASS}';
GRANT CREATE, CREATE USER ON *.* TO 'apexnode_provisioner'@'localhost';
GRANT CREATE, CREATE USER ON *.* TO 'apexnode_provisioner'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`apexnode_%\`.* TO 'apexnode_provisioner'@'localhost' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON \`apexnode_%\`.* TO 'apexnode_provisioner'@'127.0.0.1' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL

banner "Deploying ApexNode files to ${APEX_DIR}"
mkdir -p "${APEX_DIR}" "${STATE_DIR}/servers" "${STATE_DIR}/backups" "${PLUGIN_DIR}"
cp -a "${SOURCE_DIR}/." "${APEX_DIR}/"
for sql in schema.sql schema_v2.sql schema_v3.sql schema_v4.sql schema_v5.sql schema_v6.sql schema_v7.sql; do
  [[ -f "${APEX_DIR}/db/$sql" ]] && mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "${APEX_DIR}/db/$sql"
done

cat > "${APEX_DIR}/config/.env" <<ENV
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
DB_PROVISIONER_USER=apexnode_provisioner
DB_PROVISIONER_PASS=${DB_PROVISIONER_PASS}
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
PANEL_PORT=${PANEL_PORT}
APEX_STATE=${STATE_DIR}
PHP_SESSION_PATH=${STATE_DIR}/sessions
ENV
chmod 600 "${APEX_DIR}/config/.env"
WEB_USER="www-data"
WEB_GROUP="www-data"
PHP_BIN="$(command -v php)"
if [[ "$COEXIST" != "standalone" ]]; then
  WEB_USER="apexnode-web"
  WEB_GROUP="apexnode-web"
  getent group "$WEB_GROUP" >/dev/null || groupadd --system "$WEB_GROUP"
  getent passwd "$WEB_USER" >/dev/null || useradd --system --gid "$WEB_GROUP" --home-dir "$STATE_DIR" --shell /usr/sbin/nologin "$WEB_USER"
elif ! getent passwd "$WEB_USER" >/dev/null; then
  WEB_USER="apache"
  WEB_GROUP="apache"
fi
getent passwd "$WEB_USER" >/dev/null || { echo "No supported PHP web user found." >&2; exit 1; }
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP="$(id -gn "$WEB_USER")"
chown root:"$WEB_GROUP" "${APEX_DIR}/config/.env"
chmod 640 "${APEX_DIR}/config/.env"
chown -R "${WEB_USER}:${WEB_GROUP}" "$STATE_DIR"
mkdir -p "$STATE_DIR/sessions"
chown "${WEB_USER}:${WEB_GROUP}" "$STATE_DIR/sessions"
chmod 2770 "$STATE_DIR" "$STATE_DIR/servers" "$STATE_DIR/backups" "$STATE_DIR/sessions"

banner "Seeding initial data"
DB_HOST=127.0.0.1 DB_USER="${DB_USER}" DB_PASS="${DB_PASS}" DB_NAME="${DB_NAME}" \
  php "${APEX_DIR}/db/seed.php"
DB_HOST=127.0.0.1 DB_USER="${DB_USER}" DB_PASS="${DB_PASS}" DB_NAME="${DB_NAME}" \
  php "${APEX_DIR}/db/seed_v2.php"
DB_HOST=127.0.0.1 DB_USER="${DB_USER}" DB_PASS="${DB_PASS}" DB_NAME="${DB_NAME}" \
  php "${APEX_DIR}/db/seed_v3.php"
mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" -e \
  "UPDATE settings SET v='${PANEL_PORT}' WHERE k='panel_port'; UPDATE settings SET v='${COEXIST}' WHERE k='coexist_mode'; INSERT INTO settings (k,v) VALUES ('web_server','${WEB_SERVER}') ON DUPLICATE KEY UPDATE v=VALUES(v);"

if [[ "$COEXIST" == "standalone" ]]; then
  banner "Configuring PHP-FPM socket"
  PHP_SOCK=$(find /run/php /run/php-fpm -name '*.sock' -print -quit 2>/dev/null || true)
  [[ -n "$PHP_SOCK" ]] || { echo "No PHP-FPM socket found." >&2; exit 1; }
  PHP_FPM_UNIT=$(systemctl list-unit-files --type=service --no-legend 'php*-fpm.service' 2>/dev/null | awk 'NR==1 {print $1}')
  if [[ -z "$PHP_FPM_UNIT" ]]; then
    PHP_FPM_UNIT=$(systemctl list-unit-files --type=service --no-legend 'php-fpm.service' 2>/dev/null | awk 'NR==1 {print $1}')
  fi
  [[ -z "$PHP_FPM_UNIT" ]] || systemctl enable --now "$PHP_FPM_UNIT"
  FPM_CONF_DIR=$(find /etc -path '*/fpm/conf.d' -type d -print -quit 2>/dev/null || true)
  if [[ -n "$FPM_CONF_DIR" ]]; then
    printf 'session.save_path = "%s/sessions"\n' "$STATE_DIR" >"$FPM_CONF_DIR/99-apexnode.ini"
  fi
  banner "Standalone Nginx vhost on :${PANEL_PORT}"
  cat > /etc/nginx/conf.d/apexnode.conf <<NGX
server {
    listen ${PANEL_PORT} default_server;
    server_name _;
    root ${APEX_DIR}/public;
    index router.php;

    location / { try_files \$uri \$uri/ /router.php\$is_args\$args; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
      fastcgi_pass unix:${PHP_SOCK};
    }
    location ~ /\. { deny all; }
}
NGX
  nginx -t && systemctl reload nginx
else
  banner "Leaving the existing ${WEB_SERVER} web server untouched"
fi

if [[ "$COEXIST" != "standalone" ]]; then
  mkdir -p "${PLUGIN_DIR}/${COEXIST}"
  cp -a "${SOURCE_DIR}/install/integrations/${COEXIST}/." "${PLUGIN_DIR}/${COEXIST}/"
  chmod 750 "${PLUGIN_DIR}/${COEXIST}/install.sh"
  cat > "${PLUGIN_DIR}/${COEXIST}/apexnode.conf" <<CONF
PANEL_PORT=${PANEL_PORT}
INSTALL_DIR=${APEX_DIR}
DATA_DIR=${STATE_DIR}
CONF
  chmod 640 "${PLUGIN_DIR}/${COEXIST}/apexnode.conf"
  if [[ -n "$DOMAIN" ]]; then
    INTEGRATION_ARGS=(--domain "$DOMAIN" --port "$PANEL_PORT")
    [[ -z "$PANEL_USER" ]] || INTEGRATION_ARGS+=(--panel-user "$PANEL_USER")
    "${PLUGIN_DIR}/${COEXIST}/install.sh" "${INTEGRATION_ARGS[@]}"
  fi
fi

banner "Registering systemd services"
cat > /etc/systemd/system/apex-daemon.service <<UNIT
[Unit]
Description=ApexNode Game Server Daemon
After=network.target mariadb.service

[Service]
Type=simple
WorkingDirectory=${APEX_DIR}/daemon
EnvironmentFile=-${APEX_DIR}/config/.env
Environment=APEX_STATE=${STATE_DIR}
ExecStart=${APEX_DIR}/.venv/bin/python -m uvicorn daemon:app --host 127.0.0.1 --port 8001
Restart=always

[Install]
WantedBy=multi-user.target
UNIT

if [[ "$COEXIST" != "standalone" ]]; then
  cat > /etc/systemd/system/apex-panel.service <<UNIT
[Unit]
Description=ApexNode Panel private web listener
After=network.target mariadb.service

[Service]
Type=simple
WorkingDirectory=${APEX_DIR}
EnvironmentFile=${APEX_DIR}/config/.env
ExecStart=${PHP_BIN} -d session.save_path=${STATE_DIR}/sessions -S 127.0.0.1:${PANEL_PORT} -t ${APEX_DIR}/public ${APEX_DIR}/public/router.php
Restart=always
User=${WEB_USER}
Group=${WEB_GROUP}

[Install]
WantedBy=multi-user.target
UNIT
fi

cat > /etc/systemd/system/apex-backup.service <<UNIT
[Unit]
Description=ApexNode Backup Runner
After=mariadb.service

[Service]
Type=simple
ExecStart=/usr/bin/php ${APEX_DIR}/scripts/backup_runner.php
Restart=always

[Install]
WantedBy=multi-user.target
UNIT

cat > /etc/systemd/system/apexnode-bot.service <<UNIT
[Unit]
Description=ApexNode Discord Bot
After=network.target mariadb.service

[Service]
Type=simple
WorkingDirectory=${APEX_DIR}/discord-bot
EnvironmentFile=-${APEX_DIR}/config/.env
ExecStart=${APEX_DIR}/.venv/bin/python ${APEX_DIR}/discord-bot/bot.py
Restart=always

[Install]
WantedBy=multi-user.target
UNIT

python3 -m venv "${APEX_DIR}/.venv"
"${APEX_DIR}/.venv/bin/pip" install --quiet -r "${APEX_DIR}/daemon/requirements.txt"
"${APEX_DIR}/.venv/bin/pip" install --quiet -r "${APEX_DIR}/discord-bot/requirements.txt"

if systemctl list-unit-files 'php*-fpm.service' 2>/dev/null | grep -q fpm; then
  systemctl reload 'php*-fpm.service' 2>/dev/null || true
fi

systemctl daemon-reload
if [[ "$COEXIST" == "standalone" ]]; then
  systemctl enable --now apex-daemon apex-backup
else
  systemctl enable --now apex-panel apex-daemon apex-backup
fi
systemctl enable apexnode-bot >/dev/null 2>&1 || true
if systemctl list-unit-files redis-server.service 2>/dev/null | grep -q '^redis-server.service'; then
  systemctl enable --now redis-server
else
  systemctl enable --now redis
fi

banner "Done!"
IP=$(hostname -I | awk '{print $1}')
echo
if [[ "$COEXIST" == "standalone" ]]; then
  echo "  🚀  ApexNode Panel is up on:  http://${IP}:${PANEL_PORT}/"
else
  echo "  🚀  ApexNode Panel is running on 127.0.0.1:${PANEL_PORT}"
  echo "      Integration helper: ${PLUGIN_DIR}/${COEXIST}/install.sh"
  if [[ -z "$DOMAIN" ]]; then
    case "$COEXIST" in
      cpanel|directadmin) echo "      Run it with --domain YOUR_DOMAIN --panel-user YOUR_ACCOUNT to configure the web panel." ;;
      *) echo "      Run it with --domain YOUR_DOMAIN to configure the web panel." ;;
    esac
  fi
fi
echo "      Login:   admin / admin123   (CHANGE IMMEDIATELY)"
echo "      DB user: ${DB_USER}"
echo "      DB pass: ${DB_PASS}"
