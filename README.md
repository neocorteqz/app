# ApexNode Game Server Panel

**Beta version 0.0.1**

ApexNode is a self-hosted Linux control panel for deploying and operating game servers. It currently supports Minecraft Java and Bedrock, Counter-Strike 2, and Rust workflows. The panel combines a PHP web application, a Python daemon, MariaDB, Redis, and systemd services.

> **Beta disclaimer:** ApexNode is beta software. Features, database schemas, and installer behavior can change between releases. Do not use it as the sole control plane for valuable or production game data. Keep independent backups, test restores, restrict administrative access, and report problems before relying on the panel.

## Feature Set

- Create and manage game servers, nodes, resource limits, ports, eggs, and mod loaders.
- Start, stop, restart, inspect status, and use a live server console.
- Install Modrinth and CurseForge modpacks with background job progress and cancellation.
- Verify downloaded Paper JARs against the SHA256 checksum supplied by PaperMC.
- Pin Minecraft Java servers to a selected release version.
- Give friends a public server join page with a share link, QR code, connect address, and live status/player count.
- Manage server files and backups, including scheduled backups, retention, and restore confirmation.
- Import and review Pterodactyl eggs, including updates to previously imported eggs.
- Create and rotate database credentials, manage server-level access, and review activity and operational alerts.
- Install behind Apache, Nginx, Caddy, cPanel, DirectAdmin, or Plesk, or use standalone Nginx on a bare server.
- Install the panel as a PWA and optionally configure the Discord integration.

Some game runtimes and integrations are still limited or experimental. Review the beta disclaimer and test the exact workflow you plan to use.

## Requirements

### Dependencies to have ready before browser installation

The upload installer assumes these dependencies are already installed. It checks requirements and configures the application; it does not install packages or run commands.

| Dependency | Required setup |
| --- | --- |
| PHP | PHP 8.0 or newer with PDO, `pdo_mysql`, cURL, sessions, JSON and password hashing. PHP must be able to write the panel's `config/` directory and create `storage/`. |
| Database | MySQL 8.0+ or MariaDB 10.5+, an empty database, and a database user with SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX and REFERENCES privileges on that database. |
| Web server | Apache 2.4 with `mod_rewrite`, directory overrides enabled for `.htaccess`, and PHP configured; or an existing Nginx/Caddy configuration with front-controller routing and private-file protection. |
| HTTPS | A working SSL certificate for the panel URL before entering credentials. |
| Browser | A current browser with JavaScript enabled. |
| Optional acceleration | PHP OPcache, enabled by your hosting provider. The panel also reuses configuration/user/theme reads per request, reuses PDO connections, and caches static interface assets in the browser. |

For **game-server operations**, also have Python 3 with the packages in `panel/daemon/requirements.txt`, the ApexNode daemon running on the same machine, compatible game runtimes (Java for Minecraft Java, etc.), writable shared server storage, and the backup runner for scheduled backups. Backup creation/restore also needs `tar`/gzip and PHP `exec` enabled; S3 backups additionally need the AWS CLI. Database-user provisioning is optional and needs separately configured `DB_PROVISIONER_USER` / `DB_PROVISIONER_PASS` credentials with database/user creation privileges. Redis and systemd are used by the legacy full-host installer but are not prerequisites for the PHP panel's upload wizard. Node.js/npm and Composer are not required to install the PHP panel.

### Supported operating systems (legacy full-host installer)

- Debian 12 or newer
- Ubuntu 22.04 LTS or newer
- RHEL-family systems using `dnf` or `yum` (for example, Rocky Linux or AlmaLinux), provided enabled repositories supply the required PHP, MariaDB, Redis, Java, and Python packages

The installer requires `apt`, `dnf`, or `yum`, a working MariaDB/MySQL root administrative login for local provisioning, `systemd`, and internet access to download packages and application dependencies. Hosted-panel installs also need the existing panel's administration tools and a domain already pointed at the server.

### Hardware sizing

Panel requirements are modest, but game servers are usually the resource bottleneck. These are starting recommendations, not guarantees; Minecraft modpacks and player counts can require much more.

| Host type | CPU | RAM | Storage | Suitable use |
| --- | ---: | ---: | ---: | --- |
| Small test VPS | 2 vCPU | 4 GB | 30 GB SSD | Panel plus one lightweight or mostly idle server |
| Small production VPS | 4 vCPU | 8 GB | 80 GB SSD/NVMe | A few small servers; monitor memory and disk |
| Modded Minecraft host | 6-8 modern cores | 16-32 GB | 150+ GB NVMe | Modpacks; allocate RAM per pack and leave memory for Linux and services |
| Dedicated/multi-server host | 8+ modern cores | 32+ GB | 500 GB+ NVMe | Multiple busy servers; size from measured CPU, RAM, I/O, and player load |

Prefer a recent high-clock CPU for Minecraft, SSD/NVMe storage, and enough memory to leave at least 1-2 GB available to the operating system and services. Do not allocate all system RAM to game servers. Use a separate data volume for large server files and backups when possible.

## Installation

For an existing host with the dependencies above, use the browser upload method first. The later SSH instructions are an optional full-host provisioning alternative.

### Upload and install through your browser (no SSH)

For the **web panel**, use existing PHP hosting with PHP 8.0+, PDO MySQL, cURL, HTTPS, and MySQL 8.0+ or MariaDB 10.5+. Apache must allow `.htaccess` with rewrite support.

1. Download the repository ZIP and upload **the contents of `panel/`**, including `.htaccess`, into your chosen web directory using your hosting file manager or FTP. For example, `public_html/games/` serves the panel at `https://example.com/games/`.
2. In your hosting dashboard, create an **empty database** and a database user with privileges on that database. No database-root account is needed.
3. Make `config/` and the uploaded panel directory writable by PHP using your hosting file manager. Avoid world-writable permissions.
4. Open `https://example.com/games/install.php`. The wizard checks requirements, asks for the database details and your own administrator credentials, applies the bundled schema, and creates local storage. No Composer, npm, shell commands, Redis, or dependency downloads are required for panel installation.
5. Follow the sign-in link. Installation locks automatically. Delete the root and `public/` copies of `install.php` with your file manager when finished.

A dedicated domain can use the same upload at its document root. If your provider lets you set the document root, pointing it to `panel/public/` keeps the application code outside the served directory. Nginx hosts must configure an index.php front controller and deny access to private directories/dotfiles through their hosting dashboard or provider; Nginx does not read `.htaccess`. Do not expose the full panel directory on a host that ignores these protection rules.

The wizard creates only your chosen administrator and bundled templates/loaders. It does not create demo users or sample servers. Failed installation can be retried with the same database and administrator details; existing databases are refused on the first attempt. Configuration is stored in a protected PHP file and is not printed back to the browser. Use a fresh empty database for a new installation; this wizard is not an upgrade tool.

**Game hosting is separate:** PHP file uploads cannot install system packages, Python services, Java, or game processes. The current daemon uses a local loopback connection, so full game operations require a daemon on the same machine and access from PHP to its server files. Ordinary shared hosting can host the panel UI, but will usually need provider assistance or a VPS for the daemon. Scheduled backups also require the existing backup runner. The root installer below remains available for full host provisioning.

### Before you start (full host installation)

1. Choose a clean Linux server or confirm which web server/control panel already owns ports 80 and 443.
2. Point the domain's DNS `A`/`AAAA` record at the server if you plan to use a domain.
3. Allow the chosen panel port and game-server ports through both the cloud firewall and host firewall. Do not expose the private loopback panel port in hosted-panel mode.
4. Log in over SSH as a sudo-capable administrator. The installer needs root access; do not run a script you do not trust.
5. Back up existing web-server configuration before integrating with a hosted server.

### Interactive install

On a fresh bare server, download or clone the repository and run the installer as root:

```bash
git clone https://github.com/neocorteqz/app.git
cd app/panel
sudo bash install/install.sh
```

The wizard detects cPanel, DirectAdmin, Plesk, or active Apache/Nginx/Caddy services when possible. Review the detected choice carefully, then select the web server/integration, panel port, application directory, data directory, integration-script directory, and optional domain/account name.

### One-line install

You can run the installer directly from GitHub. This downloads and executes code as root, so inspect the script first if you need to audit it.

Standalone Nginx on port 8080 with custom locations:

```bash
curl -fsSL https://raw.githubusercontent.com/neocorteqz/app/main/panel/install/install.sh \
	| sudo bash -s -- --web-server standalone --port 8080 \
			--install-dir /opt/apexnode --data-dir /srv/apexnode-data \
			--plugin-dir /opt/apexnode-integrations
```

Existing Apache with a domain:

```bash
curl -fsSL https://raw.githubusercontent.com/neocorteqz/app/main/panel/install/install.sh \
	| sudo bash -s -- --web-server apache --port 8443 \
			--domain panel.example.com --install-dir /opt/apexnode \
			--data-dir /srv/apexnode-data
```

Caddy with automatic HTTPS:

```bash
curl -fsSL https://raw.githubusercontent.com/neocorteqz/app/main/panel/install/install.sh \
	| sudo bash -s -- --web-server caddy --port 8443 \
			--domain panel.example.com --data-dir /srv/apexnode-data
```

The installer accepts `--web-server auto|standalone|apache|nginx|caddy|cpanel|directadmin|plesk|other`. `auto` detects the host; when detection is ambiguous, choose explicitly. The legacy `--coexist` option remains available. Use `--help` to see all options. Supplying options makes the installer non-interactive by default; add `--interactive` to be prompted too, or `--non-interactive` to suppress prompts.

For cPanel and DirectAdmin, supply `--panel-user ACCOUNT` when configuring a domain. Plesk, Nginx, Apache, and Caddy helpers preserve the existing server configuration where possible and manage ApexNode-specific snippets. Without a domain, the installer installs the private loopback listener and copies the helper; run that helper later with the domain and port.

### What the installer configures

- Application files at the selected install directory.
- Server files, backups, and PHP sessions under the selected data directory.
- A MariaDB database and application/provisioning accounts.
- Redis, a Python virtual environment, daemon dependencies, and systemd services.
- A private PHP listener on `127.0.0.1:<port>` for hosted-panel modes.
- Public Nginx plus PHP-FPM only for standalone mode. Existing web servers are not intentionally replaced.
- An integration helper under the selected integration-script directory for Apache, Nginx, Caddy, cPanel, DirectAdmin, Plesk, or manual proxy setup.

Review installer output and keep generated database credentials private. For the legacy full-host installer, the initial panel login is `admin` / `admin123`; sign in immediately and change the password before exposing the panel to other users.

### Web server integrations

| Choice | Behavior |
| --- | --- |
| `standalone` | Installs/uses Nginx and PHP-FPM for a bare host. Select a free public port. |
| `apache` | Writes an ApexNode-specific Apache proxy vhost, validates config, and reloads Apache. Configure TLS using existing certificate tooling. |
| `nginx` | Writes a dedicated Nginx reverse-proxy config and validates/reloads Nginx. |
| `caddy` | Adds a separate Caddy site fragment, validates/reloads Caddy, and leaves automatic HTTPS to Caddy. |
| `cpanel` | Uses cPanel's Apache include tools for the selected account/domain. |
| `directadmin` | Adds a per-domain DirectAdmin custom HTTPD include and runs CustomBuild. |
| `plesk` | Adds a per-domain Plesk Nginx proxy include and asks Plesk to reconfigure the domain. |
| `other` | Does not edit web-server configuration; prints the loopback proxy target and required headers. |

Confirm HTTPS/certificate behavior for your specific domain and hosting-panel version before sharing access.

## First Login and Basic Use

1. Open the URL printed by the installer. Standalone typically uses `http://SERVER_IP:PORT`; hosted-panel mode uses the configured domain.
2. For browser installation, sign in with the administrator details you chose. For the legacy full-host installer, sign in with `admin` / `admin123` and immediately set a unique password.
3. Review nodes, games, loaders, eggs, and resource limits.
4. Deploy a server and select a Minecraft Java release where applicable.
5. Start the server and watch job status and console. First launches can take time while JARs or packs download.
6. Configure backup schedules and retention. Create a backup and test a restore before relying on it.
7. Share the public join page only for servers intended to be shared. Anyone with its unguessable link can view the server's listed status and connect address.

## Recommended Host Tweaks

- **1-2 vCPU / 2-4 GB RAM:** learning or one lightweight server. Avoid large modpacks; keep view distance and player counts low.
- **2-4 vCPU / 8 GB RAM:** a few small vanilla/Paper servers. Start with 2-4 GB for the main Java server and monitor before increasing it.
- **4-8+ vCPU / 16-32 GB RAM:** modpacks or several concurrent servers. Allocate RAM per workload; a larger Java heap does not automatically improve performance.
- **Dedicated hosts:** use NVMe for modpacks/backups, monitor thermal/CPU limits, and watch storage growth.
- **Operating system:** use a supported, updated release; keep security updates enabled. Do not disable the firewall to fix a port issue; allow only the panel/proxy and needed game ports.
- **Web server:** keep the existing Apache/Nginx/Caddy/control panel as the public TLS endpoint. Hosted-mode ApexNode listeners should remain on loopback.
- **Backups:** use a separate volume or trusted remote object store, set retention according to capacity, and test restores.

## Troubleshooting

### Port invalid or already in use

Use a numeric port from 1-65535. Hosted-panel mode requires an unprivileged loopback port (1024 or higher). Check listeners with `sudo ss -ltnp`, choose another free port, and update the matching proxy helper. Do not expose the loopback port publicly.

### Site shows 502/503 or proxy cannot connect

Check `sudo systemctl status apex-panel apex-daemon`, confirm the listener is on `127.0.0.1:<port>` with `sudo ss -ltnp`, and inspect `sudo journalctl -u apex-panel -n 100 --no-pager`. Validate the web server with `sudo nginx -t`, `sudo apachectl configtest`, or `sudo caddy validate --config /etc/caddy/Caddyfile`. Correct the upstream port before reloading.

### Helper cannot find the domain/account

Confirm DNS points to this host, the domain already exists in the hosting panel, and the cPanel/DirectAdmin account name is correct. Run the helper with its required `--domain`, `--port`, and where needed `--panel-user` arguments. Plesk requires its domain configuration directory and `httpdmng` utility.

### Database connection or migration error

Check `sudo systemctl status mariadb` (or `mysql`), credentials in `<install-dir>/config/.env`, and `sudo journalctl -u apex-panel -n 100 --no-pager`. Never post `.env` contents in public bug reports. Back up the database before upgrades or re-running installation.

### Paper download or checksum verification fails

Check outbound HTTPS/DNS and disk space. A Paper JAR is accepted only if its SHA256 matches PaperMC's metadata; a mismatch aborts startup rather than launching an unverified file. Review server logs, remove a partial/corrupt `server.jar` only while stopped, and retry after connectivity is restored.

### Java server is marked crashed

Look for `loader bootstrap failed` in server logs. Verify the selected Minecraft version is supported by the loader and Java is available. Paper downloads require outbound access to PaperMC.

### Permission denied for data, sessions, or backups

Use the data directory selected at installation. Check ownership for the web user and data directory, and confirm `.env` is readable by the panel service but not publicly served. Do not use world-writable permissions such as `chmod -R 777`.

### Out of disk space or memory

Run `df -h` and `free -h`, review backup retention and old downloads, and reduce concurrent servers or heap allocations. Leave memory and disk headroom for MariaDB, Redis, PHP, and Linux.

### Useful service commands

```bash
sudo systemctl status apex-panel apex-daemon apex-backup
sudo journalctl -u apex-panel -u apex-daemon -u apex-backup -n 100 --no-pager
sudo systemctl restart apex-panel apex-daemon
sudo ss -ltnp
df -h
free -h
```

## Security and Beta Notes

- Change the default administrator password immediately.
- Keep the panel behind HTTPS when accessible outside a trusted network.
- Keep database/provisioning credentials private and redact them from reports.
- Limit public panel and game ports with a firewall. Hosted integration listeners should remain on loopback.
- Treat public join links as shareable access to server name, status, player count, and connect address. Until a UI rotation action is available, revoke a link by changing its `share_token` in the database.
- Back up the database and server data independently before upgrades/restores.
- This is **Beta 0.0.1**. APIs, installer integrations, and game workflows may change. Use at your own risk and keep recoverable backups.

## Bugs, Suggestions, and Contributions

Open a GitHub issue for bugs, install problems, or suggestions: [github.com/neocorteqz/app/issues](https://github.com/neocorteqz/app/issues). Include OS/version, chosen web-server integration, sanitized installer output, and relevant service logs. Never include passwords, API keys, cookies, or `.env` contents.

Repository: [github.com/neocorteqz/app](https://github.com/neocorteqz/app)

## PHP application structure and caching

`panel/app/includes.php` is the shared web bootstrap for sessions, common helpers, authentication and controller loading. `panel/app/db.php` owns PDO connections and query helpers. Configuration, signed-in user details and theme data are reused within each request; authorization and live game state are refreshed on new requests. Static assets use browser revalidation, and the PWA cache changes when the CSS/JavaScript changes. Live status and console polling pause in hidden tabs and avoid overlapping requests. File browsing writes the server's work directory only when it changes.
