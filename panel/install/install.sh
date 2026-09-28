#!/usr/bin/env bash
# ApexNode Panel — Linux installer (Ubuntu 22.04+ / Debian 12+)
#
# Coexistence support: pass --port and --coexist to install alongside
# DirectAdmin / cPanel / Plesk without touching their ports.
#
#   sudo bash install.sh                                # standalone on :80
#   sudo bash install.sh --port 8443                    # bind to :8443, no vhost changes
#   sudo bash install.sh --port 8443 --coexist cpanel   # cPanel proxy-subdomain snippet emitted
#   sudo bash install.sh --port 8443 --coexist directadmin
#   sudo bash install.sh --port 8443 --coexist plesk
#   sudo bash install.sh --port 8443 --coexist nginx    # generic Nginx reverse-proxy snippet
set -euo pipefail

APEX_DIR="/opt/apexnode"
DB_NAME="apexnode"
DB_USER="apexnode"
DB_PASS="$(openssl rand -hex 16)"
DB_PROVISIONER_PASS="$(openssl rand -hex 24)"

PANEL_PORT="80"
COEXIST="standalone"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --port)     PANEL_PORT="$2"; shift 2 ;;
    --coexist)  COEXIST="$2";    shift 2 ;;
    *) echo "unknown arg: $1"; exit 1 ;;
  esac
done

banner() { printf "\n\033[1;36m▸ %s\033[0m\n" "$1"; }
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root."; exit 1; }

banner "ApexNode installer — port=${PANEL_PORT}, coexist=${COEXIST}"

banner "Installing dependencies"
apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
  nginx php-cli php-fpm php-mysql php-mbstring php-curl php-xml php-zip \
  mariadb-server redis-server default-jre-headless unzip curl git python3 python3-pip

banner "Provisioning MariaDB"
systemctl enable --now mariadb
mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
CREATE USER IF NOT EXISTS 'apexnode_provisioner'@'localhost' IDENTIFIED BY '${DB_PROVISIONER_PASS}';
GRANT CREATE, CREATE USER ON *.* TO 'apexnode_provisioner'@'localhost';
GRANT ALL PRIVILEGES ON \`apexnode_%\`.* TO 'apexnode_provisioner'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL

banner "Deploying ApexNode files to ${APEX_DIR}"
mkdir -p "${APEX_DIR}"
cp -r ./* "${APEX_DIR}/"
for sql in schema.sql schema_v2.sql schema_v3.sql schema_v4.sql schema_v5.sql schema_v6.sql; do
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
ENV
chmod 600 "${APEX_DIR}/config/.env"

banner "Seeding initial data"
DB_HOST=127.0.0.1 DB_USER="${DB_USER}" DB_PASS="${DB_PASS}" DB_NAME="${DB_NAME}" \
  php "${APEX_DIR}/db/seed.php"
DB_HOST=127.0.0.1 DB_USER="${DB_USER}" DB_PASS="${DB_PASS}" DB_NAME="${DB_NAME}" \
  php "${APEX_DIR}/db/seed_v2.php"
DB_HOST=127.0.0.1 DB_USER="${DB_USER}" DB_PASS="${DB_PASS}" DB_NAME="${DB_NAME}" \
  php "${APEX_DIR}/db/seed_v3.php"
mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" -e \
  "UPDATE settings SET v='${PANEL_PORT}' WHERE k='panel_port'; UPDATE settings SET v='${COEXIST}' WHERE k='coexist_mode';"

banner "Configuring PHP-FPM socket"
PHP_SOCK=$(ls /run/php/php*-fpm.sock | head -1)

if [[ "$COEXIST" == "standalone" ]]; then
  banner "Standalone Nginx vhost on :${PANEL_PORT}"
  cat > /etc/nginx/sites-available/apexnode <<NGX
server {
    listen ${PANEL_PORT} default_server;
    server_name _;
    root ${APEX_DIR}/public;
    index router.php;

    location / { try_files \$uri \$uri/ /router.php\$is_args\$args; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
    }
    location ~ /\. { deny all; }
}
NGX
  ln -sf /etc/nginx/sites-available/apexnode /etc/nginx/sites-enabled/apexnode
  [[ "$PANEL_PORT" == "80" ]] && rm -f /etc/nginx/sites-enabled/default
  nginx -t && systemctl reload nginx
else
  banner "Coexist mode '$COEXIST' — starting Apex on 127.0.0.1:${PANEL_PORT} only"
  # Nginx bound to loopback so the existing control-panel handles the public port
  cat > /etc/nginx/sites-available/apexnode <<NGX
server {
    listen 127.0.0.1:${PANEL_PORT};
    server_name _;
    root ${APEX_DIR}/public;
    index router.php;

    location / { try_files \$uri \$uri/ /router.php\$is_args\$args; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
    }
    location ~ /\. { deny all; }
}
NGX
  ln -sf /etc/nginx/sites-available/apexnode /etc/nginx/sites-enabled/apexnode
  nginx -t && systemctl reload nginx
fi

# Emit the exact snippet the operator should paste into their control panel
case "$COEXIST" in
  cpanel)
    banner "cPanel: reverse-proxy from apex.yourdomain.tld → localhost:${PANEL_PORT}"
    cat <<CPANEL
Add this to WHM → Home » Service Configuration » Apache Configuration » Include
Editor » Pre VirtualHost (2.4):

<VirtualHost *:80>
    ServerName apex.yourdomain.tld
    ProxyPreserveHost On
    ProxyPass / http://127.0.0.1:${PANEL_PORT}/
    ProxyPassReverse / http://127.0.0.1:${PANEL_PORT}/
</VirtualHost>

Then: whmapi1 configureservice service=httpd enabled=1 && systemctl reload httpd
CPANEL
    ;;
  plesk)
    banner "Plesk: add via Domains → apex.yourdomain.tld → Apache & nginx Settings"
    cat <<PLESK
Additional nginx directives (paste as-is):

  location / {
      proxy_pass http://127.0.0.1:${PANEL_PORT};
      proxy_set_header Host \$host;
      proxy_set_header X-Real-IP \$remote_addr;
      proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
  }

Uncheck "Proxy mode" so Plesk's nginx serves ApexNode directly.
PLESK
    ;;
  directadmin)
    banner "DirectAdmin: use CustomBuild custom_httpd for apex subdomain"
    cat <<DA
1. In DirectAdmin, create subdomain apex.yourdomain.tld
2. Add to /usr/local/directadmin/data/users/USER/domains/DOMAIN.custom_httpd.conf:

|?PROXY=http://127.0.0.1:${PANEL_PORT}|
|?VHOST=apex.yourdomain.tld|

3. cd /usr/local/directadmin/custombuild && ./build rewrite_confs
DA
    ;;
  nginx)
    banner "Generic Nginx reverse proxy snippet"
    cat <<GEN
Add to /etc/nginx/conf.d/apex.conf on your web-panel host:

server {
    listen 80;
    server_name apex.yourdomain.tld;
    location / {
        proxy_pass http://127.0.0.1:${PANEL_PORT};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    }
}
GEN
    ;;
esac

banner "Registering systemd services"
cat > /etc/systemd/system/apex-daemon.service <<UNIT
[Unit]
Description=ApexNode Game Server Daemon
After=network.target mariadb.service

[Service]
Type=simple
WorkingDirectory=${APEX_DIR}/daemon
EnvironmentFile=-${APEX_DIR}/config/.env
ExecStart=/usr/bin/python3 -m uvicorn daemon:app --host 127.0.0.1 --port 8001
Restart=always

[Install]
WantedBy=multi-user.target
UNIT

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
ExecStart=/usr/bin/python3 ${APEX_DIR}/discord-bot/bot.py
Restart=always

[Install]
WantedBy=multi-user.target
UNIT

pip3 install --quiet -r "${APEX_DIR}/daemon/requirements.txt"
pip3 install --quiet -r "${APEX_DIR}/discord-bot/requirements.txt"

systemctl reload php*-fpm 2>/dev/null || true

systemctl daemon-reload
systemctl enable --now apex-daemon apex-backup
systemctl enable apexnode-bot >/dev/null 2>&1 || true
systemctl enable --now redis-server

banner "Done!"
IP=$(hostname -I | awk '{print $1}')
echo
if [[ "$COEXIST" == "standalone" ]]; then
  echo "  🚀  ApexNode Panel is up on:  http://${IP}:${PANEL_PORT}/"
else
  echo "  🚀  ApexNode Panel is running on 127.0.0.1:${PANEL_PORT}"
  echo "      Wire up your ${COEXIST} vhost using the snippet above."
fi
echo "      Login:   admin / admin123   (CHANGE IMMEDIATELY)"
echo "      DB user: ${DB_USER}"
echo "      DB pass: ${DB_PASS}"
