# Caddy integration

The helper creates a separate `apexnode-sites/<domain>.caddy` fragment, adds a single import directive to the existing Caddyfile if needed, validates the configuration, and reloads Caddy. It refuses to duplicate an existing domain block.

```sh
sudo /opt/apexnode-integrations/caddy/install.sh --domain panel.example.com --port 8443
```

Caddy keeps managing HTTPS certificates for the domain. A timestamped Caddyfile backup is made before adding the import.
