# Browser installation

Before upload, prepare PHP 8.0+ with PDO MySQL, cURL, sessions and JSON; MySQL 8.0+ or MariaDB 10.5+; HTTPS; and Apache rewrite support with `.htaccess` enabled, or equivalent Nginx/Caddy routing and private-file protection. No Composer, Node.js, npm or command-line access is required for the web panel.

1. Create an empty database and a dedicated user in your hosting dashboard. Grant database-level SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX and REFERENCES.
2. Upload the contents of `panel/`, including `.htaccess`, to the chosen directory with FTP or your file manager. A directory named `games` works at `https://example.com/games/`. If supported, use `panel/public/` as the document root.
3. Allow PHP to write `config/` and create `storage/`. Use account ownership or restricted hosting permissions, not world-writable permissions.
4. Open `/games/install.php`, complete the dependency check, enter database details, and choose your administrator credentials.
5. Sign in, then delete both root and `public/` installer copies through your file manager. Confirm that requests to `config/`, `app/`, `db/`, `storage/` and dotfiles are denied by the web server.

The installer locks after success and creates no demo users or servers. A failed attempt can be retried with the same credentials and database; the wizard is not a migration tool for an existing installation.

## Existing installations

Back up the database, server files, and local configuration before replacing files. Preserve `config/installed.php` for a browser installation, or `config/.env` for the full-host installer, plus `storage/` and the daemon's data directory. Do not rerun the browser wizard against an existing database. Compare bundled migrations against your installed schema before applying changes; this release has no automatic upgrade wizard. Restart the daemon and bot after updating their Python code. Check sign-in, server access, a failed daemon command, public join pages and backup restore on a test copy first.

## Game daemon and optional features

Game operations require a same-host daemon on `127.0.0.1:8001`, Python 3.10+, dependencies from `panel/daemon/requirements.txt`, and shared writable server storage. Configure database host, port, name, username and password consistently with the panel, and `APEX_STATE` for the data directory. Compatible Java is required for Java servers. Hosting providers can set up these services when you have no shell access. The browser wizard does not install system services or game binaries.

Scheduled backups require the backup runner, tar/gzip, and PHP `exec`; S3 additionally needs AWS CLI. See the README runtime table before choosing a loader: several loaders are unsupported and non-Java games currently use a simulator.

## Discord

Install `panel/discord-bot/requirements.txt` in the bot environment and configure the same database credentials, including `DB_PORT`. Set the bot token and guild ID through the panel or `DISCORD_BOT_TOKEN` and `DISCORD_GUILD_ID`. Keep the bot on the daemon host. Commands require the configured guild and Manage Server permission. Empty token input preserves the configured token; it is not rendered back in the settings page. Restart the bot after configuration changes. Keep its token private.

## Troubleshooting without SSH

Use your hosting dashboard to verify PHP extensions, database permissions, HTTPS and file ownership. A 404 under a subdirectory usually means rewrite/front-controller rules are absent. A daemon error requires your provider to check the local service; it should not be solved by exposing port 8001 publicly. The panel now preserves server status when a daemon command fails.
