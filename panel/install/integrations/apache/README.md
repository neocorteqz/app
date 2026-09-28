# Apache integration

The helper adds an HTTP reverse-proxy virtual host in Apache's include directory and enables `proxy`, `proxy_http`, and `headers` where `a2enmod` is available. Run as root:

```sh
sudo /opt/apexnode-integrations/apache/install.sh --domain panel.example.com --port 8443
```

The helper does not issue certificates. Configure TLS with your existing certificate tooling and proxy the TLS virtual host to the same loopback port. It validates Apache configuration before reload.
